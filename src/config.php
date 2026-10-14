<?php

declare(strict_types=1);

use Naf\Monitoring\Exporters\NullExporter;

return [
    'monitoring' => [
        'exporter'             => NullExporter::class,
        'exporter_options'     => [],
        'log_exporter'         => null,
        'log_exporter_options' => [],
        'labels'               => [],
        'batch_size'           => 256,
        'metrics'              => [],
        'http'                 => false,
        'on_error'             => null,
        'logging'              => [
            'enabled'   => false,
            'min_level' => 'warning',
            'count'     => true,
        ],
    ],
];
