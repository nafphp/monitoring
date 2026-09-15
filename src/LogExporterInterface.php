<?php

declare(strict_types=1);

namespace Naf\Monitoring;

interface LogExporterInterface
{
    /** @param list<LogRecord> $records Send once; throw on export failure. */
    public function export(array $records): void;
}
