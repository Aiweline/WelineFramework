<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Order\BackendLifecycleStagePolicy;

use Weline\B2B\Extends\Module\Weline_Order\CommerceOrderType\TobCommerceOrderType;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\B2B\Service\HangStatusLabels;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\LifecycleStageView;
use Weline\Order\Api\OrderBackendLifecycleStagePolicyInterface;
use Weline\Order\Model\Order;

final class TobBackendLifecycleStagePolicy implements OrderBackendLifecycleStagePolicyInterface
{
    public function getOrderTypeCode(): string
    {
        return TobCommerceOrderType::CODE;
    }

    public function supports(array $orderRow): bool
    {
        return $this->hangStatus($orderRow) !== '';
    }

    public function present(array $orderRow): ?LifecycleStageView
    {
        $status = $this->hangStatus($orderRow);
        if ($status === '') {
            return null;
        }
        $reviewRequired = $this->merchantReviewRequired($orderRow);
        if ($status === B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL
            || $status === B2BOrderHang::STATUS_REJECTED
        ) {
            $reviewRequired = true;
        }
        $path = $this->pathFor($status, $reviewRequired);
        $steps = [];
        $seen = false;
        foreach ($path as $code) {
            if ($code === $status) {
                $state = 'current';
                $seen = true;
            } elseif (!$seen) {
                $state = 'done';
            } else {
                $state = 'upcoming';
            }
            $steps[] = [
                'code' => $code,
                'label' => HangStatusLabels::label($code, backendPaymentComplete: true),
                'state' => $state,
                'tone' => $state === 'upcoming' ? '' : HangStatusLabels::tone($code),
            ];
        }

        return new LifecycleStageView(
            title: (string)__('批发支付阶段'),
            currentCode: $status,
            currentLabel: HangStatusLabels::label($status, backendPaymentComplete: true),
            tone: HangStatusLabels::tone($status),
            steps: $steps,
            actions: $this->actionsFor($status, $orderRow),
        );
    }

    /**
     * @param array<string, mixed> $orderRow
     * @return list<array{code: string, label: string, tone: string, path: string, params: array<string, string>, testid: string}>
     */
    private function actionsFor(string $status, array $orderRow): array
    {
        if ($status !== B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL) {
            return [];
        }
        $hangKey = $this->hangKey($orderRow);
        $orderId = trim((string)($orderRow[Order::schema_fields_ID] ?? $orderRow['order_id'] ?? $orderRow['id'] ?? ''));
        if ($hangKey === '' || $orderId === '' || !ctype_digit($orderId)) {
            return [];
        }
        $params = [
            'hang_id' => $hangKey,
            'order_id' => $orderId,
        ];

        return [
            [
                'code' => 'approve',
                'label' => (string)__('通过审查'),
                'tone' => 'primary',
                'path' => 'b2b/backend/control-center/approve-hang-order',
                'params' => $params,
                'testid' => 'order-edit-hang-approve',
            ],
            [
                'code' => 'reject',
                'label' => (string)__('驳回'),
                'tone' => 'danger',
                'path' => 'b2b/backend/control-center/reject-hang-order',
                'params' => $params,
                'testid' => 'order-edit-hang-reject',
            ],
        ];
    }

    /** @param array<string, mixed> $orderRow */
    private function hangKey(array $orderRow): string
    {
        $payload = $orderRow[Order::schema_fields_TYPE_PAYLOAD_JSON] ?? $orderRow['type_payload'] ?? null;
        if (\is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($payload)) {
            $fromPayload = trim((string)($payload['hang_id'] ?? $payload['hang_uuid'] ?? ''));
            if ($fromPayload !== '') {
                return $fromPayload;
            }
        }
        $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_UUID] ?? $orderRow['order_uuid'] ?? ''));
        if ($orderRef === '') {
            $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_NUMBER] ?? $orderRow['order_number'] ?? ''));
        }

        return $orderRef;
    }

    /** @param array<string, mixed> $orderRow */
    private function hangStatus(array $orderRow): string
    {
        $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_UUID] ?? $orderRow['order_uuid'] ?? ''));
        if ($orderRef === '') {
            $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_NUMBER] ?? $orderRow['order_number'] ?? ''));
        }
        if ($orderRef !== '') {
            try {
                $svc = ObjectManager::getInstance(B2BHangOrderService::class);
                if ($svc instanceof B2BHangOrderService) {
                    $fromHang = strtolower(trim((string)($svc->typePayloadForOrder($orderRef)['hang_status'] ?? '')));
                    if ($fromHang !== '') {
                        return $fromHang;
                    }
                }
            } catch (\Throwable) {
                // fall through to order type_payload
            }
        }
        $payload = $orderRow[Order::schema_fields_TYPE_PAYLOAD_JSON] ?? $orderRow['type_payload'] ?? null;
        if (\is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($payload)) {
            return strtolower(trim((string)($payload['hang_status'] ?? '')));
        }

        return '';
    }

    /** @param array<string, mixed> $orderRow */
    private function merchantReviewRequired(array $orderRow): bool
    {
        $payload = $orderRow[Order::schema_fields_TYPE_PAYLOAD_JSON] ?? $orderRow['type_payload'] ?? null;
        if (\is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($payload) && array_key_exists('merchant_review_required', $payload)) {
            return (bool)$payload['merchant_review_required'];
        }
        $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_UUID] ?? $orderRow['order_uuid'] ?? ''));
        if ($orderRef === '') {
            $orderRef = trim((string)($orderRow[Order::schema_fields_ORDER_NUMBER] ?? $orderRow['order_number'] ?? ''));
        }
        if ($orderRef !== '') {
            try {
                $svc = ObjectManager::getInstance(B2BHangOrderService::class);
                if ($svc instanceof B2BHangOrderService) {
                    $tp = $svc->typePayloadForOrder($orderRef);
                    if (array_key_exists('merchant_review_required', $tp)) {
                        return (bool)$tp['merchant_review_required'];
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        // Legacy hangs / missing snapshot: keep full review path.
        return true;
    }

    /** @return list<string> */
    private function pathFor(string $status, bool $merchantReviewRequired = true): array
    {
        if ($status === B2BOrderHang::STATUS_REJECTED) {
            return [
                B2BOrderHang::STATUS_AWAITING_DEPOSIT,
                B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
                B2BOrderHang::STATUS_REJECTED,
            ];
        }
        if ($status === B2BOrderHang::STATUS_EXPIRED) {
            return [
                B2BOrderHang::STATUS_AWAITING_DEPOSIT,
                B2BOrderHang::STATUS_EXPIRED,
            ];
        }
        if (!$merchantReviewRequired) {
            return [
                B2BOrderHang::STATUS_AWAITING_DEPOSIT,
                B2BOrderHang::STATUS_AWAITING_BALANCE,
                B2BOrderHang::STATUS_COMPLETED,
            ];
        }

        return [
            B2BOrderHang::STATUS_AWAITING_DEPOSIT,
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
            B2BOrderHang::STATUS_AWAITING_BALANCE,
            B2BOrderHang::STATUS_COMPLETED,
        ];
    }
}
