<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui;

use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Events\EventLoop;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Events\KeyBindings;
use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\TabContract;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;

/**
 * Main TUI application orchestrator
 */
final class TuiApplication
{
    private Display $display;

    private EventLoop $eventLoop;

    private TuiState $state;

    /** @var array<TabContract> */
    private array $tabs = [];

    /** @var array<string, callable> */
    private array $actionQueue = [];

    private int $lastRenderTime = 0;

    private const MIN_RENDER_INTERVAL_MS = 50; // 20 FPS max

    public function __construct()
    {
        $this->display = DisplayBuilder::default()->build();
        $this->eventLoop = new EventLoop;
        $this->state = new TuiState;
    }

    /**
     * Register a tab
     */
    public function registerTab(TabContract $tab): void
    {
        $this->tabs[] = $tab;
    }

    /**
     * Initialize the TUI
     */
    public function initialize(): void
    {
        $this->display->clear();
        $this->eventLoop->initialize();

        // Activate the first tab
        if (! empty($this->tabs)) {
            $this->tabs[0]->onActivate($this->state);
        }
    }

    /**
     * Update state from OutputData
     */
    public function updateData(OutputData $data): void
    {
        $this->state->updateFromOutputData($data);
    }

    /**
     * Add a worker log line
     */
    public function addWorkerLog(int $pid, string $line): void
    {
        $this->state->addWorkerLog($pid, $line);
    }

    /**
     * Process pending events
     *
     * @return bool True if should continue, false to exit
     */
    public function processEvents(): bool
    {
        // Poll for events (non-blocking)
        while ($event = $this->eventLoop->poll()) {
            // Record interaction time for any key event
            if ($event instanceof CharKeyEvent || $event instanceof CodedKeyEvent) {
                $this->state->recordInteraction();
            }

            if ($event instanceof CharKeyEvent) {
                $this->handleCharKeyEvent($event);
            } elseif ($event instanceof CodedKeyEvent) {
                $this->handleCodedKeyEvent($event);
            } elseif ($event instanceof MouseEvent) {
                $this->handleMouseEvent($event);
            } elseif ($event instanceof TerminalResizedEvent) {
                $this->handleResizeEvent();
            }

            if ($this->state->shouldExit) {
                return false;
            }
        }

        return true;
    }

    private function handleMouseEvent(MouseEvent $event): void
    {
        // Handle scroll events for navigation
        if ($event->kind === MouseEventKind::ScrollUp) {
            // Scroll up = navigate up in current tab
            $currentTab = $this->getCurrentTab();
            if ($currentTab !== null) {
                $currentTab->scrollUp($this->state);
            }
        } elseif ($event->kind === MouseEventKind::ScrollDown) {
            // Scroll down = navigate down in current tab
            $currentTab = $this->getCurrentTab();
            if ($currentTab !== null) {
                $currentTab->scrollDown($this->state);
            }
        }
        // Other mouse events (clicks, drags) are captured but ignored
        // This prevents them from affecting the terminal
    }

    private function handleResizeEvent(): void
    {
        // Rebuild display to pick up new terminal dimensions
        $this->display = DisplayBuilder::default()->build();
        $this->display->clear();

        // Force immediate re-render by resetting render timer
        $this->lastRenderTime = 0;
    }

    /**
     * Render the current state
     */
    public function render(): void
    {
        // Rate limit rendering
        $now = (int) (microtime(true) * 1000);
        if ($now - $this->lastRenderTime < self::MIN_RENDER_INTERVAL_MS) {
            return;
        }
        $this->lastRenderTime = $now;

        $mainWidget = $this->buildMainLayout();
        $this->display->draw($mainWidget);
    }

    /**
     * Get the current state (for external access)
     */
    public function getState(): TuiState
    {
        return $this->state;
    }

    /**
     * Queue an action to be executed
     */
    public function queueAction(string $name, callable $action): void
    {
        $this->actionQueue[$name] = $action;
    }

    /**
     * Process queued actions
     *
     * @return array<string, mixed> Results keyed by action name
     */
    public function processActionQueue(): array
    {
        // Collect pending actions from WorkersTab
        $this->collectTabPendingActions();

        $results = [];
        foreach ($this->actionQueue as $name => $action) {
            $results[$name] = $action();
        }
        $this->actionQueue = [];

        return $results;
    }

    /**
     * Collect pending actions from tabs that support them
     */
    private function collectTabPendingActions(): void
    {
        foreach ($this->tabs as $tab) {
            if ($tab instanceof Tabs\WorkersTab) {
                $pendingActions = $tab->getPendingActions();
                foreach ($pendingActions as $name => $action) {
                    $this->actionQueue[$name] = $action;
                }
            }
        }
    }

    /**
     * Shutdown and cleanup
     */
    public function shutdown(): void
    {
        $this->eventLoop->shutdown();
    }

