<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

/**
 * Worker SSE transport for frontend query streams.
 *
 * Must extend FrontendRestController so route generation registers
 * /api/framework/stream into frontend_rest_api (same as QueryBin).
 * Extending FrontendController incorrectly places the route in
 * frontend_pc, which the /api/ area never matches — EventSource then
 * receives a WLS SSE failed frame with HTTP 404 Not Found.
 */
class Stream extends FrontendRestController
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
            $events = $this->gateway->executeStream($streamTicket);
            $isRuntimeTaskStream = $this->isRuntimeTaskChannel((string)$streamTicket['channel']);

            $sse->start();
            if (!$isRuntimeTaskStream) {
                $sse->sendControlEvent('open', [
                    'channel' => $streamTicket['channel'],
                    'expires_at' => $streamTicket['expires_at'],
                ]);
            }

            foreach ($events as $event) {
                // Long-lived providers may yield this private transport marker
                // while polling. It becomes a throttled SSE comment, never a
                // business event or Last-Event-ID cursor.
                if ($this->isTransportHeartbeat($event)) {
                    $sse->maybeHeartbeat();
                    continue;
                }

                $normalized = $this->normalizeStreamEvent($event);
                if ($normalized['control']) {
                    $sse->sendControlEvent($normalized['event'], $normalized['data']);
                    continue;
                }

                if ($normalized['has_id']) {
                    $sse->sendEvent($normalized['event'], $normalized['data'], $normalized['id']);
                    continue;
                }

                $sse->sendEvent($normalized['event'], $normalized['data']);
            }

            // A runtime provider may intentionally rotate a subscription after
            // replay/polling. Its terminal state must therefore come only from
            // a persisted event, never from the HTTP stream ending.
            if ($isRuntimeTaskStream) {
                // Tell the StreamHandle to fetch a fresh ticket. Native
                // EventSource would otherwise auto-retry the consumed ticket
                // URL and leave the UI cursor stuck until the task finishes.
                $sse->sendControlEvent('runtime_rotate', ['ok' => true]);
            } else {
                // Keep the legacy "done" terminal name for non-runtime stream users,
                // but deliberately do not advance Last-Event-ID.
                $sse->sendControlEvent('done', ['ok' => true]);
            }
            $sse->close();
        } catch (FrontendQueryException $exception) {
            $this->sendFailure($sse, $exception->getHttpStatus(), $exception->getErrorCode(), $exception->getMessage());
        } catch (\Throwable $throwable) {
            $this->sendFailure($sse, 500, 'business_error', $throwable->getMessage());
        }
    }

    private function assertSameOrigin(): void
    {
        $currentOrigin = $this->currentOrigin();
        $origin = (string)$this->request->getServer('HTTP_ORIGIN');
        if ($origin === '') {
            return;
        }

        if (\rtrim($origin, '/') !== $currentOrigin) {
            throw new FrontendQueryException('auth_error', 'Worker stream origin mismatch.', 401);
        }
    }

    private function currentOrigin(): string
    {
        $scheme = \strtolower(\trim(WelineEnv::getRequestScheme()));
        $host = RequestAuthority::current();
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new FrontendQueryException('protocol_error', 'Worker stream request authority is invalid.', 400);
        }

        return $scheme . '://' . $host;
    }

    /**
     * @return array{event:string, data:mixed, id:int|null, has_id:bool, control:bool}
     */
    private function normalizeStreamEvent(mixed $event): array
    {
        if (\is_array($event)) {
            $rawId = \array_key_exists('id', $event)
                ? $event['id']
                : (\array_key_exists('sequence', $event) ? $event['sequence'] : null);
            $id = $this->normalizeEventId($rawId);

            return [
                'event' => $this->normalizeEventName((string)($event['event'] ?? $event['type'] ?? 'message')),
                'data' => $event['data'] ?? $event,
                'id' => $id,
                'has_id' => $id !== null,
                'control' => ($event['control'] ?? false) === true,
            ];
        }

        return [
            'event' => 'message',
            'data' => $event,
            'id' => null,
            'has_id' => false,
            'control' => false,
        ];
    }

    private function sendFailure(SseWriter $sse, int $status, string $code, string $message): void
    {
        $sse->start();
        // Transport/auth failures must not reuse the business `failed` terminal.
        // Runtime observers treat `failed` as task death; a bad stream ticket or
        // origin check should only rotate the SSE window and resume the same task.
        $event = $this->isTransportFailure($status, $code) ? 'transport_error' : 'failed';
        $sse->sendControlEvent($event, [
            'code' => $code,
            'http_status' => $status,
            'message' => $message,
        ]);
        $sse->close();
    }

    private function isTransportFailure(int $status, string $code): bool
    {
        $normalized = \strtolower(\trim($code));

        return $status === 401
            || $normalized === 'auth_error'
            || $normalized === 'worker_store_unavailable';
    }

    private function normalizeEventName(string $event): string
    {
        return \preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $event) ? $event : 'message';
    }

    private function isTransportHeartbeat(mixed $event): bool
    {
        return \is_array($event) && ($event['transport'] ?? null) === 'heartbeat';
    }

    private function isRuntimeTaskChannel(string $channel): bool
    {
        return \str_starts_with($channel, 'runtime_task.');
    }

    private function normalizeEventId(mixed $id): ?int
    {
        if (\is_int($id)) {
            return $id >= 0 ? $id : null;
        }

        if (!\is_string($id) || !\preg_match('/^(?:0|[1-9][0-9]*)$/', $id)) {
            return null;
        }

        $normalized = (int)$id;
        return (string)$normalized === $id ? $normalized : null;
    }
}
