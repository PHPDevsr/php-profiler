<?php

declare(strict_types=1);

/**
 * PHP Profiler – auto_prepend_file entry point.
 *
 * Add the following to php.ini (or a per-vhost .user.ini):
 *
 *   auto_prepend_file = /opt/php-profiler/profiler.php
 *
 * This file requires the "excimer" PHP extension.
 * If the extension is not loaded the file is a no-op.
 *
 * Data is written to the "data/" directory next to this file.
 * Override by setting the environment variable PHP_PROFILER_DATA_DIR.
 *
 * The dashboard is intentionally excluded from profiling.
 * Secure the dashboard path in your web-server config:
 *
 *   location /profiler/ {
 *       allow 127.0.0.1;
 *       deny  all;
 *   }
 */

// ── Guard: only profile real HTTP requests ────────────────────────────────────

if (PHP_SAPI === 'cli') {
    return;
}

if (! extension_loaded('excimer')) {
    return;
}

// Skip profiling the dashboard itself to avoid recursion / noise.
// Sanitize to printable ASCII only before storing or comparing.
$_profilerRequestUri = preg_replace('/[^\x20-\x7E]/', '', $_SERVER['REQUEST_URI'] ?? '') ?? '';

if (
    str_contains($_profilerRequestUri, '/profiler')
    || str_contains($_profilerRequestUri, '/__profiler')
) {
    return;
}

// ── Data directory ────────────────────────────────────────────────────────────

$_profilerDataDir = (string) (getenv('PHP_PROFILER_DATA_DIR') ?: (__DIR__ . '/data'));

if (! is_dir($_profilerDataDir) && ! mkdir($_profilerDataDir, 0755, true) && ! is_dir($_profilerDataDir)) {
    // Cannot create storage – bail out silently to avoid breaking the app.
    return;
}

// ── Start Excimer ─────────────────────────────────────────────────────────────

$_profilerExcimer    = new ExcimerProfiler();
$_profilerStartTime  = microtime(true);

$_profilerExcimer->setPeriod(0.01);           // sample every 10 ms
$_profilerExcimer->setEventType(EXCIMER_REAL);
$_profilerExcimer->setExcludeDepth(2);        // exclude the profiler frames themselves
$_profilerExcimer->start();

// ── Shutdown handler: save results ───────────────────────────────────────────

register_shutdown_function(
    static function () use ($_profilerExcimer, $_profilerStartTime, $_profilerDataDir, $_profilerRequestUri): void {
        $_profilerExcimer->stop();

        $log    = $_profilerExcimer->getLog();
        $folded = $log->formatFolded();

        if (trim($folded) === '') {
            return;
        }

        // Strip query string – group by path only.
        $endpoint = $_profilerRequestUri;
        $qpos     = strpos($endpoint, '?');

        if ($qpos !== false) {
            $endpoint = substr($endpoint, 0, $qpos);
        }

        // Count total samples (number of non-empty lines in the trimmed folded string).
        $trimmedFolded = trim($folded);
        $sampleCount   = substr_count($trimmedFolded, "\n") + 1;

        $id      = date('YmdHis') . '_' . bin2hex(random_bytes(8));
        $profile = [
            'id'            => $id,
            'timestamp'     => $_profilerStartTime,
            'endpoint'      => $endpoint,
            'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'duration_ms'   => round((microtime(true) - $_profilerStartTime) * 1000.0, 2),
            'sample_count'  => $sampleCount,
            'folded_stacks' => $folded,
        ];

        $filename = $_profilerDataDir . '/' . $id . '.json';
        $encoded  = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded !== false) {
            file_put_contents($filename, $encoded);
        }

        // ── Rolling cleanup: keep at most 10 000 profile files ──────────────
        $allFiles = glob($_profilerDataDir . '/*.json');

        if ($allFiles !== false && count($allFiles) > 10_000) {
            sort($allFiles);

            foreach (array_slice($allFiles, 0, count($allFiles) - 10_000) as $oldFile) {
                unlink($oldFile);
            }
        }
    }
);
