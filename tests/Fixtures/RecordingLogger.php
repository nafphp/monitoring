<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
