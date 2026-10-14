<?php

declare(strict_types=1);

define('BASE_PATH', getenv('MONITORING_TEST_HOST'));
require getenv('MONITORING_TEST_AUTOLOAD');

use function Naf\app;
use function Naf\Monitoring\monitor;

app()->run();
monitor('orders.created', ['channel' => 'cli'])->increment();
// The pending record must be exported by the plugin's shutdown hook.
