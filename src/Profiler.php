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
 * When the excimer extension is not loaded the profiler still tracks
 * start/stop state so it can be used in environments without the extension.
 */
class Profiler
{
    /**
     * Default sample period in seconds.
     */
    private const float DEFAULT_PERIOD = 0.01;

    /**
     * Whether the profiler is currently running.
     */
    private bool $running = false;

    /**
     * Collected log data (parsed folded stacks).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $log = [];

    /**
     * Raw folded stacks string produced by Excimer after stop().
     */
    private string $foldedStacks = '';

    /**
     * Underlying Excimer profiler instance (null when extension unavailable).
     */
    private ?ExcimerProfiler $excimerProfiler = null;

    /**
    * @param float $period Sample period in seconds (default: 0.01)
    */
    public function __construct(private float $period = self::DEFAULT_PERIOD)
    {
        $this->period = $period;
    }

    /**
     * Start profiling.
     *
     * When the excimer extension is loaded a real sampling profiler is started.
     * Note: calling start() will clear any previously collected log data.
     * Call getLog() / getFoldedStacks() before calling start() again if you
     * need to preserve the data.
     *
     * @throws RuntimeException if profiling is already running
     */
    public function start(): void
    {
        if ($this->running) {
            throw new RuntimeException('Profiler is already running.');
        }

        $this->log          = [];
        $this->foldedStacks = '';
        $this->running      = true;

        if (extension_loaded('excimer')) {
            $this->excimerProfiler = new ExcimerProfiler();
            $this->excimerProfiler->setPeriod($this->period);
            $this->excimerProfiler->setEventType(EXCIMER_REAL);
            $this->excimerProfiler->start();
        }
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

        if ($this->excimerProfiler instanceof ExcimerProfiler) {
            $this->excimerProfiler->stop();
            $excimerLog            = $this->excimerProfiler->getLog();
            $this->foldedStacks    = $excimerLog->formatFolded();
            $this->log             = $this->parseFoldedStacks($this->foldedStacks);
            $this->excimerProfiler = null;
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
     * Each entry is an array with keys:
     *   - 'stack' => array<int, string>  (call stack, outermost first)
     *   - 'count' => int                 (number of samples for this stack)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Get the raw folded-stacks string produced by Excimer.
     *
     * Format: one line per unique stack, "frame1;frame2;...;frameN count".
     * Returns an empty string when excimer is not available or before stop().
     */
    public function getFoldedStacks(): string
    {
        return $this->foldedStacks;
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

        $this->log          = [];
        $this->foldedStacks = '';
    }

    /**
     * Parse a folded-stacks string into a structured array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFoldedStacks(string $folded): array
    {
        $result = [];
        $folded = trim($folded);

        if ($folded === '') {
            return $result;
        }

        foreach (explode("\n", $folded) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $lastSpace = strrpos($line, ' ');

            if ($lastSpace === false) {
                continue;
            }

            $stack     = substr($line, 0, $lastSpace);
            $rawCount  = substr($line, $lastSpace + 1);

            // Skip malformed lines where the count is not a positive integer.
            if (! ctype_digit($rawCount)) {
                continue;
            }

            $count = (int) $rawCount;

            if ($count <= 0) {
                continue;
            }

            $result[] = [
                'stack' => explode(';', $stack),
                'count' => $count,
            ];
        }

        return $result;
    }
}
