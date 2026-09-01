<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Payment\Model\PaymentDevRelayEvent;
use Weline\Payment\Model\PaymentDevRelaySession;

final class DevRelayStreamService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly DevRelayEventStore $events,
        private readonly DevRelayInboxPayloadService $payloads,
    ) {
    }

    public function stream(string $sessionCode, string $token, ?int $lastEventId = null): void
    {
        if (!$this->gate->canOpenUi()) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('Dev Relay 未启用。'));
            $sse->complete(['success' => false]);

            return;
        }

        try {
            $session = $this->sessions->requireActiveSession($sessionCode, $token);
        } catch (\Throwable $throwable) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError($throwable->getMessage());
            $sse->complete(['success' => false]);

            return;
        }

        $sse = new SseWriter();
        $sse->start();
        // 无 Last-Event-ID 时从 0 重放：避免 pair→写 state→loopback probe→再连 SSE 的竞态把刚注入事件跳过。
        // 可恢复客户端应带 Last-Event-ID / Last-Event-Id。
        $lastSeq = $lastEventId ?? 0;
        $sse->sendControlEvent('session.open', [
            'session_code' => $sessionCode,
            'last_seq' => $lastSeq,
            'role' => (string) $session->getData(PaymentDevRelaySession::schema_fields_ROLE),
            'local_inbound_url' => (string) $session->getData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL),
            'outbound_mode' => (string) $session->getData(PaymentDevRelaySession::schema_fields_OUTBOUND_MODE),
        ]);

        $sentSeq = $lastSeq;
        $deadline = time() + (int) $this->gate->config()['session_ttl_seconds'];
        while ($sse->isAlive() && time() < $deadline) {
            $rows = $this->events->listSince($sessionCode, $sentSeq);
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $seq = (int) ($row[PaymentDevRelayEvent::schema_fields_SEQ] ?? 0);
                $eventCode = (string) ($row[PaymentDevRelayEvent::schema_fields_EVENT_CODE] ?? '');
                $sse->sendEvent('webhook.relay', [
                    'event_code' => $eventCode,
                    'seq' => $seq,
                    'endpoint_code' => (string) ($row[PaymentDevRelayEvent::schema_fields_ENDPOINT_CODE] ?? ''),
                    'provider_code' => (string) ($row[PaymentDevRelayEvent::schema_fields_PROVIDER_CODE] ?? ''),
                    'provider_event_id' => (string) ($row[PaymentDevRelayEvent::schema_fields_PROVIDER_EVENT_ID] ?? ''),
                    'event_type' => (string) ($row[PaymentDevRelayEvent::schema_fields_EVENT_TYPE] ?? ''),
                    'fetch_url' => $this->sessions->buildEventFetchUrl($eventCode, $sessionCode, $token),
                    'local_inbound_url' => (string) $session->getData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL),
                ], $seq);
                $sentSeq = max($sentSeq, $seq);
            }

            $sse->maybeHeartbeat();
            // WLS 下必须用 SchedulerSystem::usleep，否则原生 usleep 会堵死 Worker，导致同机 fetch/probe 排队超时。
            SchedulerSystem::usleep(500_000);
        }

        $sse->sendControlEvent('session.closed', ['reason' => 'ttl_or_disconnect']);
        $sse->close();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchEventPayload(string $eventCode, string $sessionCode, string $token): array
    {
        $session = $this->sessions->requireActiveSession($sessionCode, $token);
        $event = $this->events->loadEvent($eventCode);
        if ($event === null) {
            throw new \RuntimeException((string) __('Relay 事件不存在。'));
        }
        if ((string) $event->getData(PaymentDevRelayEvent::schema_fields_SESSION_CODE) !== (string) $session->getData(PaymentDevRelaySession::schema_fields_SESSION_CODE)) {
            throw new \RuntimeException((string) __('Relay 事件与会话不匹配。'));
        }

        $payload = $this->payloads->loadFromInboxCode((string) $event->getData(PaymentDevRelayEvent::schema_fields_INBOX_CODE));

        return [
            'event_code' => $eventCode,
            'endpoint_code' => $payload['endpoint_code'],
            'provider_event_id' => $payload['provider_event_id'],
            'provider_code' => $payload['provider_code'],
            'event_type' => $payload['event_type'],
            'raw_body' => base64_encode($payload['raw_body']),
            'raw_body_encoding' => 'base64',
            'headers' => $payload['headers'],
            'signature' => $payload['signature'],
            'local_inbound_url' => (string) $session->getData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL),
        ];
    }


}
