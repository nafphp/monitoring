<?php

declare(strict_types=1);

namespace Naf\Monitoring\Transport;

interface DatagramTransportInterface
{
    /** Send one whole datagram, or throw. Success does not acknowledge delivery. */
    public function send(string $datagram): void;
}
