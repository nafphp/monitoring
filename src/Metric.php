<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use LogicException;

final readonly class Metric
{
    /** @param array<string, string> $labels @internal Created by Monitor::metric(). */
    public function __construct(
        private Monitor $monitor,
        private MetricDefinition $definition,
        private array $labels,
    ) {}

    public function increment(int|float $amount = 1): void
    {
        $this->assertType(MetricType::Counter);
        $this->record($amount);
    }

    public function set(int|float $value): void
    {
        $this->assertType(MetricType::Gauge);
        $this->record($value);
    }

    public function observe(int|float $value): void
    {
        $this->assertType(MetricType::Histogram);
        $this->record($value);
    }

    /** Measure even a failed operation; preserve its result or exception. */
    public function measure(callable $operation): mixed
    {
        $this->assertType(MetricType::Histogram);

        if (!in_array($this->definition->unit, ['s', 'ms'], true)) {
            throw new LogicException('measure() requires a histogram declared in s or ms.');
        }

        $start = hrtime(true);

        try {
            return $operation();
        } finally {
            $divisor = $this->definition->unit === 's' ? 1_000_000_000 : 1_000_000;
            $this->record((hrtime(true) - $start) / $divisor);
        }
    }

    private function record(int|float $value): void
    {
        $this->monitor->record(new Measurement($this->definition, $value, $this->labels));
    }

    private function assertType(MetricType $type): void
    {
        if ($this->definition->type !== $type) {
            throw new LogicException("Metric '{$this->definition->name}' is {$this->definition->type->value}, not {$type->value}.");
        }
    }
}
