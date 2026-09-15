<?php

declare(strict_types=1);

namespace Naf\Monitoring\Exporters;

use InvalidArgumentException;
use Naf\Monitoring\Measurement;
use Naf\Monitoring\MetricDefinition;
use Naf\Monitoring\MetricExporterInterface;
use OverflowException;

final class InMemoryExporter implements MetricExporterInterface
{
    /** @var list<Measurement> */
    private array $measurements = [];

    public function __construct(private readonly int $capacity = 10000)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capture capacity must be positive.');
        }
    }

    public function validate(MetricDefinition $definition): void
    {
    }

    public function export(array $measurements): void
    {
        if (count($this->measurements) + count($measurements) > $this->capacity) {
            throw new OverflowException('In-memory metric capture is full; drain it between jobs/tests.');
        }

        array_push($this->measurements, ...$measurements);
    }

    /** @return list<Measurement> */
    public function measurements(): array
    {
        return $this->measurements;
    }

    /** @return list<Measurement> */
    public function drain(): array
    {
        $measurements       = $this->measurements;
        $this->measurements = [];

        return $measurements;
    }
}
