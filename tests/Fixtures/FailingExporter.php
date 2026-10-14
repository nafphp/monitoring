<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Monitoring\MetricDefinition;
use Naf\Monitoring\MetricExporterInterface;
use RuntimeException;

final class FailingExporter implements MetricExporterInterface
{
    public int $attempts = 0;

    public function validate(MetricDefinition $definition): void
    {
    }

    public function export(array $measurements): void
    {
        ++$this->attempts;
        throw new RuntimeException('Collector unavailable');
    }
}
