<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\State;

final class FilterState
{
    public bool $isActive = false;

    public string $query = '';

    /** @var array<int, string> Filter query per tab */
    private array $tabFilters = [];

    public function enterFilterMode(): void
    {
        $this->isActive = true;
        $this->query = '';
    }

    public function exitFilterMode(): void
    {
        $this->isActive = false;
    }

    public function clearFilter(): void
    {
        $this->query = '';
        $this->isActive = false;
    }

    public function appendChar(string $char): void
    {
        $this->query .= $char;
    }

    public function backspace(): void
    {
        if ($this->query !== '') {
            $this->query = mb_substr($this->query, 0, -1);
        }
    }

    public function setTabFilter(int $tab, string $query): void
    {
        $this->tabFilters[$tab] = $query;
    }

    public function getTabFilter(int $tab): string
    {
        return $this->tabFilters[$tab] ?? '';
    }

    public function applyFilter(int $tab): void
    {
        $this->tabFilters[$tab] = $this->query;
        $this->exitFilterMode();
    }

    public function hasFilter(int $tab): bool
    {
        return isset($this->tabFilters[$tab]) && $this->tabFilters[$tab] !== '';
    }

    /**
     * Filter an array of items by a callback
     *
     * @template T
     *
     * @param  array<T>  $items
     * @param  callable(T, string): bool  $matcher
     * @return array<T>
     */
    public function filterItems(array $items, int $tab, callable $matcher): array
    {
        $query = $this->getTabFilter($tab);
        if ($query === '') {
            return $items;
        }

        return array_filter($items, fn ($item) => $matcher($item, $query));
    }

    /**
     * Case-insensitive string contains check
     */
    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return mb_stripos($haystack, $needle) !== false;
    }
}
