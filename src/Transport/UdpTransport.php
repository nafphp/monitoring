<?php

declare(strict_types=1);

namespace Naf\Monitoring\Transport;

use InvalidArgumentException;
use RuntimeException;

final class UdpTransport implements DatagramTransportInterface
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8125,
        private readonly float $timeout = 0.05,
    ) {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('StatsD host must be a numeric IPv4/IPv6 address; resolve DNS outside the request.');
        }

        if ($port < 1 || $port > 65535 || !is_finite($timeout) || $timeout <= 0 || $timeout > 1) {
            throw new InvalidArgumentException('Invalid UDP port or timeout; use 1–65535 and a timeout in (0, 1] seconds.');
        }
    }

    public function send(string $datagram): void
    {
        if ($datagram === '' || strlen($datagram) > 65507) {
            throw new InvalidArgumentException('Invalid UDP payload size.');
        }

        if (!is_resource($this->socket)) {
            $host   = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
            $socket = @stream_socket_client(
                "udp://$host:{$this->port}",
                $errorCode,
                $errorMessage,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
            );

            if ($socket === false) {
                throw new RuntimeException("StatsD socket failed ($errorCode): $errorMessage");
            }

            if (!stream_set_blocking($socket, false)) {
                fclose($socket);
                throw new RuntimeException('Cannot make the StatsD socket non-blocking.');
            }

            $this->socket = $socket;
        }

        $written = @fwrite($this->socket, $datagram);

        if ($written !== strlen($datagram)) {
            fclose($this->socket);
            $this->socket = null;

            throw new RuntimeException('StatsD datagram was not completely accepted by the local socket.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
