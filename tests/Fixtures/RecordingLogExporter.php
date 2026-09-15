<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Monitoring\LogExporterInterface;

final class RecordingLogExporter implements LogExporterInterface
{
    public array $records = [];

    public function export(array $records): void
    {
        array_push($this->records, ...$records);
    }
}
