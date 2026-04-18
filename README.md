# php-profiler

PHP Sampling Profiler using the [Excimer](https://www.mediawiki.org/wiki/Excimer) extension, with automatic per-request collection and a built-in flamegraph dashboard.

---

## Features

- **Zero-code instrumentation** – add one line to `php.ini` and every request is profiled automatically.
- **Flamegraph dashboard** – interactive SVG flamegraph with zoom, tooltips, and frame highlighting.
- **Endpoint ranking** – requests grouped by URI path, sorted by total sample count.
- **Merged view** – all requests for the same endpoint are merged into a single flamegraph.
- **Export JSON** – download the merged folded-stacks profile for offline analysis.
- **Automatic cleanup** – keeps the newest 10 000 profiles; older files are pruned on each request.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.3 |
| [ext-excimer](https://pecl.php.net/package/excimer) | any |

---

## Installation

```bash
composer require phpdevsr/php-profiler
```

Or clone / install directly to `/opt/php-profiler`:

```bash
git clone https://github.com/PHPDevsr/php-profiler /opt/php-profiler
```

---

## Quick-start

### 1 – Enable auto-profiling in `php.ini`

```ini
auto_prepend_file = /opt/php-profiler/profiler.php
```

Optionally override the data directory:

```ini
; defaults to /opt/php-profiler/data
auto_prepend_file = /opt/php-profiler/profiler.php
```

Or via an environment variable:

```bash
PHP_PROFILER_DATA_DIR=/var/lib/php-profiler/data
```

### 2 – Expose the dashboard (Nginx example)

```nginx
# Serve the dashboard at /profiler/
location /profiler/ {
    alias /opt/php-profiler/dashboard/;

    # Restrict to localhost only
    allow 127.0.0.1;
    deny  all;

    index index.php;
    try_files $uri $uri/ /profiler/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }
}
```

Apache example:

```apache
Alias /profiler /opt/php-profiler/dashboard

<Directory /opt/php-profiler/dashboard>
    Options -Indexes
    AllowOverride None
    Require ip 127.0.0.1
    DirectoryIndex index.php
</Directory>
```

### 3 – Open the dashboard

```
http://127.0.0.1/profiler/
```

Make a few HTTP requests to your application, then refresh the dashboard to see endpoint rankings and flamegraphs.

---

## Library API

You can also use the `Profiler` class directly in your code:

```php
use PHPDevsr\Profiler\Profiler;

$profiler = new Profiler(period: 0.01); // 10 ms sampling interval

$profiler->start();
// … code to profile …
$profiler->stop();

// Raw folded-stacks string (Excimer format, compatible with flamegraph tools)
$folded = $profiler->getFoldedStacks();

// Parsed log: array of ['stack' => string[], 'count' => int]
$log = $profiler->getLog();
```

### FileStorage

```php
use PHPDevsr\Profiler\Storage\FileStorage;

$storage = new FileStorage('/var/lib/php-profiler/data');

$storage->save([
    'id'            => uniqid('', true),
    'timestamp'     => microtime(true),
    'endpoint'      => '/api/users',
    'method'        => 'GET',
    'duration_ms'   => 45.2,
    'sample_count'  => 12,
    'folded_stacks' => $profiler->getFoldedStacks(),
]);

// Endpoint statistics (sorted by total samples, descending)
$stats = $storage->getEndpointStats();

// Profiles for one endpoint
$profiles = $storage->findByEndpoint('/api/users');

// Delete oldest files when total exceeds $maxFiles
$storage->cleanup(maxFiles: 10_000);
```

---

## Development

```bash
# Run tests
composer test

# Static analysis
composer phpstan

# Rector dry-run
composer rector
```

---

## License

MIT — see [LICENSE](LICENSE).
