<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use Closure;
use InvalidArgumentException;
use Stringable;
use Throwable;

final class Monitor
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];
    private readonly array $labels;
    /** @var list<Measurement> */
    private array $measurements = [];
    /** @var list<LogRecord> */
    private array $logs        = [];
    private bool $flushing     = false;
    private int $failedBatches = 0;

    /**
     * @param list<MetricDefinition> $definitions
     * @param array<string, string|int|bool> $labels Constant service/environment labels.
     * @param null|Closure(Throwable, string): void $onError Independent diagnostic callback.
     */
    public function __construct(
        private readonly MetricExporterInterface $exporter,
        array $definitions = [],
        array $labels = [],
        private readonly int $batchSize = 256,
        private readonly ?LogExporterInterface $logExporter = null,
        private readonly ?Closure $onError = null,
    ) {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 10000 records.');
        }

        $this->labels = MetricDefinition::normalizeLabels($labels);

        foreach (array_merge(self::builtInDefinitions(), $definitions) as $definition) {
            if (!$definition instanceof MetricDefinition) {
                throw new InvalidArgumentException('Expected MetricDefinition instances.');
            }

            if (isset($this->definitions[$definition->name])) {
                throw new InvalidArgumentException("Duplicate or reserved metric '{$definition->name}'.");
            }

            if (array_intersect(array_keys($this->labels), $definition->labels) !== []) {
                throw new InvalidArgumentException("Global labels overlap the labels of '{$definition->name}'.");
            }

            if (count($this->labels) + count($definition->labels) > 16) {
                throw new InvalidArgumentException('Global and metric labels together must not exceed 16.');
            }

            $exporter->validate($definition);
            $this->definitions[$definition->name] = $definition;
        }
    }

    /** @param array<string, string|int|bool> $labels */
    public function metric(string $name, array $labels = []): Metric
    {
        $definition = $this->definitions[$name] ?? throw new InvalidArgumentException("Undeclared metric '$name'.");
        $labels     = MetricDefinition::normalizeLabels($labels);

        if (array_diff(array_keys($labels), $definition->labels) !== []) {
            throw new InvalidArgumentException("Undeclared labels for metric '$name'.");
        }

        return new Metric($this, $definition, array_replace($this->labels, $labels));
    }

    /** @internal Called by Metric; each record is a delta/sample, never a cumulative counter. */
    public function record(Measurement $measurement): void
    {
        if ($this->flushing) {
            return;
        }

        if (($this->definitions[$measurement->definition->name] ?? null) !== $measurement->definition) {
            throw new InvalidArgumentException('The measurement must use this monitor\'s declared metric.');
        }

        $this->measurements[] = $measurement;
        $this->flushWhenFull();
    }

    /** @internal The PSR-3 decorator applies the level filter before calling this method. */
    public function recordLog(string $level, string|Stringable $message, array $context): void
    {
        if ($this->flushing || $this->logExporter === null) {
            return;
        }

        $this->logs[] = new LogRecord($level, $message, $context, $this->labels, microtime(true));
        $this->flushWhenFull();
    }

    /** Send pending records once. Failures are diagnosed and discarded, never retried. */
    public function flush(): void
    {
        if ($this->flushing || ($this->measurements === [] && $this->logs === [])) {
            return;
        }

        $measurements       = $this->measurements;
        $logs               = $this->logs;
        $this->measurements = [];
        $this->logs         = [];
        $this->flushing     = true;

        try {
            if ($measurements !== []) {
                $this->attempt(fn() => $this->exporter->export($measurements), 'metrics');
            }

            if ($logs !== [] && $this->logExporter !== null) {
                $this->attempt(fn() => $this->logExporter->export($logs), 'logs');
            }
        } finally {
            $this->flushing = false;
        }
    }

    public function failedBatches(): int
    {
        return $this->failedBatches;
    }

    private function flushWhenFull(): void
    {
        if (count($this->measurements) + count($this->logs) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function attempt(Closure $export, string $signal): void
    {
        try {
            $export();
        } catch (Throwable $error) {
            ++$this->failedBatches;

            try {
                ($this->onError ?? static function (): void {
                })($error, $signal);
            } catch (Throwable) {
                // A diagnostic failure must not replace the application's result either.
            }
        }
    }

    /** @return list<MetricDefinition> */
    private static function builtInDefinitions(): array
    {
        return [
            new MetricDefinition('logs.total', MetricType::Counter, labels: ['level']),
            new MetricDefinition('http.requests', MetricType::Counter, labels: ['method', 'route', 'status']),
            new MetricDefinition('http.duration', MetricType::Histogram, 's', ['method', 'route', 'status']),
            new MetricDefinition('http.exceptions', MetricType::Counter),
        ];
    }
}
