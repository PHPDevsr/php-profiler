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

namespace Tests;

use PHPDevsr\Profiler\Profiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * @internal
 */
final class ProfilerTest extends TestCase
{
    private Profiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new Profiler();
    }

    public function testDefaultPeriod(): void
    {
        assertSame(0.01, $this->profiler->getPeriod());
    }

    public function testCustomPeriod(): void
    {
        $profiler = new Profiler(0.05);
        assertSame(0.05, $profiler->getPeriod());
    }

    public function testSetPeriod(): void
    {
        $this->profiler->setPeriod(0.02);
        assertSame(0.02, $this->profiler->getPeriod());
    }

    public function testIsNotRunningInitially(): void
    {
        assertFalse($this->profiler->isRunning());
    }

    public function testStartSetsRunning(): void
    {
        $this->profiler->start();
        assertTrue($this->profiler->isRunning());
        $this->profiler->stop();
    }

    public function testStopSetsNotRunning(): void
    {
        $this->profiler->start();
        $this->profiler->stop();
        assertFalse($this->profiler->isRunning());
    }

    public function testStartThrowsIfAlreadyRunning(): void
    {
        $this->profiler->start();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profiler is already running.');

        try {
            $this->profiler->start();
        } finally {
            $this->profiler->stop();
        }
    }

    public function testStopThrowsIfNotRunning(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profiler is not running.');
        $this->profiler->stop();
    }

    public function testSetPeriodThrowsIfRunning(): void
    {
        $this->profiler->start();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot change period while profiler is running.');

        try {
            $this->profiler->setPeriod(0.05);
        } finally {
            $this->profiler->stop();
        }
    }

    public function testGetLogInitiallyEmpty(): void
    {
        assertSame([], $this->profiler->getLog());
    }

    public function testResetClearsLog(): void
    {
        $this->profiler->reset();
        assertSame([], $this->profiler->getLog());
    }

    public function testResetThrowsIfRunning(): void
    {
        $this->profiler->start();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot reset while profiler is running.');

        try {
            $this->profiler->reset();
        } finally {
            $this->profiler->stop();
        }
    }

    public function testGetFoldedStacksInitiallyEmpty(): void
    {
        assertSame('', $this->profiler->getFoldedStacks());
    }

    public function testGetFoldedStacksEmptyAfterStartStop(): void
    {
        // Without the excimer extension the folded stacks remain empty.
        $this->profiler->start();
        $this->profiler->stop();
        assertSame('', $this->profiler->getFoldedStacks());
    }

    public function testResetClearsFoldedStacks(): void
    {
        $this->profiler->reset();
        assertSame('', $this->profiler->getFoldedStacks());
    }

    public function testStartClearsPreviousData(): void
    {
        // start() / stop() pair should always reset log and folded stacks.
        $this->profiler->start();
        $this->profiler->stop();
        assertSame([], $this->profiler->getLog());
        assertSame('', $this->profiler->getFoldedStacks());

        // A second start should clear them again.
        $this->profiler->start();
        $this->profiler->stop();
        assertSame([], $this->profiler->getLog());
        assertSame('', $this->profiler->getFoldedStacks());
    }
}