    private function handleCharKeyEvent(CharKeyEvent $event): void
    {
        // Check for modal states first
        if ($this->state->confirmMode) {
            $this->handleConfirmModeChar($event);

            return;
        }

        if ($this->state->commandMode) {
            $this->handleCommandModeChar($event);

            return;
        }

        if ($this->state->filter->isActive) {
            $this->handleFilterModeChar($event);

            return;
        }

        // Global key bindings
        if (KeyBindings::isQuit($event)) {
            $this->state->shouldExit = true;

            return;
        }

        if (KeyBindings::isFilter($event)) {
            $this->state->filter->enterFilterMode();

            return;
        }

        if (KeyBindings::isCommand($event)) {
            $this->state->enterCommandMode();

            return;
        }

        // Tab switch shortcuts
        $tabIndex = KeyBindings::isTabSwitch($event);
        if ($tabIndex !== null && $tabIndex < count($this->tabs)) {
            $this->switchToTab($tabIndex);

            return;
        }

        // Delegate to current tab
        $currentTab = $this->getCurrentTab();
        if ($currentTab !== null) {
            $currentTab->handleCharKey($event, $this->state);
        }
    }

    private function handleCodedKeyEvent(CodedKeyEvent $event): void
    {
        // Check for modal states first
        if ($this->state->confirmMode) {
            $this->handleConfirmModeCoded($event);

            return;
        }

        if ($this->state->commandMode) {
            $this->handleCommandModeCoded($event);

            return;
        }

        if ($this->state->filter->isActive) {
            $this->handleFilterModeCoded($event);

            return;
        }

        // Tab navigation
        if (KeyBindings::isNextTab($event)) {
            $this->nextTab();

            return;
        }

        if (KeyBindings::isPreviousTab($event)) {
            $this->previousTab();

            return;
        }

        // Delegate to current tab
        $currentTab = $this->getCurrentTab();
        if ($currentTab !== null) {
            $currentTab->handleCodedKey($event, $this->state);
        }
    }

    private function handleFilterModeChar(CharKeyEvent $event): void
    {
        // Any printable character appends to filter
        $this->state->filter->appendChar($event->char);
    }

    private function handleFilterModeCoded(CodedKeyEvent $event): void
    {
        if (KeyBindings::isEscape($event)) {
            $this->state->filter->clearFilter();

            return;
        }

        if (KeyBindings::isBackspace($event)) {
            $this->state->filter->backspace();

            return;
        }

        if (KeyBindings::isEnter($event)) {
            $this->state->filter->applyFilter($this->state->navigation->currentTab);

            return;
        }
    }

    private function handleCommandModeChar(CharKeyEvent $event): void
    {
        $this->state->appendCommandChar($event->char);
    }

    private function handleCommandModeCoded(CodedKeyEvent $event): void
    {
        if (KeyBindings::isEscape($event)) {
            $this->state->exitCommandMode();

            return;
        }

        if (KeyBindings::isBackspace($event)) {
            $this->state->backspaceCommand();

            return;
        }

        if (KeyBindings::isEnter($event)) {
            $this->executeCommand($this->state->commandInput);
            $this->state->exitCommandMode();

            return;
        }
    }

    private function handleConfirmModeChar(CharKeyEvent $event): void
    {
        if ($event->char === 'y' || $event->char === 'Y') {
            $this->state->confirmAction();
        } elseif ($event->char === 'n' || $event->char === 'N') {
            $this->state->dismissConfirmDialog();
        }
    }

    private function handleConfirmModeCoded(CodedKeyEvent $event): void
    {
        if (KeyBindings::isEscape($event)) {
            $this->state->dismissConfirmDialog();

            return;
        }

        if (KeyBindings::isEnter($event)) {
            $this->state->confirmAction();
        }
    }

    private function executeCommand(string $command): void
    {
        $parts = explode(' ', trim($command));
        $cmd = $parts[0];
        $args = array_slice($parts, 1);

        // Queue commands for external processing
        $this->queueAction("command:{$cmd}", fn () => [
            'command' => $cmd,
            'args' => $args,
        ]);
    }

    private function switchToTab(int $index): void
    {
        if ($index === $this->state->navigation->currentTab) {
            return;
        }

        $currentTab = $this->getCurrentTab();
        if ($currentTab !== null) {
            $currentTab->onDeactivate($this->state);
        }

        $this->state->navigation->goToTab($index);

        $newTab = $this->getCurrentTab();
        if ($newTab !== null) {
            $newTab->onActivate($this->state);
        }
    }

    private function nextTab(): void
    {
        $next = ($this->state->navigation->currentTab + 1) % count($this->tabs);
        $this->switchToTab($next);
    }

    private function previousTab(): void
    {
        $prev = ($this->state->navigation->currentTab - 1 + count($this->tabs)) % count($this->tabs);
        $this->switchToTab($prev);
    }

