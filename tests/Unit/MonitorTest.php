<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Naf\Monitoring\{Measurement, MetricDefinition, MetricType, Monitor};
use Naf\Monitoring\Exporters\{CompositeExporter, InMemoryExporter, NullExporter};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\{FailingExporter, RecordingLogExporter};

final class MonitorTest extends TestCase
{
    public function testMetricsPreserveDeltasUnitsAndIsolatePerCallLabels(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter, [
            new MetricDefinition('orders.created', MetricType::Counter, labels: ['channel']),
            new MetricDefinition('queue.depth', MetricType::Gauge),
            new MetricDefinition('duration', MetricType::Histogram, 's'),
        ], ['service' => 'shop'], batchSize: 10);

        $web = $monitor->metric('orders.created', ['channel' => 'web']);
        $monitor->metric('orders.created', ['channel' => 'cli'])->increment(5);
        $web->increment();
        $monitor->metric('orders.created')->increment(0);
        $monitor->metric('queue.depth')->set(-2);
        $monitor->metric('duration')->observe(0.125);

        self::assertSame([], $exporter->measurements());
        $monitor->flush();
        $monitor->flush();

        $records = $exporter->drain();
        self::assertSame([5, 1, 0, -2, 0.125], array_column($records, 'value'));
        self::assertSame(['channel' => 'cli', 'service' => 'shop'], $records[0]->labels);
        self::assertSame(['channel' => 'web', 'service' => 'shop'], $records[1]->labels);
        self::assertSame(['service' => 'shop'], $records[2]->labels);
        self::assertSame('s', $records[4]->definition->unit);
        self::assertSame([], $exporter->measurements());
    }

    public function testMeasurePreservesReturnValuesAndOriginalExceptionWhenExportFails(): void
    {
        $exporter = new FailingExporter();
        $monitor  = new Monitor($exporter, [new MetricDefinition('duration', MetricType::Histogram, 'ms')], batchSize: 1);
        $result   = new \stdClass();

        self::assertSame($result, $monitor->metric('duration')->measure(fn() => $result));

        $original = new RuntimeException('Business operation failed');

        try {
            $monitor->metric('duration')->measure(static fn() => throw $original);
            self::fail('The operation exception was lost.');
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
        }

        self::assertSame(2, $monitor->failedBatches());
        self::assertSame(2, $exporter->attempts);
    }

    public function testMeasuredDurationUsesMonotonicTimeAndDeclaredUnit(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter, [new MetricDefinition('duration', MetricType::Histogram, 'ms')]);
        $monitor->metric('duration')->measure(static fn() => usleep(2000));
        $monitor->flush();

        self::assertGreaterThanOrEqual(1.0, $exporter->measurements()[0]->value);
        self::assertLessThan(5000.0, $exporter->measurements()[0]->value);
    }

    public function testBufferFlushesAtThresholdAndBetweenJobsWithoutLabelLeakage(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter, [new MetricDefinition('jobs', MetricType::Counter, labels: ['queue'])], batchSize: 2);

        for ($job = 0; $job < 3; ++$job) {
            $monitor->metric('jobs', ['queue' => 'mail'])->increment();
            $monitor->metric('jobs')->increment();
            self::assertCount(2, $exporter->drain());
            $monitor->metric('jobs')->increment();
            $monitor->flush();
            self::assertSame([], $exporter->drain()[0]->labels);
        }
    }

    public function testFailureDoesNotRetryAndStillExportsLogsAndOtherDestinations(): void
    {
        $bad      = new FailingExporter();
        $good     = new InMemoryExporter();
        $logs     = new RecordingLogExporter();
        $problems = [];
        $monitor  = new Monitor(new CompositeExporter([$bad, $good]), logExporter: $logs,
            onError: static function ($error, $signal) use (&$problems): void {
                $problems[] = [$error->getMessage(), $signal];
                throw new RuntimeException('Diagnostic also failed');
            });

        $monitor->metric('logs.total', ['level' => 'error'])->increment();
        $monitor->recordLog('error', 'Problem', []);
        $monitor->flush();
        $monitor->flush();

        self::assertCount(1, $good->measurements());
        self::assertCount(1, $logs->records);
        self::assertSame(1, $bad->attempts);
        self::assertSame(1, $monitor->failedBatches());
        self::assertSame('metrics', $problems[0][1]);
    }

    public function testUnknownMetricsAndLabelsAreRejected(): void
    {
        $monitor = new Monitor(new NullExporter());

        foreach ([['missing', []], ['logs.total', ['message' => 'arbitrary']]] as [$name, $labels]) {
            try {
                $monitor->metric($name, $labels);
                self::fail('Invalid metric request was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWrongMetricOperationIsRejectedBeforeRunningCallback(): void
    {
        $monitor = new Monitor(new NullExporter());
        $this->expectException(LogicException::class);
        $monitor->metric('logs.total')->measure(static fn() => self::fail('Must not execute'));
    }

    public function testNonTimeHistogramCannotMeasure(): void
    {
        $monitor = new Monitor(new NullExporter(), [new MetricDefinition('size', MetricType::Histogram, 'bytes')]);
        $this->expectException(LogicException::class);
        $monitor->metric('size')->measure(static fn() => null);
    }

    #[DataProvider('invalidValues')]
    public function testInvalidNumericValuesAreRejected(MetricType $type, int|float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Measurement(new MetricDefinition('metric', $type), $value);
    }

    public static function invalidValues(): array
    {
        return [
            [MetricType::Counter, -1], [MetricType::Histogram, -0.1],
            [MetricType::Gauge, INF], [MetricType::Counter, NAN], [MetricType::Histogram, -INF],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testUnsafeNamesAreRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricDefinition($name, MetricType::Counter);
    }

    public static function invalidNames(): array
    {
        return [[''], ['orders:1|c'], ["orders\ninjected"], ['orders.__labels.x'], [str_repeat('a', 121)]];
    }

    #[DataProvider('invalidLabels')]
    public function testUnsafeOrUnboundedLabelsAreRejected(array $labels): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetricDefinition::normalizeLabels($labels);
    }

    public static function invalidLabels(): array
    {
        return [
            [['level' => "error\nother:1|c"]], [['level' => 'a,b']], [['level' => 'a:b']],
            [['level' => '']], [['level' => str_repeat('a', 81)]], [['level' => 1.2]],
            [['level' => []]], [[0 => 'error']], [['bad|key' => 'error']],
        ];
    }

    public function testBooleanLabelsAreStableAndSorted(): void
    {
        self::assertSame(['a' => 'false', 'b' => 'true', 'c' => '42'], MetricDefinition::normalizeLabels(['c' => 42, 'b' => true, 'a' => false]));
    }

    public function testReservedDefinitionsCannotBeRedefined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Monitor(new NullExporter(), [new MetricDefinition('logs.total', MetricType::Gauge)]);
    }

    public function testGlobalLabelsCannotOverrideMetricLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Monitor(new NullExporter(), labels: ['level' => 'info']);
    }
}
