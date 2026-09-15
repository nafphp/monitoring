<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Monitoring\{LogExporterInterface, Monitor, MonitoringLogger};
use Naf\Monitoring\Exporters\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use Tests\Fixtures\{RecordingLogExporter, RecordingLogger};

final class MonitoringLoggerTest extends TestCase
{
    private const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    #[DataProvider('thresholds')]
    public function testEveryThresholdFiltersOnlyMonitoring(string $threshold, array $expected): void
    {
        $inner    = new RecordingLogger();
        $metrics  = new InMemoryExporter();
        $logs     = new RecordingLogExporter();
        $monitor  = new Monitor($metrics, labels: ['service' => 'shop'], logExporter: $logs);
        $logger   = new MonitoringLogger($inner, $monitor, $threshold);
        $error    = new RuntimeException('Original exception');

        foreach (self::LEVELS as $level) {
            $logger->log($level, 'Order {id} failed', ['id' => 42, 'exception' => $error]);
        }

        $monitor->flush();

        self::assertCount(8, $inner->records);
        self::assertSame($expected, array_column($logs->records, 'level'));
        self::assertSame($expected, array_map(static fn($record) => $record->labels['level'], $metrics->measurements()));
        self::assertSame('Order {id} failed', $logs->records[0]->message);
        self::assertSame(['id' => 42, 'exception' => $error], $logs->records[0]->context);
        self::assertSame(['service' => 'shop'], $logs->records[0]->labels);
        self::assertGreaterThan(0, $logs->records[0]->timestamp);
    }

    public static function thresholds(): array
    {
        $cases = [];

        foreach (self::LEVELS as $index => $level) {
            $cases[] = [$level, array_slice(self::LEVELS, $index)];
        }

        return $cases;
    }

    public function testLogExportCanBeUsedWithoutMetricCounting(): void
    {
        $metrics = new InMemoryExporter();
        $logs    = new RecordingLogExporter();
        $monitor = new Monitor($metrics, logExporter: $logs);
        $logger  = new MonitoringLogger(new RecordingLogger(), $monitor, count: false);
        $logger->warning('Problem');
        $monitor->flush();

        self::assertSame([], $metrics->measurements());
        self::assertCount(1, $logs->records);
    }

    public function testExporterLoggingAndRecursiveFlushCannotCreateFeedbackLoop(): void
    {
        $inner    = new RecordingLogger();
        $metrics  = new InMemoryExporter();
        $exporter = new class implements LogExporterInterface {
            public MonitoringLogger $logger;
            public Monitor $monitor;
            public int $calls = 0;

            public function export(array $records): void
            {
                ++$this->calls;
                $this->logger->error('Exporter internal failure');
                $this->monitor->flush();
                throw new RuntimeException('Export failed');
            }
        };

        $monitor = new Monitor($metrics, logExporter: $exporter, onError: static function () use (&$logger): void {
            $logger->error('Diagnostic');
        });
        $logger = new MonitoringLogger($inner, $monitor);
        $exporter->logger  = $logger;
        $exporter->monitor = $monitor;
        $logger->error('Application failure');
        $monitor->flush();
        $monitor->flush();

        self::assertSame(1, $exporter->calls);
        self::assertSame(1, $monitor->failedBatches());
        self::assertCount(3, $inner->records);
        self::assertCount(1, $metrics->measurements());
    }

    public function testInvalidLogLevelIsRejected(): void
    {
        $logger = new MonitoringLogger(new RecordingLogger(), new Monitor(new InMemoryExporter()));
        $this->expectException(InvalidArgumentException::class);
        $logger->log(['warning'], 'Invalid');
    }

    public function testInvalidThresholdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MonitoringLogger(new RecordingLogger(), new Monitor(new InMemoryExporter()), 'WARN');
    }
}
