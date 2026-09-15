<?php

declare(strict_types=1);

use Naf\Core\Event;
use Psr\Http\Message\ResponseInterface;
use function Naf\{event, json, log, route};
use function Naf\Monitoring\monitor;

route()->add('GET', '/ok/{id}', static function (string $id): ResponseInterface {
    monitor('orders.created', ['channel' => 'web'])->increment();
    log()->info('Below the monitoring threshold');
    log()->warning('Inventory low');
    return json(['id' => $id]);
}, 'orders.show');

route()->add('GET', '/error', static fn() => throw new RuntimeException('Expected application failure'), 'failure');

route()->add('GET', '/late', static function (): ResponseInterface {
    event()->listen(Event::RESPONSE_END, static fn() => throw new RuntimeException('Expected late listener failure'));
    return json(['ok' => true]);
}, 'late');

route()->add('GET', '/changed', static function (): ResponseInterface {
    event()->listen(Event::RESPONSE_HEADER, static fn(ResponseInterface $response) => $response->withStatus(202));
    return json(['ok' => true]);
}, 'changed');

route()->add('GET', '/header-error', static function (): ResponseInterface {
    event()->listen(Event::RESPONSE_HEADER, static fn() => throw new RuntimeException('Expected header listener failure'));
    return json(['ok' => true]);
}, 'header-error');
