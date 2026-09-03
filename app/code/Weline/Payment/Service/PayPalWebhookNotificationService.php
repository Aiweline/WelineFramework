<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentTransaction;

/**
 * 将 PayPal 争议/欺诈/退款等 webhook 侧效应写入交易审计字段。
 */
final class PayPalWebhookNotificationService
{
    public function __construct(
        private readonly PayPalWebhookTransitionMapper $mapper,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $inbox
     */
    public function recordFromInbox(array $inbox): void
    {
        if (strtolower(trim((string) ($inbox['provider_code'] ?? ''))) !== 'paypal') {
            return;
        }

        $eventType = (string) ($inbox['event_type'] ?? '');
        if (!$this->mapper->isSideEffectNotification($eventType)) {
            return;
        }

        $payload = $this->decodePayload($inbox);
        $transaction = $this->findTransaction($inbox, $payload);
        if ($transaction === null) {
            return;
        }

        $callback = $transaction->getCallbackData();
        $entries = \is_array($callback['paypal_notifications'] ?? null) ? $callback['paypal_notifications'] : [];
        $entries[] = [
            'at' => date('c'),
            'inbox_code' => (string) ($inbox['inbox_code'] ?? ''),
            'provider_event_id' => (string) ($inbox['provider_event_id'] ?? ''),
            'event_type' => $eventType,
            'status_transition' => (string) ($inbox['status_transition'] ?? ''),
            'resource_id' => $this->extractResourceId($payload),
        ];
        $callback['paypal_notifications'] = $entries;
        $transaction->setCallbackData($callback)->save();
    }

    /**
     * @param array<string, mixed> $inbox
     * @param array<string, mixed> $payload
     */
    private function findTransaction(array $inbox, array $payload): ?PaymentTransaction
    {
        $candidates = array_values(array_unique(array_filter([
            trim((string) ($inbox['transaction_code'] ?? '')),
            $this->extractResourceId($payload),
            $this->extractCaptureIdFromResource($payload),
        ], static fn(string $v): bool => $v !== '')));

        /** @var PaymentTransaction $model */
        $model = $this->objectManager->getInstance(PaymentTransaction::class, [], false);
        foreach ($candidates as $candidate) {
            $model->reset()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $candidate)
                ->where(PaymentTransaction::schema_fields_METHOD_CODE, 'paypal')
                ->find()
                ->fetch();
            if ($model->getId()) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $inbox
     * @return array<string, mixed>
     */
    private function decodePayload(array $inbox): array
    {
        $raw = $inbox['payload_json'] ?? $inbox['raw_payload'] ?? null;
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractResourceId(array $payload): string
    {
        $resource = \is_array($payload['resource'] ?? null) ? $payload['resource'] : [];

        return trim((string) ($resource['id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractCaptureIdFromResource(array $payload): string
    {
        $resource = \is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $disputed = \is_array($resource['disputed_transactions'] ?? null) ? $resource['disputed_transactions'] : [];
        foreach ($disputed as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $captureId = trim((string) ($row['seller_transaction_id'] ?? $row['buyer_transaction_id'] ?? ''));
            if ($captureId !== '') {
                return $captureId;
            }
        }

        return '';
    }
}
