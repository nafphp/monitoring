<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Monitoring\Exporters\InMemoryExporter;
use Naf\Monitoring\HttpMetrics;
use Naf\Monitoring\MetricDefinition;
use Naf\Monitoring\MetricExporterInterface;
use Naf\Monitoring\Monitor;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class HttpMetricsTest extends TestCase
{
    public function testFinalResponseWinsAndShutdownDoesNotCountTwice(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter);
        $http     = new HttpMetrics($monitor);
        $http->start(new ServerRequest('GET', '/users/42'));
        $http->matched('users.show');
        $http->response(new Response(200));
        $http->finish(new Response(201));
        $http->finish();
        $monitor->flush();

        self::assertCount(2, $exporter->measurements());
        self::assertSame(['method' => 'GET', 'route' => 'users.show', 'status' => '201'], $exporter->measurements()[0]->labels);
    }

    public function testNextRequestResetsRouteAndExceptionState(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter);
        $http     = new HttpMetrics($monitor);
        $http->start(new ServerRequest('GET', '/one'));
        $http->matched('one');
        $http->exception();
        $http->exception();
        $http->finish(new Response(500));
        $http->start(new ServerRequest('CUSTOM', '/two'));
        $http->exception();
        $http->finish(new Response(404));
        $monitor->flush();

        $measurements = $exporter->measurements();
        self::assertCount(6, $measurements);
        self::assertSame('http.exceptions', $measurements[3]->definition->name);
        self::assertSame(['method' => 'OTHER', 'route' => 'unmatched', 'status' => '404'], $measurements[4]->labels);
    }

    public function testShutdownUsesObservedResponseOrFatalFallback(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter);
        $http     = new HttpMetrics($monitor);
        $http->start(new ServerRequest('GET', '/error'));
        $http->finish(fatal: true);
        $http->finish(fatal: true);
        $monitor->flush();

        self::assertCount(3, $exporter->measurements());
        self::assertSame('500', $exporter->measurements()[1]->labels['status']);
    }

    public function testUnknownEarlyExitDoesNotInventStatus(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter);
        $http     = new HttpMetrics($monitor);
        $http->start(new ServerRequest('GET', '/early-exit'));
        $http->finish();
        $monitor->flush();

        self::assertSame([], $exporter->measurements());
    }

    public function testDurationIsCapturedBeforeThresholdFlushAtResponseEnd(): void
    {
        $exporter = new class implements MetricExporterInterface {
            public ?int $exportStart   = null;
            public array $measurements = [];

            public function validate(MetricDefinition $definition): void
            {
            }

            public function export(array $measurements): void
            {
                $this->exportStart ??= hrtime(true);
                usleep(5000);
                array_push($this->measurements, ...$measurements);
            }
        };

        $monitor = new Monitor($exporter, batchSize: 1);
        $http    = new HttpMetrics($monitor);
        $before  = hrtime(true);
        $http->start(new ServerRequest('GET', '/'));
        $http->finish(new Response(200));

        self::assertCount(2, $exporter->measurements);
        self::assertLessThanOrEqual(($exporter->exportStart - $before) / 1_000_000_000, $exporter->measurements[1]->value);
    }

    public function testShutdownPrefersEmittedErrorStatusAfterAnObservedSuccess(): void
    {
        $exporter = new InMemoryExporter();
        $monitor  = new Monitor($exporter);
        $http     = new HttpMetrics($monitor);
        $http->start(new ServerRequest('GET', '/'));
        $http->response(new Response(200));
        $http->finish(emittedStatus: 500);
        $monitor->flush();

        self::assertSame('500', $exporter->measurements()[0]->labels['status']);
    }
}
