<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use InvalidArgumentException;

use function Naf\app;

/** Retrieve the monitoring service, or a declared metric with per-call labels. */
function monitor(?string $name = null, array $labels = []): Monitor|Metric
{
    $monitor = app()->container()->get(Monitor::class);

    if ($name === null && $labels !== []) {
        throw new InvalidArgumentException('Labels require a metric name.');
    }

    return $name === null ? $monitor : $monitor->metric($name, $labels);
}
