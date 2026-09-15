<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require getenv('MONITORING_TEST_AUTOLOAD');

use Naf\Monitoring\MetricExporterInterface;
use function Naf\app;

if (getenv('MONITORING_TEST_FAILURE') === '1') {
    require getenv('MONITORING_TEST_FAILING_EXPORTER');
    app()->container()->set(MetricExporterInterface::class, new Tests\Fixtures\FailingExporter());
}

app()->run();
