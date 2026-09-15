<?php

declare(strict_types=1);

namespace Naf\Monitoring;

interface MetricExporterInterface
{
    /** Reject unsupported metric types/units before the first measurement. */
    public function validate(MetricDefinition $definition): void;

    /** @param list<Measurement> $measurements Send once; throw on export failure. */
    public function export(array $measurements): void;
}
