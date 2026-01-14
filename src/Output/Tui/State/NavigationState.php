<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\State;

final class NavigationState
{
    private const MAX_TABS = 6;

    private const PAGE_SIZE = 10;

    public int $currentTab = 0;

    /** @var array<int, int> Selected row per tab */
    public array $selectedRows = [];

    /** @var array<int, int> Scroll offset per tab */
    public array $scrollOffsets = [];

    public function __construct()
    {
        // Initialize state for all tabs
        for ($i = 0; $i < self::MAX_TABS; $i++) {
            $this->selectedRows[$i] = 0;
            $this->scrollOffsets[$i] = 0;
        }
    }

    public function nextTab(): void
    {
        $this->currentTab = ($this->currentTab + 1) % self::MAX_TABS;
    }

    public function previousTab(): void
    {
        $this->currentTab = ($this->currentTab - 1 + self::MAX_TABS) % self::MAX_TABS;
    }

    public function goToTab(int $tab): void
    {
        if ($tab >= 0 && $tab < self::MAX_TABS) {
            $this->currentTab = $tab;
        }
    }

    /**
     * Get selected row for a specific tab
     */
    public function getSelectedRow(int $tab): int
    {
        return $this->selectedRows[$tab] ?? 0;
    }

    /**
     * Set selected row for a specific tab
     */
    public function setSelectedRow(int $tab, int $row): void
    {
        $this->selectedRows[$tab] = max(0, $row);
    }

    /**
     * Move selection up for a specific tab
     */
    public function moveSelectionUp(int $tab, int $maxItems): void
    {
        $current = $this->getSelectedRow($tab);
        if ($current > 0) {
            $this->setSelectedRow($tab, $current - 1);
        }
    }

    /**
     * Move selection down for a specific tab
     */
    public function moveSelectionDown(int $tab, int $maxItems): void
    {
        $current = $this->getSelectedRow($tab);
        if ($current < $maxItems - 1) {
            $this->setSelectedRow($tab, $current + 1);
        }
    }

    /**
     * Get scroll offset for a specific tab
     */
    public function getScrollOffset(int $tab): int
    {
        return $this->scrollOffsets[$tab] ?? 0;
    }

    /**
     * Set scroll offset for a specific tab
     */
    public function setScrollOffset(int $tab, int $offset): void
    {
        $this->scrollOffsets[$tab] = max(0, $offset);
    }

    /**
     * Scroll up for a specific tab
     */
    public function scrollUp(int $tab, int $lines = 1): void
    {
        $current = $this->getScrollOffset($tab);
        $this->setScrollOffset($tab, max(0, $current - $lines));
    }

    /**
     * Scroll down for a specific tab
     */
    public function scrollDown(int $tab, int $maxItems, int $lines = 1): void
    {
        $current = $this->getScrollOffset($tab);
        $this->setScrollOffset($tab, $current + $lines);
    }

    /**
     * Page up for a specific tab
     */
    public function pageUp(int $tab, int $maxItems): void
    {
        $current = $this->getSelectedRow($tab);
        $newRow = max(0, $current - self::PAGE_SIZE);
        $this->setSelectedRow($tab, $newRow);

        // Also adjust scroll
        $scrollOffset = $this->getScrollOffset($tab);
        if ($newRow < $scrollOffset) {
            $this->setScrollOffset($tab, $newRow);
        }
    }

    /**
     * Page down for a specific tab
     */
    public function pageDown(int $tab, int $maxItems): void
    {
        $current = $this->getSelectedRow($tab);
        $newRow = min($maxItems - 1, $current + self::PAGE_SIZE);
        $this->setSelectedRow($tab, max(0, $newRow));

        // Also adjust scroll
        $scrollOffset = $this->getScrollOffset($tab);
        if ($newRow >= $scrollOffset + self::PAGE_SIZE) {
            $this->setScrollOffset($tab, max(0, $newRow - self::PAGE_SIZE + 1));
        }
    }

    /**
     * Reset state for a specific tab
     */
    public function resetTabState(int $tab): void
    {
        $this->selectedRows[$tab] = 0;
        $this->scrollOffsets[$tab] = 0;
    }

    /**
     * Move selection up for current tab (convenience method for mouse scroll)
     */
    public function moveUp(): void
    {
        $current = $this->getSelectedRow($this->currentTab);
        if ($current > 0) {
            $this->setSelectedRow($this->currentTab, $current - 1);
        }
    }

    /**
     * Move selection down for current tab (convenience method for mouse scroll)
     */
    public function moveDown(): void
    {
        $current = $this->getSelectedRow($this->currentTab);
        // Allow moving down without bounds check - let the tab handle limiting
        $this->setSelectedRow($this->currentTab, $current + 1);
    }
}
