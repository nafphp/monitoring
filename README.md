# NAF Monitoring

Declared metrics, StatsD/DogStatsD export and optional PSR-3 logging for NAF.
Requires PHP 8.3+ and `naf/framework` 0.2.3+. Composer package: `naf/monitoring`;
GitHub repository: `nafphp/monitoring`.

**Development preview:** `v0.1.0-rc` is not a stable release or a published
Packagist installation. In a disposable NAF host, install the review branch:

```bash
composer config repositories.naf-monitoring vcs https://github.com/nafphp/monitoring
composer require naf/monitoring:dev-v0.1.0-rc
```

The plugin is discovered automatically. It ships with a no-op exporter;
HTTP instrumentation and logger decoration are opt-in.

Merge into the array returned by `app/config.php`:

```php
<?php
use Naf\Monitoring\Exporters\StatsDExporter;

return ['monitoring' => [
    'exporter' => StatsDExporter::class,
    'exporter_options' => [
        'host' => '127.0.0.1',
        'port' => 8125,
        'prefix' => 'shop',
        'protocol' => 'statsd', // Or dogstatsd for native tags/histograms.
    ],
    'metrics' => [
        'orders.created' => ['type' => 'counter', 'labels' => ['channel']],
        'queue.depth' => ['type' => 'gauge'],
        'checkout.duration' => ['type' => 'histogram', 'unit' => 's'],
    ],
    'http' => true,
    'logging' => ['enabled' => true, 'min_level' => 'warning'],
]];
```

From a handler or application service after NAF boots:

```php
use function Naf\Monitoring\monitor;

monitor('orders.created', ['channel' => 'web'])->increment();
monitor('queue.depth')->set(42);
monitor('checkout.duration')->observe(0.125);

// Optional timing wrapper: returns the callback result, including on export failure.
$result = monitor('checkout.duration')->measure(fn() => $checkout->run());
```

Metrics are buffered and flushed at the configured batch size, HTTP response end
and normal PHP shutdown. Workers should call `monitor()->flush()` after each job.
StatsD sends best-effort UDP; socket success does not confirm delivery. The logger
threshold filters the monitoring copy while the existing logger receives every log.

See the [complete guide](docs/monitoring.md) for configuration, protocol semantics,
multiple destinations, structured logs, custom exporters, tests and lifecycle limits.
The guide is prepared here for publication to the NAF docs after the first release.

## Development

```bash
composer install
composer validate --strict
composer test
```

The suite covers unit behavior, loopback UDP and real HTTP/CLI hosts. It uses the
published framework dependency; it does not depend on a sibling checkout. CI runs
PHP 8.3, 8.4 and 8.5. For a separately installed host, run:

```bash
php tests/host.php /absolute/path/to/host/vendor/autoload.php
php tests/http.php /absolute/path/to/host/vendor/autoload.php
```

Read [AGENTS.md](AGENTS.md) and the
[shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md).
