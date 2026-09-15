<?php

declare(strict_types=1);

use Naf\Monitoring\Exporters\StatsDExporter;

return ['monitoring' => [
    'exporter' => StatsDExporter::class,
    'exporter_options' => [
        'host' => '127.0.0.1',
        'port' => (int) getenv('MONITORING_TEST_PORT'),
        'protocol' => 'dogstatsd',
    ],
    'http' => true,
    'logging' => ['enabled' => true, 'min_level' => 'warning'],
    'metrics' => ['orders.created' => ['type' => 'counter', 'labels' => ['channel']]],
]];
