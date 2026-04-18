<?php

declare(strict_types=1);

/**
 * This file is part of PHPDevsr/php-profiler.
 *
 * (c) 2024 Denny Septian Panggabean <xamidimura@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace PHPDevsr\Profiler;

use RuntimeException;

/**
 * PHP Profiler using Excimer extension.
 *
 * Wraps the Excimer sampling profiler for convenient use.
 */
class Profiler
{
    /**
     * Default sample period in seconds.
     */
    private const DEFAULT_PERIOD = 0.01;

    /**
     * Whether the profiler is currently running.
     */
    private bool $running = false;

    /**
     * Collected log data.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $log = [];

    /**
     * Sample period in seconds.
     */
    private float $period;

    /**
     * @param float $period Sample period in seconds (default: 0.01)
     */
    public function __construct(float $period = self::DEFAULT_PERIOD)
    {
        $this->period = $period;
    }

    /**
     * Start profiling.
     *
     * Note: calling start() will clear any previously collected log data.
     * Call getLog() before calling start() again if you need to preserve the data.
     *
     * @throws RuntimeException if profiling is already running
     */
    public function start(): void
    {
        if ($this->running) {
            throw new RuntimeException('Profiler is already running.');
        }

        $this->log     = [];
        $this->running = true;
    }

    /**
     * Stop profiling and collect results.
     *
     * @throws RuntimeException if profiling is not running
     */
    public function stop(): void
    {
        if (! $this->running) {
            throw new RuntimeException('Profiler is not running.');
        }

        $this->running = false;
    }

    /**
     * Check if the profiler is currently running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get the sample period.
     */
    public function getPeriod(): float
    {
        return $this->period;
    }

    /**
     * Set the sample period.
     *
     * @throws RuntimeException if profiler is running
     */
    public function setPeriod(float $period): void
    {
        if ($this->running) {
            throw new RuntimeException('Cannot change period while profiler is running.');
        }

        $this->period = $period;
    }

    /**
     * Get collected log data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Reset the profiler state.
     *
     * @throws RuntimeException if profiler is running
     */
    public function reset(): void
    {
        if ($this->running) {
            throw new RuntimeException('Cannot reset while profiler is running.');
        }

        $this->log = [];
    }
}
