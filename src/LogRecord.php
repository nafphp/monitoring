<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use Stringable;

final readonly class LogRecord
{
    /** @param array<string, mixed> $context @param array<string, string> $labels */
    public function __construct(
        public string $level,
        public string|Stringable $message,
        public array $context,
        public array $labels,
        public float $timestamp,
    ) {}
}
