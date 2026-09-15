<?php

declare(strict_types=1);

namespace Naf\Monitoring\Exporters;

use InvalidArgumentException;
use Naf\Monitoring\{MetricDefinition, MetricExporterInterface};
use RuntimeException;
use Throwable;

final readonly class CompositeExporter implements MetricExporterInterface
{
    /** @param list<MetricExporterInterface> $exporters */
    public function __construct(private array $exporters)
    {
        foreach ($exporters as $exporter) {
            if (!$exporter instanceof MetricExporterInterface) {
                throw new InvalidArgumentException('Every destination must implement MetricExporterInterface.');
            }
        }
    }

    public function validate(MetricDefinition $definition): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->validate($definition);
        }
    }

    public function export(array $measurements): void
    {
        $failure = null;

        foreach ($this->exporters as $exporter) {
            try {
                $exporter->export($measurements);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if ($failure !== null) {
            throw new RuntimeException('At least one metric destination failed: ' . $failure->getMessage(), 0, $failure);
        }
    }
}
