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

namespace PHPDevsr\Profiler\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stores and retrieves profile records as JSON files on disk.
 *
 * Each saved profile is written to a single file named
 * "<id>.json" inside the configured data directory.
 */
class FileStorage
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    /**
     * Save a profile record to disk.
     *
     * Required keys: 'id', 'endpoint', 'folded_stacks'.
     *
     * @param array<string, mixed> $profile
     *
     * @throws InvalidArgumentException if required keys are missing
     * @throws RuntimeException         if the file cannot be written
     */
    public function save(array $profile): void
    {
        if (! isset($profile['id'], $profile['endpoint'], $profile['folded_stacks'])) {
            throw new InvalidArgumentException(
                'Profile must contain at least the keys: id, endpoint, folded_stacks.'
            );
        }

        $this->ensureDataDir();

        $filename = $this->dataDir . '/' . (string) $profile['id'] . '.json';
        $encoded  = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false || file_put_contents($filename, $encoded) === false) {
            throw new RuntimeException('Failed to write profile file: ' . $filename);
        }
    }

    /**
     * Return all stored profiles, unsorted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $files    = glob($this->dataDir . '/*.json');
        $profiles = [];

        if ($files === false) {
            return $profiles;
        }

        foreach ($files as $file) {
            $raw = file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($raw, true);

            if (is_array($data)) {
                $profiles[] = $data;
            }
        }

        return $profiles;
    }

    /**
     * Return all profiles recorded for a specific endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByEndpoint(string $endpoint): array
    {
        return array_values(
            array_filter(
                $this->findAll(),
                static fn (array $p): bool => ($p['endpoint'] ?? '') === $endpoint
            )
        );
    }

    /**
     * Return per-endpoint statistics sorted by total sample count (descending).
     *
     * Each entry contains:
     *   - endpoint         (string)
     *   - request_count    (int)
     *   - total_samples    (int)
     *   - total_duration_ms (float)
     *   - avg_duration_ms  (float)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEndpointStats(): array
    {
        /** @var array<string, array<string, mixed>> $endpoints */
        $endpoints = [];

        foreach ($this->findAll() as $profile) {
            $ep = (string) ($profile['endpoint'] ?? '');

            if (! isset($endpoints[$ep])) {
                $endpoints[$ep] = [
                    'endpoint'          => $ep,
                    'request_count'     => 0,
                    'total_samples'     => 0,
                    'total_duration_ms' => 0.0,
                    'avg_duration_ms'   => 0.0,
                ];
            }

            $endpoints[$ep]['request_count']++;
            $endpoints[$ep]['total_samples']     += (int) ($profile['sample_count'] ?? 0);
            $endpoints[$ep]['total_duration_ms'] += (float) ($profile['duration_ms'] ?? 0.0);
        }

        foreach ($endpoints as &$ep) {
            $count                = (int) $ep['request_count'];
            $ep['avg_duration_ms'] = $count > 0
                ? round((float) $ep['total_duration_ms'] / $count, 2)
                : 0.0;
        }

        unset($ep);

        usort(
            $endpoints,
            static fn (array $a, array $b): int => (int) $b['total_samples'] - (int) $a['total_samples']
        );

        return array_values($endpoints);
    }

    /**
     * Delete the oldest files when more than $maxFiles profiles are stored.
     *
     * @return int Number of files deleted.
     */
    public function cleanup(int $maxFiles = 10_000): int
    {
        $files = glob($this->dataDir . '/*.json');

        if ($files === false) {
            return 0;
        }

        $count = count($files);

        if ($count <= $maxFiles) {
            return 0;
        }

        sort($files);
        $toDelete = array_slice($files, 0, $count - $maxFiles);

        foreach ($toDelete as $file) {
            unlink($file);
        }

        return count($toDelete);
    }

    /**
     * Create the data directory if it does not already exist.
     *
     * @throws RuntimeException if the directory cannot be created
     */
    private function ensureDataDir(): void
    {
        if (is_dir($this->dataDir)) {
            return;
        }

        if (! mkdir($this->dataDir, 0755, true) && ! is_dir($this->dataDir)) {
            throw new RuntimeException('Failed to create data directory: ' . $this->dataDir);
        }
    }
}
