<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

final class MonitoringLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private bool $forwarding = false;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Monitor $monitor,
        private readonly string $minLevel = LogLevel::WARNING,
        private readonly bool $count = true,
    ) {
        self::validateLevel($minLevel);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        self::validateLevel($level);

        // The threshold only filters the monitoring copy, never the original logger.
        $this->inner->log($level, $message, $context);

        if ($this->forwarding || self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $this->forwarding = true;

        try {
            $this->monitor->recordLog($level, $message, $context);

            if ($this->count) {
                $this->monitor->metric('logs.total', ['level' => $level])->increment();
            }
        } finally {
            $this->forwarding = false;
        }
    }

    private static function validateLevel(mixed $level): void
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Use a valid PSR-3 log level.');
        }
    }
}
