<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

class Stream extends FrontendController
{
    public function __construct(
        private readonly FrontendWorkerSessionService $sessionService,
        private readonly FrontendQueryGateway $gateway
    ) {
    }

    public function getIndex(): void
    {
        $sse = (new SseWriter())->setCorsOrigin(null);

        try {
            $this->assertSameOrigin();
            $ticket = (string)$this->request->getGet('ticket', '');
            $streamTicket = $this->sessionService->consumeStreamTicket($ticket);

            $sse->start();
            $sse->sendEvent('open', [
                'channel' => $streamTicket['channel'],
                'expires_at' => $streamTicket['expires_at'],
            ]);

            foreach ($this->gateway->executeStream($streamTicket) as $event) {
                $normalized = $this->normalizeStreamEvent($event);
                $sse->sendEvent($normalized['event'], $normalized['data']);
            }

            $sse->complete(['ok' => true]);
        } catch (FrontendQueryException $exception) {
            $this->sendFailure($sse, $exception->getHttpStatus(), $exception->getErrorCode(), $exception->getMessage());
        } catch (\Throwable $throwable) {
            $this->sendFailure($sse, 500, 'business_error', $throwable->getMessage());
        }
    }

    private function assertSameOrigin(): void
    {
        $origin = (string)$this->request->getServer('HTTP_ORIGIN');
        if ($origin === '') {
            return;
        }

        if (\rtrim($origin, '/') !== $this->currentOrigin()) {
            throw new FrontendQueryException('auth_error', 'Worker stream origin mismatch.', 401);
        }
    }

    private function currentOrigin(): string
    {
        $scheme = (string)$this->request->getServer('REQUEST_SCHEME');
        if ($scheme === '') {
            $https = (string)$this->request->getServer('HTTPS');
            $scheme = ($https !== '' && \strtolower($https) !== 'off') ? 'https' : 'http';
        }
        $host = (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost');

        return $scheme . '://' . $host;
    }

    /**
     * @return array{event:string, data:mixed}
     */
    private function normalizeStreamEvent(mixed $event): array
    {
        if (\is_array($event)) {
            return [
                'event' => $this->normalizeEventName((string)($event['event'] ?? $event['type'] ?? 'message')),
                'data' => $event['data'] ?? $event,
            ];
        }

        return [
            'event' => 'message',
            'data' => $event,
        ];
    }

    private function sendFailure(SseWriter $sse, int $status, string $code, string $message): void
    {
        $sse->start();
        $sse->sendEvent('failed', [
            'code' => $code,
            'http_status' => $status,
            'message' => $message,
        ]);
        $sse->close();
    }

    private function normalizeEventName(string $event): string
    {
        return \preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $event) ? $event : 'message';
    }
}
