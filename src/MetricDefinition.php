<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use InvalidArgumentException;

final readonly class MetricDefinition
{
    /** @param list<string> $labels Allowed per-measurement label names. */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $unit = '',
        public array $labels = [],
    ) {
        self::validateName($name);

        if (preg_match('/^[a-zA-Z0-9_\/]{0,24}$/D', $unit) !== 1) {
            throw new InvalidArgumentException('Invalid metric unit.');
        }

        if (!array_is_list($labels) || count($labels) > 16) {
            throw new InvalidArgumentException('Declare up to 16 unique label names.');
        }

        foreach ($labels as $label) {
            if (!is_string($label) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $label) !== 1) {
                throw new InvalidArgumentException('Invalid label name.');
            }
        }

        if (count(array_unique($labels)) !== count($labels)) {
            throw new InvalidArgumentException('Label names must be unique.');
        }
    }

    public static function validateName(string $name): void
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.]{0,119}$/D', $name) !== 1
            || in_array('__labels', explode('.', $name), true)) {
            throw new InvalidArgumentException('Invalid metric name; the __labels segment is reserved.');
        }
    }

    /** @param array<string, string|int|bool> $labels @return array<string, string> */
    public static function normalizeLabels(array $labels): array
    {
        if (count($labels) > 16) {
            throw new InvalidArgumentException('A measurement supports at most 16 labels.');
        }

        $normalized = [];

        foreach ($labels as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $key) !== 1
                || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new InvalidArgumentException('Labels require named string, integer or boolean values.');
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            if (preg_match('/^[a-zA-Z0-9_.\/-]{1,80}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Label values must contain 1–80 portable ASCII characters: letters, digits, _, ., / or -.');
            }

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
