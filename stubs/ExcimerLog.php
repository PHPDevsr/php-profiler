<?php

declare(strict_types=1);

/**
 * Stub for the ExcimerLog class provided by the excimer PHP extension.
 *
 * @see https://www.mediawiki.org/wiki/Excimer
 *
 * @implements \IteratorAggregate<int, ExcimerLogEntry>
 */
class ExcimerLog implements Countable, IteratorAggregate
{
    /**
     * Return the log in "folded stacks" format, suitable for flamegraph tools.
     *
     * Each line is "frame1;frame2;...;frameN count".
     */
    public function formatFolded(): string
    {
        return '';
    }

    /**
     * Return the number of samples in the log.
     */
    public function count(): int
    {
        return 0;
    }

    /**
     * @return \ArrayIterator<int, ExcimerLogEntry>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator([]);
    }

    /**
     * Aggregate samples by function name.
     *
     * @return array<string, int>
     */
    public function aggregateByFunction(): array
    {
        return [];
    }
}
