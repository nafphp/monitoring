<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Monitoring\Transport\DatagramTransportInterface;

final class RecordingTransport implements DatagramTransportInterface
{
    public array $packets = [];

    public function send(string $datagram): void
    {
        $this->packets[] = $datagram;
    }
}
