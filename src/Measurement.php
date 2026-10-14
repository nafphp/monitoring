<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use InvalidArgumentException;

final readonly class Measurement
{
    public array $labels;

    /** @param array<string, string|int|bool> $labels */
    public function __construct(
        public MetricDefinition $definition,
        public int|float $value,
        array $labels = [],
    ) {
        if (!is_finite((float) $value) || ($definition->type !== MetricType::Gauge && $value < 0)) {
            throw new InvalidArgumentException('Measurements must be finite; only gauges accept negative values.');
        }

        $this->labels = MetricDefinition::normalizeLabels($labels);
    }
}