    private function getCurrentTab(): ?TabContract
    {
        return $this->tabs[$this->state->navigation->currentTab] ?? null;
    }

    private function buildMainLayout(): GridWidget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1), // Tab bar - fixed 1 line
                Constraint::min(0),    // Main content - fill remaining space
                Constraint::length(1)  // Status bar - fixed 1 line
            )
            ->widgets(
                $this->buildTabBar(),
                $this->buildMainContent(),
                $this->buildStatusBar()
            );
    }

    private function buildTabBar(): ParagraphWidget
    {
        $spans = [];

        foreach ($this->tabs as $index => $tab) {
            $isActive = $index === $this->state->navigation->currentTab;

            $style = $isActive
                ? Style::default()->white()->onBlue()->bold()
                : Style::default()->gray();

            $spans[] = Span::styled(" [{$tab->getShortcut()}]{$tab->getName()} ", $style);
        }

        return ParagraphWidget::fromLines(
            Line::fromSpans(...$spans)
        );
    }

    private function buildMainContent(): BlockWidget
    {
        $currentTab = $this->getCurrentTab();

        if ($currentTab === null) {
            return BlockWidget::default()
                ->borders(Borders::ALL)
                ->widget(ParagraphWidget::fromString('No tabs registered'));
        }

        // Check for modal overlays
        if ($this->state->confirmMode) {
            return $this->buildConfirmDialog();
        }

        if ($this->state->commandMode) {
            return $this->buildCommandPalette();
        }

        // Get the tab's widget
        $area = $this->display->viewportArea();

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$currentTab->getName()} "))
            ->widget($currentTab->render($this->state, $area));
    }

    private function buildStatusBar(): ParagraphWidget
    {
        $spans = [];

        // Filter indicator
        if ($this->state->filter->isActive) {
            $spans[] = Span::styled(' /', Style::default()->yellow());
            $spans[] = Span::styled($this->state->filter->query, Style::default()->white());
            $spans[] = Span::styled('_ ', Style::default()->yellow());
        } elseif ($this->state->filter->hasFilter($this->state->navigation->currentTab)) {
            $filter = $this->state->filter->getTabFilter($this->state->navigation->currentTab);
            $spans[] = Span::styled(" Filter: {$filter} ", Style::default()->yellow());
        }

        // Command mode indicator
        if ($this->state->commandMode) {
            $spans[] = Span::styled(' :', Style::default()->cyan());
            $spans[] = Span::styled($this->state->commandInput, Style::default()->white());
            $spans[] = Span::styled('_ ', Style::default()->cyan());
        }

        // Global hints
        $hints = KeyBindings::getGlobalHints();
        $currentTab = $this->getCurrentTab();
        if ($currentTab !== null && ! $this->state->filter->isActive && ! $this->state->commandMode) {
            $hints = array_merge($hints, $currentTab->getStatusBarHints());
        }

        if (! $this->state->filter->isActive && ! $this->state->commandMode) {
            foreach ($hints as $key => $desc) {
                $spans[] = Span::styled(" {$key}", Style::default()->cyan());
                $spans[] = Span::styled(":{$desc}", Style::default()->gray());
            }
        }

        return ParagraphWidget::fromLines(
            Line::fromSpans(...$spans)
        );
    }

    private function buildConfirmDialog(): BlockWidget
    {
        $lines = [
            Line::fromString(''),
            Line::fromString($this->state->confirmMessage),
            Line::fromString(''),
            Line::fromSpans(
                Span::styled('[Y]', Style::default()->green()->bold()),
                Span::fromString('es  '),
                Span::styled('[N]', Style::default()->red()->bold()),
                Span::fromString('o')
            ),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Double)
            ->borderStyle(Style::default()->yellow())
            ->titles(Title::fromString(' Confirm '))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildCommandPalette(): BlockWidget
    {
        $lines = [
            Line::fromSpans(
                Span::styled(':', Style::default()->cyan()),
                Span::styled($this->state->commandInput, Style::default()->white()),
                Span::styled('_', Style::default()->cyan())
            ),
            Line::fromString(''),
            Line::fromSpans(
                Span::styled('Commands: ', Style::default()->gray()),
                Span::styled('restart [all|<pid>]', Style::default()->white()),
                Span::styled(' | ', Style::default()->gray()),
                Span::styled('scale <queue> <n>', Style::default()->white()),
            ),
            Line::fromSpans(
                Span::styled('          ', Style::default()->gray()),
                Span::styled('pause <queue>', Style::default()->white()),
                Span::styled(' | ', Style::default()->gray()),
                Span::styled('resume <queue>', Style::default()->white()),
                Span::styled(' | ', Style::default()->gray()),
                Span::styled('filter <pattern>', Style::default()->white()),
            ),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->cyan())
            ->titles(Title::fromString(' Command '))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }
}
