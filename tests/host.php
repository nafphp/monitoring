<?php

declare(strict_types=1);

use Naf\Monitoring\Exporters\InMemoryExporter;
use Naf\Monitoring\LogExporterInterface;
use Naf\Monitoring\MetricExporterInterface;
use Naf\Monitoring\Monitor;
use Naf\Monitoring\MonitoringLogger;
use Tests\Fixtures\RecordingLogExporter;

use function Naf\app;
use function Naf\config;
use function Naf\log;
use function Naf\Monitoring\monitor;

require __DIR__ . '/support.php';

$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
$host     = sys_get_temp_dir() . '/naf-monitoring-host-' . bin2hex(random_bytes(12));

mkdir($host . '/app', 0700, true);
define('BASE_PATH', $host);

try {
    file_put_contents($host . '/app/config.php', <<<'CONFIG'
    <?php
    use Naf\Monitoring\Exporters\InMemoryExporter;
    return ['monitoring' => [
        'exporter' => InMemoryExporter::class,
        'labels' => ['service' => 'shop'],
        'logging' => ['enabled' => true, 'min_level' => 'error'],
        'metrics' => [
            'orders.created' => ['type' => 'counter', 'labels' => ['channel']],
            'queue.depth' => ['type' => 'gauge'],
            'checkout.duration' => ['type' => 'histogram', 'unit' => 's'],
        ],
    ]];
    CONFIG);

    require $autoload;
    require_once __DIR__ . '/Fixtures/RecordingLogExporter.php';

    app()->run();

    check(app()->hasPlugin('naf/monitoring'), 'Composer did not discover naf/monitoring.');
    check(config('monitoring:batch_size') === 256, 'Plugin defaults did not merge.');
    check(config('monitoring:http') === false, 'HTTP instrumentation should be opt-in.');

    $container  = app()->container();
    $configured = $container->get(MetricExporterInterface::class);
    check($configured instanceof InMemoryExporter, 'Configured exporter was not created by NAF DI.');

    // The plugin must resolve these overrides lazily, after host bootstrap registrations.
    $capture = new InMemoryExporter();
    $logs    = new RecordingLogExporter();
    $container->set(MetricExporterInterface::class, $capture);
    $container->set(LogExporterInterface::class, $logs);

    monitor('orders.created', ['channel' => 'web'])->increment();
    monitor('queue.depth')->set(42);
    $result = monitor('checkout.duration')->measure(static fn() => 'result');

    check($result === 'result', 'measure() changed the application return value.');
    check(monitor() === $container->get(Monitor::class), 'Helper and constructor DI must share one monitor.');
    check(log() instanceof MonitoringLogger, 'PSR-3 decoration was not installed.');

    log()->info('Ordinary log {id}', ['id' => 7]);
    log()->warning('Below threshold');
    log()->error('Failed order {id}', ['id' => 42]);
    monitor()->flush();

    check(count($capture->measurements()) === 4, 'Metric overrides or log-level filter failed.');
    check($capture->measurements()[3]->labels === ['level' => 'error', 'service' => 'shop'], 'Log metric labels differ.');
    check(count($logs->records) === 1 && $logs->records[0]->context === ['id' => 42], 'Structured logging lost its context.');
    check(str_contains(file_get_contents($host . '/logs/app.log'), 'Ordinary log 7'), 'Original logger was filtered or replaced.');
    check(str_contains(file_get_contents($host . '/logs/app.log'), 'Failed order 42'), 'Original logger did not receive errors.');

    try {
        monitor(null, ['unused' => 'value']);
        throw new RuntimeException('Labels without a metric were accepted.');
    } catch (InvalidArgumentException) {
        // Expected API validation.
    }

    echo "NAF host discovery, config, lazy DI overrides, helper, timer and PSR-3 examples passed.\n";
} finally {
    removeHost($host);
}
