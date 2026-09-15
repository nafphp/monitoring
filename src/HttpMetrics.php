<?php

declare(strict_types=1);

namespace Naf\Monitoring;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

/** @internal Captures only the HTTP cycle observed through NAF's existing events. */
final class HttpMetrics
{
    private ?int $start = null;
    private string $method = 'OTHER';
    private string $route = 'unmatched';
    private ?int $status = null;
    private bool $exception = false;

    public function __construct(private readonly Monitor $monitor) {}

    public function start(ServerRequestInterface $request): void
    {
        $this->start     = hrtime(true);
        $this->method    = in_array($request->getMethod(), ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'], true)
            ? $request->getMethod() : 'OTHER';
        $this->route     = 'unmatched';
        $this->status    = null;
        $this->exception = false;
    }

    public function matched(?string $route): void
    {
        $this->route = $route === null || $route === '' ? 'unnamed'
            : (preg_match('/^[a-zA-Z0-9_.\/-]{1,80}$/D', $route) === 1 ? $route : 'other');
    }

    public function exception(): void
    {
        if ($this->start !== null && !$this->exception) {
            $this->exception = true;
            $this->monitor->metric('http.exceptions')->increment();
        }
    }

    public function response(ResponseInterface $response): void
    {
        $this->status = $response->getStatusCode();
    }

    public function finish(?ResponseInterface $response = null, bool $fatal = false, ?int $emittedStatus = null): void
    {
        if ($this->start === null) {
            return;
        }

        $end = hrtime(true);

        if ($response !== null) {
            $this->response($response);
        }

        if ($fatal) {
            $this->exception();
            $this->status ??= 500;
        }

        if ($emittedStatus !== null && $this->status !== null) {
            $this->status = $emittedStatus;
        }

        $start       = $this->start;
        $this->start = null;

        if ($this->status === null) {
            // An exit before any response was observed has no trustworthy status code.
            return;
        }

        $labels = ['method' => $this->method, 'route' => $this->route, 'status' => $this->status];

        $this->monitor->metric('http.requests', $labels)->increment();
        $this->monitor->metric('http.duration', $labels)->observe(($end - $start) / 1_000_000_000);
    }
}
