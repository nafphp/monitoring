<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$autoload = realpath($argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php');
$host     = sys_get_temp_dir() . '/naf-monitoring-http-' . bin2hex(random_bytes(12));

mkdir($host . '/app', 0700, true);
mkdir($host . '/public', 0700, true);

foreach (['app/config.php', 'app/routes.php', 'public/index.php'] as $file) {
    copy(__DIR__ . '/Fixtures/Http/' . $file, $host . '/' . $file);
}

$server  = null;
$process = null;

/** @return list<string> */
function receiveMetrics($server): array
{
    $lines = [];

    do {
        $read  = [$server];
        $write = $except = [];
        $ready = stream_select($read, $write, $except, 0, 150000);

        if ($ready === false) {
            throw new RuntimeException('UDP select failed.');
        }

        if ($ready > 0) {
            $packet = stream_socket_recvfrom($server, 65535);
            check(is_string($packet), 'UDP receive failed.');
            array_push($lines, ...explode("\n", $packet));
        }
    } while ($ready > 0);

    return $lines;
}

try {
    $server = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
    check(is_resource($server), 'Could not bind loopback UDP: ' . $message);
    $udpPort = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    foreach ([false, true] as $failureMode) {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        check(is_resource($reservation), 'Could not reserve HTTP port.');
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);

        $environment = array_merge(getenv(), [
            // Published framework 0.2.3 installs its global error handler in dev.
            'APP_ENV'                          => 'dev',
            'MONITORING_TEST_PORT'             => (string) $udpPort,
            'MONITORING_TEST_AUTOLOAD'         => $autoload,
            'MONITORING_TEST_FAILURE'          => $failureMode ? '1' : '0',
            'MONITORING_TEST_FAILING_EXPORTER' => __DIR__ . '/Fixtures/FailingExporter.php',
        ]);

        // Inherit a working PHP INI. -n is propagated when the parent was started with -n.
        $phpOptions = php_ini_loaded_file() === false ? ['-n'] : ['-c', php_ini_loaded_file()];
        $process    = proc_open([PHP_BINARY, ...$phpOptions, '-S', $address, '-t', $host . '/public'], [
            0 => ['pipe', 'r'],
            1 => ['file', $host . '/server.log', 'a'],
            2 => ['file', $host . '/server.log', 'a'],
        ], $pipes, $host, $environment);
        check(is_resource($process), 'Could not start PHP HTTP host.');
        fclose($pipes[0]);

        $ready = false;

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $probe = @stream_socket_client('tcp://' . $address, $code, $message, 0.05);

            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }

            usleep(20000);
        }

        check($ready, 'HTTP host did not start: ' . file_get_contents($host . '/server.log'));

        $cases = $failureMode ? ['/ok/7' => [200, 'orders.show']] : [
            '/ok/7'         => [200, 'orders.show'],
            '/error'        => [500, 'failure'],
            '/missing'      => [404, 'unmatched'],
            '/late'         => [200, 'late'],
            '/changed'      => [202, 'changed'],
            '/header-error' => [500, 'header-error'],
        ];

        foreach ($cases as $path => [$status, $route]) {
            $response = fopen('http://' . $address . $path, 'r', false, stream_context_create([
                'http' => ['ignore_errors' => true, 'timeout' => 5],
            ]));
            check(is_resource($response), 'HTTP request failed.');
            $headers = stream_get_meta_data($response)['wrapper_data'];
            $body    = stream_get_contents($response);
            fclose($response);
            check(str_contains($headers[0] ?? '', (string) $status), "Unexpected HTTP status for $path: " . ($headers[0] ?? 'none'));

            if ($path === '/ok/7') {
                check(json_decode($body, true) === ['id' => '7'], 'Monitoring changed the application response.');
            }

            $lines = receiveMetrics($server);

            if ($failureMode) {
                check($lines === [], 'Failing exporter unexpectedly sent UDP data.');
                continue;
            }

            $requestMetric = "http.requests:1|c|#method:GET,route:$route,status:$status";
            check(count(array_filter($lines, static fn($line) => $line === $requestMetric)) === 1, "Request missing or counted twice for $path: " . json_encode($lines));
            $durations = array_filter($lines, static fn($line) => str_starts_with($line, 'http.duration:'));
            check(count($durations) === 1, "Duration missing or counted twice for $path.");

            if ($path === '/ok/7') {
                check(in_array('orders.created:1|c|#channel:web', $lines, true), 'Business metric missing.');
                check(in_array('logs.total:1|c|#level:warning', $lines, true), 'Warning-level log metric missing.');
                check(!str_contains(implode("\n", $lines), 'level:info'), 'Below-threshold logs leaked into monitoring.');
            }

            if ($path === '/error' || $path === '/missing') {
                check(in_array('http.exceptions:1|c', $lines, true), 'NAF exception hook did not capture the exception.');
            }
        }

        proc_terminate($process);
        proc_close($process);
        $process = null;
    }

    check(str_contains(file_get_contents($host . '/server.log'), 'NAF monitoring metrics export failed'), 'Exporter failure was not diagnosed.');

    $environment['MONITORING_TEST_FAILURE'] = '0';
    $environment['MONITORING_TEST_HOST']    = $host;
    $process                                = proc_open([PHP_BINARY, ...$phpOptions, __DIR__ . '/Fixtures/cli.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $host . '/cli.log', 'a'], 2 => ['file', $host . '/cli.log', 'a'],
    ], $pipes, $host, $environment);
    check(is_resource($process), 'Could not start CLI host.');
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $process  = null;
    check($exitCode === 0, 'CLI host failed: ' . file_get_contents($host . '/cli.log'));
    check(receiveMetrics($server) === ['orders.created:1|c|#channel:cli'], 'CLI shutdown lost or duplicated pending metrics.');

    echo "Real HTTP/UDP: success, exception, 404, final status, late/header failures, shutdown deduplication, unavailable exporter and CLI shutdown passed.\n";
} catch (Throwable $error) {
    if (is_file($host . '/server.log')) {
        fwrite(STDERR, file_get_contents($host . '/server.log'));
    }

    throw $error;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    if (is_resource($server)) {
        fclose($server);
    }

    removeHost($host);
}
