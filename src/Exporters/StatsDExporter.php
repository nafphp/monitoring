<?php

declare(strict_types=1);

namespace Naf\Monitoring\Exporters;

use InvalidArgumentException;
use Naf\Monitoring\{Measurement, MetricDefinition, MetricExporterInterface, MetricType};
use Naf\Monitoring\Transport\{DatagramTransportInterface, UdpTransport};
use RuntimeException;

final class StatsDExporter implements MetricExporterInterface
{
    private readonly DatagramTransportInterface $transport;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 8125,
        private readonly string $prefix = '',
        private readonly string $protocol = 'statsd',
        private readonly int $maxDatagramBytes = 512,
        float $timeout = 0.05,
        ?DatagramTransportInterface $transport = null,
    ) {
        if ($prefix !== '') {
            MetricDefinition::validateName($prefix);
        }

        if (!in_array($protocol, ['statsd', 'dogstatsd'], true)) {
            throw new InvalidArgumentException('Protocol must be statsd or dogstatsd.');
        }

        if ($maxDatagramBytes < 64 || $maxDatagramBytes > 65507) {
            throw new InvalidArgumentException('Datagram size must be between 64 and 65507 bytes.');
        }

        $this->transport = $transport ?? new UdpTransport($host, $port, $timeout);
    }

    public function validate(MetricDefinition $definition): void
    {
        if ($this->protocol === 'statsd' && $definition->type === MetricType::Histogram
            && !in_array($definition->unit, ['s', 'ms'], true)) {
            throw new InvalidArgumentException("Plain StatsD requires histogram '{$definition->name}' in s or ms; use DogStatsD for other units.");
        }
    }

    public function export(array $measurements): void
    {
        $packets = [];
        $packet  = '';

        // Encode before sending so an invalid record cannot send half of a batch.
        foreach ($measurements as $measurement) {
            $lines = $this->encode($measurement);

            if (strlen($lines) > $this->maxDatagramBytes) {
                throw new RuntimeException('A StatsD measurement exceeds maxDatagramBytes; shorten labels or increase the configured limit.');
            }

            if ($packet !== '' && strlen($packet) + 1 + strlen($lines) > $this->maxDatagramBytes) {
                $packets[] = $packet;
                $packet    = '';
            }

            $packet .= ($packet === '' ? '' : "\n") . $lines;
        }

        if ($packet !== '') {
            $packets[] = $packet;
        }

        foreach ($packets as $datagram) {
            $this->transport->send($datagram);
        }
    }

    private function encode(Measurement $measurement): string
    {
        $definition = $measurement->definition;
        $this->validate($definition);

        $name  = ($this->prefix === '' ? '' : $this->prefix . '.') . $definition->name;
        $tags  = '';
        $value = $measurement->value;
        $type  = match ($definition->type) {
            MetricType::Counter => 'c',
            MetricType::Gauge => 'g',
            MetricType::Histogram => $this->protocol === 'dogstatsd' ? 'h' : 'ms',
        };

        if ($this->protocol === 'statsd' && $definition->type === MetricType::Histogram && $definition->unit === 's') {
            $value *= 1000;

            if (!is_finite((float) $value)) {
                throw new RuntimeException('StatsD seconds-to-milliseconds conversion overflowed.');
            }
        }

        if ($measurement->labels !== []) {
            if ($this->protocol === 'dogstatsd') {
                $pairs = [];

                foreach ($measurement->labels as $key => $label) {
                    $pairs[] = "$key:$label";
                }

                $tags = '|#' . implode(',', $pairs);
            } else {
                $name .= '.__labels';

                foreach ($measurement->labels as $key => $label) {
                    $name .= '.' . bin2hex($key) . '.' . bin2hex($label);
                }
            }
        }

        // json_encode is independent of the process locale and rejects non-finite values.
        $number = json_encode($value, JSON_THROW_ON_ERROR);
        $line   = "$name:$number|$type$tags";

        if ($definition->type === MetricType::Gauge && $value < 0) {
            // Keep the reset and negative delta in the same datagram.
            $line = "$name:0|g$tags\n$line";
        }

        return $line;
    }
}
