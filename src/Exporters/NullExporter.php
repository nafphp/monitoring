<?php

declare(strict_types=1);

namespace Naf\Monitoring\Exporters;

use Naf\Monitoring\MetricDefinition;
use Naf\Monitoring\MetricExporterInterface;

final class NullExporter implements MetricExporterInterface
{
    public function validate(MetricDefinition $definition): void
    {
    }

    public function export(array $measurements): void
    {
    }
}
