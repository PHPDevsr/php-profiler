<?php

declare(strict_types=1);

/**
 * Stub for the ExcimerProfiler class provided by the excimer PHP extension.
 *
 * @see https://www.mediawiki.org/wiki/Excimer
 */
class ExcimerProfiler
{
    /**
     * Set the sampling period in seconds.
     */
    public function setPeriod(float $period): void {}

    /**
     * Set the event type (EXCIMER_REAL or EXCIMER_CPU).
     */
    public function setEventType(int $eventType): void {}

    /**
     * Set how many frames to exclude from the bottom of each stack.
     */
    public function setExcludeDepth(int $depth): void {}

    /**
     * Start profiling.
     */
    public function start(): void {}

    /**
     * Stop profiling.
     */
    public function stop(): void {}

    /**
     * Flush buffered data to the log callback.
     */
    public function flush(): void {}

    /**
     * Get the collected profiling log.
     */
    public function getLog(): ExcimerLog
    {
        return new ExcimerLog();
    }
}

if (!defined('EXCIMER_REAL')) {
    define('EXCIMER_REAL', 0);
}

if (!defined('EXCIMER_CPU')) {
    define('EXCIMER_CPU', 1);
}
