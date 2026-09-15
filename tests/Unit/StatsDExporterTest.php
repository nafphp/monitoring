<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Naf\Monitoring\Exporters\StatsDExporter;
use Naf\Monitoring\Measurement;
use Naf\Monitoring\MetricDefinition;
use Naf\Monitoring\MetricType;
use Naf\Monitoring\Monitor;
use Naf\Monitoring\Transport\UdpTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\RecordingTransport;

final class StatsDExporterTest extends TestCase
{
    public function testClassicStatsDWireTypesTimeConversionAndNegativeGaugeReset(): void
    {
        $transport = new RecordingTransport();
        $exporter  = new StatsDExporter(prefix: 'shop', transport: $transport);
        $exporter->export([
            new Measurement(new MetricDefinition('orders', MetricType::Counter), 5),
            new Measurement(new MetricDefinition('depth', MetricType::Gauge), -7),
            new Measurement(new MetricDefinition('duration', MetricType::Histogram, 's'), 0.125),
            new Measurement(new MetricDefinition('duration_ms', MetricType::Histogram, 'ms'), 2.5),
        ]);

        self::assertSame(["shop.orders:5|c\nshop.depth:0|g\nshop.depth:-7|g\nshop.duration:125|ms\nshop.duration_ms:2.5|ms"], $transport->packets);
    }

    public function testClassicLabelsAreStableAndDoNotCollapseDistinctValues(): void
    {
        $transport = new RecordingTransport();
        $exporter  = new StatsDExporter(transport: $transport);
        $metric    = new MetricDefinition('orders', MetricType::Counter);
        $exporter->export([
            new Measurement($metric, 1, ['b' => 'x.y', 'a' => 'x/y']),
            new Measurement($metric, 1, ['a' => 'x/y', 'b' => 'x.y']),
            new Measurement($metric, 1, ['b' => 'x/y', 'a' => 'x.y']),
        ]);

        $lines = explode("\n", $transport->packets[0]);
        self::assertSame('orders.__labels.61.782f79.62.782e79:1|c', $lines[0]);
        self::assertSame($lines[0], $lines[1]);
        self::assertNotSame($lines[0], $lines[2]);
    }

    public function testDogStatsDTagsHistogramsAndUnits(): void
    {
        $transport = new RecordingTransport();
        $exporter  = new StatsDExporter(protocol: 'dogstatsd', transport: $transport);
        $exporter->export([
            new Measurement(new MetricDefinition('size', MetricType::Histogram, 'bytes'), 12, ['kind' => 'mail']),
            new Measurement(new MetricDefinition('duration', MetricType::Histogram, 's'), 0.125),
            new Measurement(new MetricDefinition('gauge', MetricType::Gauge), -2, ['env' => 'test']),
        ]);

        self::assertSame(["size:12|h|#kind:mail\nduration:0.125|h\ngauge:0|g|#env:test\ngauge:-2|g|#env:test"], $transport->packets);
    }

    public function testUnsupportedClassicHistogramFailsBeforeAnyMeasurements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Monitor(new StatsDExporter(), [new MetricDefinition('size', MetricType::Histogram, 'bytes')]);
    }

    public function testPacketsRespectLimitWithoutSplittingNegativeGaugePair(): void
    {
        $transport = new RecordingTransport();
        $exporter  = new StatsDExporter(maxDatagramBytes: 64, transport: $transport);
        $counter   = new MetricDefinition('long_counter_metric', MetricType::Counter);
        $gauge     = new MetricDefinition('long_gauge_metric', MetricType::Gauge);
        $exporter->export([new Measurement($counter, 1), new Measurement($counter, 1), new Measurement($gauge, -9)]);

        self::assertCount(2, $transport->packets);
        self::assertSame("long_gauge_metric:0|g\nlong_gauge_metric:-9|g", $transport->packets[1]);

        foreach ($transport->packets as $packet) {
            self::assertLessThanOrEqual(64, strlen($packet));
        }
    }

    public function testOversizeRecordSendsNothingAndReportsFailure(): void
    {
        $transport = new RecordingTransport();
        $exporter  = new StatsDExporter(maxDatagramBytes: 64, transport: $transport);

        try {
            $exporter->export([
                new Measurement(new MetricDefinition('small', MetricType::Counter), 1),
                new Measurement(new MetricDefinition(str_repeat('x', 80), MetricType::Counter), 1),
            ]);
            self::fail('Oversize record was accepted.');
        } catch (RuntimeException) {
            self::assertSame([], $transport->packets);
        }
    }

    public function testNoopBatchDoesNotOpenSocketOrSend(): void
    {
        $transport = new RecordingTransport();
        (new StatsDExporter(transport: $transport))->export([]);
        self::assertSame([], $transport->packets);
    }

    public function testActualUdpDatagramArrivesOnLoopback(): void
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
        self::assertIsResource($server, $message);

        try {
            $port     = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
            $exporter = new StatsDExporter(port: $port, protocol: 'dogstatsd');
            $exporter->export([new Measurement(new MetricDefinition('orders', MetricType::Counter), 1, ['channel' => 'web'])]);

            stream_set_timeout($server, 2);
            self::assertSame('orders:1|c|#channel:web', stream_socket_recvfrom($server, 2048));
        } finally {
            fclose($server);
        }
    }

    public function testDnsAndInvalidTimeoutsAreRejectedBeforeIo(): void
    {
        foreach ([['localhost', 8125, 0.05], ['127.0.0.1', 0, 0.05], ['127.0.0.1', 8125, INF]] as [$host, $port, $timeout]) {
            try {
                new UdpTransport($host, $port, $timeout);
                self::fail('Unsafe UDP configuration accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
