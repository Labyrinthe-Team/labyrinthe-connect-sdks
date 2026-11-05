<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

/**
 * One page of a paginated list. Iterate it for the current page, or use
 * autoPaging() to walk every item of every page (pages are fetched lazily).
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T>                $data
     * @param \Closure(int): Page<T> $fetchPage loads another page by number
     */
    public function __construct(
        public readonly array $data,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
        private readonly \Closure $fetchPage,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * @return Page<T>|null
     */
    public function nextPage(): ?self
    {
        return $this->hasMore() ? ($this->fetchPage)($this->currentPage + 1) : null;
    }

    /**
     * Every item from this page to the last one.
     *
     * @return \Generator<int, T>
     */
    public function autoPaging(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'data' => $this->data,
            'currentPage' => $this->currentPage,
            'lastPage' => $this->lastPage,
            'perPage' => $this->perPage,
            'total' => $this->total,
        ];
    }
}
