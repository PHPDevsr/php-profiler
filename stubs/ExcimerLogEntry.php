<?php

declare(strict_types=1);

/**
 * Stub for the ExcimerLogEntry class provided by the excimer PHP extension.
 *
 * @see https://www.mediawiki.org/wiki/Excimer
 */
class ExcimerLogEntry
{
    /**
     * Get the timestamp of the sample.
     */
    public function getTimestamp(): float
    {
        return 0.0;
    }

    /**
     * Get the backtrace of the sample.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTrace(): array
    {
        return [];
    }
}
