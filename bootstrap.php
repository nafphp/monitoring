<?php

declare(strict_types=1);

use Naf\Core\Event;
use Naf\Monitoring\{HttpMetrics, LogExporterInterface, MetricDefinition, MetricExporterInterface, MetricType, Monitor, MonitoringLogger};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Log\LoggerInterface;
use function Naf\{app, config, event, route};

$container = app()->container();

if (!$container->has(MetricExporterInterface::class)) {
    $container->set(MetricExporterInterface::class, static function () use ($container): MetricExporterInterface {
        return $container->make(config('monitoring:exporter'), config('monitoring:exporter_options', []));
    });
}

if (!$container->has(LogExporterInterface::class) && config('monitoring:log_exporter') !== null) {
    $container->set(LogExporterInterface::class, static function () use ($container): LogExporterInterface {
        return $container->make(config('monitoring:log_exporter'), config('monitoring:log_exporter_options', []));
    });
}

if (!$container->has(Monitor::class)) {
    $container->set(Monitor::class, static function () use ($container): Monitor {
        $definitions = [];

        foreach (config('monitoring:metrics', []) as $name => $options) {
            $definitions[] = new MetricDefinition(
                $name,
                MetricType::from($options['type']),
                $options['unit'] ?? '',
                $options['labels'] ?? [],
            );
        }

        $onError = config('monitoring:on_error');

        return new Monitor(
            $container->get(MetricExporterInterface::class),
            $definitions,
            config('monitoring:labels', []),
            config('monitoring:batch_size', 256),
            $container->has(LogExporterInterface::class) ? $container->get(LogExporterInterface::class) : null,
            $onError === null
                ? static fn(Throwable $error, string $signal) => error_log("NAF monitoring $signal export failed: " . $error->getMessage())
                : Closure::fromCallable($onError),
        );
    });
}

if (config('monitoring:logging:enabled', false)) {
    $originalLogger = $container->get(LoggerInterface::class);

    $container->set(LoggerInterface::class, static fn() => new MonitoringLogger(
        $originalLogger,
        $container->get(Monitor::class),
        config('monitoring:logging:min_level', 'warning'),
        config('monitoring:logging:count', true),
    ));
}

$http = null;

if (config('monitoring:http', false)) {
    event()->listen(Event::REQUEST_START, static function (ServerRequestInterface $request) use ($container, &$http): void {
        $http ??= new HttpMetrics($container->get(Monitor::class));
        $http->start($request);
    }, PHP_INT_MAX);

    event()->listen(Event::ROUTE_MATCHED, static function () use (&$http): void {
        $http?->matched(route()->current());
    }, PHP_INT_MAX);

    event()->listen(Event::EXCEPTION, static function () use (&$http): void {
        $http?->exception();
    }, PHP_INT_MAX);

    event()->listen(Event::RESPONSE_SEND, static function (ResponseInterface $response) use (&$http): void {
        $http?->response($response);
    }, PHP_INT_MAX);
}

event()->listen(Event::RESPONSE_END, static function (ResponseInterface $response) use ($container, &$http): void {
    $http?->finish($response);
    $container->get(Monitor::class)->flush();
}, PHP_INT_MIN);

// Covers CLI scripts and responses that bypass or interrupt RESPONSE_END.
register_shutdown_function(static function () use ($container, &$http): void {
    try {
        $error = error_get_last();
        $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);

        $emittedStatus = http_response_code();
        $http?->finish(fatal: $fatal, emittedStatus: is_int($emittedStatus) ? $emittedStatus : null);
        $container->get(Monitor::class)->flush();
    } catch (Throwable $error) {
        error_log('NAF monitoring shutdown failed: ' . $error->getMessage());
    }
});
