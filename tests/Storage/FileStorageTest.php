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

namespace Tests\Storage;

use InvalidArgumentException;
use PHPDevsr\Profiler\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileStorageTest extends TestCase
{
    private string $tmpDir;

    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/php-profiler-test-' . uniqid('', true);
        $this->storage = new FileStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Remove test files
        $files = glob($this->tmpDir . '/*.json') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ── save() ────────────────────────────────────────────────────────────────

    public function testSaveCreatesJsonFile(): void
    {
        $profile = $this->makeProfile('p1', '/api/users');
        $this->storage->save($profile);

        assertFileExists($this->tmpDir . '/p1.json');
    }

    public function testSaveCreatesDataDirIfMissing(): void
    {
        assertDirectoryDoesNotExist($this->tmpDir);
        $this->storage->save($this->makeProfile('p1', '/api/users'));
        assertDirectoryExists($this->tmpDir);
    }

    public function testSaveThrowsOnMissingRequiredKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->save(['id' => 'x', 'endpoint' => '/foo']);
    }

    public function testSavedFileContainsExpectedJson(): void
    {
        $profile = $this->makeProfile('p2', '/api/orders', 'main;foo;bar 5');
        $this->storage->save($profile);

        $raw  = file_get_contents($this->tmpDir . '/p2.json');
        $data = json_decode((string) $raw, true);

        assertIsArray($data);
        assertSame('/api/orders', $data['endpoint']);
        assertSame('main;foo;bar 5', $data['folded_stacks']);
    }

    // ── findAll() ─────────────────────────────────────────────────────────────

    public function testFindAllReturnsEmptyForEmptyDir(): void
    {
        mkdir($this->tmpDir, 0755, true);
        assertSame([], $this->storage->findAll());
    }

    public function testFindAllReturnsAllSavedProfiles(): void
    {
        $this->storage->save($this->makeProfile('a1', '/api/a'));
        $this->storage->save($this->makeProfile('b2', '/api/b'));
        $this->storage->save($this->makeProfile('c3', '/api/c'));

        $all = $this->storage->findAll();
        assertCount(3, $all);
    }

    // ── findByEndpoint() ──────────────────────────────────────────────────────

    public function testFindByEndpointReturnsMatchingProfiles(): void
    {
        $this->storage->save($this->makeProfile('x1', '/api/users'));
        $this->storage->save($this->makeProfile('x2', '/api/users'));
        $this->storage->save($this->makeProfile('x3', '/api/orders'));

        $users = $this->storage->findByEndpoint('/api/users');
        assertCount(2, $users);

        foreach ($users as $p) {
            assertSame('/api/users', $p['endpoint']);
        }
    }

    public function testFindByEndpointReturnsEmptyWhenNoMatch(): void
    {
        $this->storage->save($this->makeProfile('y1', '/api/users'));
        assertSame([], $this->storage->findByEndpoint('/api/other'));
    }

    // ── getEndpointStats() ────────────────────────────────────────────────────

    public function testGetEndpointStatsSortsByTotalSamplesDesc(): void
    {
        // /api/b gets more samples so should rank first
        $this->storage->save($this->makeProfile('s1', '/api/a', 'main 3',  3, 100.0));
        $this->storage->save($this->makeProfile('s2', '/api/b', 'main 10', 10, 200.0));
        $this->storage->save($this->makeProfile('s3', '/api/b', 'main 5',  5, 150.0));

        $stats = $this->storage->getEndpointStats();

        assertCount(2, $stats);
        assertSame('/api/b', $stats[0]['endpoint']);
        assertSame(15, $stats[0]['total_samples']);
        assertSame(2,  $stats[0]['request_count']);
        assertSame(175.0, $stats[0]['avg_duration_ms']);

        assertSame('/api/a', $stats[1]['endpoint']);
        assertSame(3, $stats[1]['total_samples']);
        assertSame(1, $stats[1]['request_count']);
    }

    public function testGetEndpointStatsReturnsEmptyForEmptyDir(): void
    {
        mkdir($this->tmpDir, 0755, true);
        assertSame([], $this->storage->getEndpointStats());
    }

    // ── cleanup() ─────────────────────────────────────────────────────────────

    public function testCleanupDeletesOldestFiles(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save($this->makeProfile('file' . $i, '/api/x'));
        }

        $deleted = $this->storage->cleanup(3);

        assertSame(2, $deleted);
        assertCount(3, glob($this->tmpDir . '/*.json') ?: []);
    }

    public function testCleanupDoesNothingWhenUnderLimit(): void
    {
        $this->storage->save($this->makeProfile('f1', '/api/x'));
        $this->storage->save($this->makeProfile('f2', '/api/x'));

        assertSame(0, $this->storage->cleanup(10));
        assertCount(2, glob($this->tmpDir . '/*.json') ?: []);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makeProfile(
        string $id,
        string $endpoint,
        string $foldedStacks = 'main 1',
        int $sampleCount = 1,
        float $durationMs = 50.0
    ): array {
        return [
            'id'            => $id,
            'timestamp'     => microtime(true),
            'endpoint'      => $endpoint,
            'method'        => 'GET',
            'duration_ms'   => $durationMs,
            'sample_count'  => $sampleCount,
            'folded_stacks' => $foldedStacks,
        ];
    }
}
