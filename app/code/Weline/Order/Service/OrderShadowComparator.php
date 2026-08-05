<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\OrderPlan;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Pure OrderPlan shadow compare（TEST-P2D-05）.
 *
 * New side MUST only call plan(). The caller supplies authoritative monotonic
 * counters for DML/lock/reservation/outbox/cache so the result cannot be made
 * green merely by checking OrderFacade's in-memory row count.
 */
final class OrderShadowComparator
{
    public const ERROR_DIFF = 'order_shadow_plan_diff';
    public const ERROR_NOT_SHADOW = 'order_shadow_mode_required';
    public const ERROR_EFFECT_SNAPSHOT = 'order_shadow_effect_snapshot_invalid';

    private const EFFECT_KEYS = ['dml', 'lock', 'reservation', 'outbox', 'cache'];

    public function __construct(
        private readonly OrderFacadeInterface $facade,
        private readonly OrderWriterGuard $guard,
    ) {
    }

    public static function forTesting(
        ?OrderFacadeInterface $facade = null,
        ?OrderWriterGuard $guard = null,
    ): self {
        $facade ??= OrderFacade::forTesting();
        $guard ??= new OrderWriterGuard();
        $guard->gate()->setMode(OrderCutoverGate::MODE_SHADOW);
        return new self($facade, $guard);
    }

    /**
     * @param callable(CreateCheckoutGroupCommand): OrderPlan|array $legacyPlanner
     *        Legacy writer/planner path. It runs after the new-side effect
     *        snapshot closes, so its expected fact is not charged to new plan.
     * @param callable(): array{dml:int,lock:int,reservation:int,outbox:int,cache:int} $newEffectSnapshot
     * @return array{
     *   equal:bool,
     *   mode:string,
     *   new_writes:int,
     *   new_effects:array{dml:int,lock:int,reservation:int,outbox:int,cache:int},
     *   new_plan:array<string,mixed>,
     *   legacy_plan:array<string,mixed>,
     *   diff:list<string>
     * }
     */
    public function compare(
        CreateCheckoutGroupCommand $command,
        callable $legacyPlanner,
        callable $newEffectSnapshot,
    ): array {
        if (!$this->guard->gate()->isShadow()) {
            throw new OrderFacadeConflictException(
                self::ERROR_NOT_SHADOW,
                \__('OrderPlan 影子比对只允许在 shadow mode 执行'),
                ['mode' => $this->guard->gate()->mode()],
            );
        }

        $effectsBefore = $this->snapshotEffects($newEffectSnapshot);
        $newPlan = $this->facade->plan($command);
        $effectsAfter = $this->snapshotEffects($newEffectSnapshot);
        $newEffects = [];
        foreach (self::EFFECT_KEYS as $key) {
            $newEffects[$key] = $effectsAfter[$key] - $effectsBefore[$key];
        }

        $legacyRaw = $legacyPlanner($command);
        $legacyPlan = $legacyRaw instanceof OrderPlan
            ? $legacyRaw
            : $this->arrayToPlan($legacyRaw, $command);

        $diff = $this->diffNormalized(
            $this->normalize($newPlan),
            $this->normalize($legacyPlan),
        );
        foreach ($newEffects as $key => $delta) {
            if ($delta !== 0) {
                $diff[] = 'new_side_effect:' . $key;
            }
        }

        return [
            'equal' => $diff === [],
            'mode' => $this->guard->gate()->mode(),
            'new_writes' => $newEffects['dml'],
            'new_effects' => $newEffects,
            'new_plan' => $newPlan->toArray(),
            'legacy_plan' => $legacyPlan->toArray(),
            'diff' => array_values(array_unique($diff)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToPlan(array $data, CreateCheckoutGroupCommand $command): OrderPlan
    {
        foreach (['orders', 'totals', 'warnings'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                throw new OrderFacadeConflictException(
                    self::ERROR_DIFF,
                    \__('影子计划字段 %{1} 必须是数组', [$key]),
                    ['field' => $key],
                );
            }
        }

        return new OrderPlan(
            currency: (string)($data['currency'] ?? $command->currency),
            websiteId: (int)($data['website_id'] ?? $command->websiteId),
            storeId: (int)($data['store_id'] ?? $command->storeId),
            orders: $data['orders'] ?? [],
            totals: $data['totals'] ?? [],
            shippingChargeOwnerIndex: isset($data['shipping_charge_owner_index'])
                ? (int) $data['shipping_charge_owner_index']
                : null,
            warnings: $data['warnings'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(OrderPlan $plan): array
    {
        $orders = [];
        $shippingOwnerSplitKey = null;
        foreach ($plan->orders as $order) {
            $items = [];
            foreach (($order['items'] ?? []) as $item) {
                $items[] = [
                    'offer_id' => isset($item['offer_id']) ? (int) $item['offer_id'] : null,
                    'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'sku' => isset($item['sku']) ? (string) $item['sku'] : null,
                    'name' => (string)($item['name'] ?? ''),
                    'qty_minor' => (int)($item['qty_minor'] ?? 0),
                    'unit_price_minor' => (int)($item['unit_price_minor'] ?? 0),
                    'row_total_minor' => (int)($item['row_total_minor'] ?? 0),
                    'requires_shipping' => (bool)($item['requires_shipping'] ?? true),
                ];
            }
            usort(
                $items,
                static fn (array $a, array $b): int => strcmp(
                    json_encode($a, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    json_encode($b, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ),
            );

            $splitKey = (string)($order['split_key'] ?? '');
            $isOwner = (bool)($order['is_shipping_charge_owner'] ?? false);
            if ($isOwner) {
                $shippingOwnerSplitKey = $splitKey;
            }
            $orders[] = [
                'split_key' => $splitKey,
                'items' => $items,
                'subtotal_minor' => (int)($order['subtotal_minor'] ?? 0),
                'shipping_amount_minor' => (int)($order['shipping_amount_minor'] ?? 0),
                'tax_amount_minor' => (int)($order['tax_amount_minor'] ?? 0),
                'grand_total_minor' => (int)($order['grand_total_minor'] ?? 0),
                'requires_shipping' => (bool)($order['requires_shipping'] ?? false),
                'is_shipping_charge_owner' => $isOwner,
            ];
        }
        usort($orders, static fn (array $a, array $b): int => strcmp($a['split_key'], $b['split_key']));

        $warnings = array_map('strval', $plan->warnings);
        sort($warnings, SORT_STRING);

        return [
            'currency' => $plan->currency,
            'website_id' => $plan->websiteId,
            'store_id' => $plan->storeId,
            'orders' => $orders,
            'totals' => [
                'subtotal_minor' => (int)($plan->totals['subtotal_minor'] ?? 0),
                'shipping_amount_minor' => (int)($plan->totals['shipping_amount_minor'] ?? 0),
                'tax_amount_minor' => (int)($plan->totals['tax_amount_minor'] ?? 0),
                'grand_total_minor' => (int)($plan->totals['grand_total_minor'] ?? 0),
                'order_count' => (int)($plan->totals['order_count'] ?? count($orders)),
            ],
            'shipping_charge_owner_split_key' => $shippingOwnerSplitKey,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $legacy
     * @return list<string>
     */
    private function diffNormalized(array $new, array $legacy): array
    {
        if ($new === $legacy) {
            return [];
        }

        $diff = ['normalized_plan_mismatch'];
        foreach ([
            'currency',
            'website_id',
            'store_id',
            'orders',
            'totals',
            'shipping_charge_owner_split_key',
            'warnings',
        ] as $key) {
            if (($new[$key] ?? null) !== ($legacy[$key] ?? null)) {
                $diff[] = $key;
            }
        }
        return $diff;
    }

    /**
     * @param callable(): array<string, mixed> $snapshot
     * @return array{dml:int,lock:int,reservation:int,outbox:int,cache:int}
     */
    private function snapshotEffects(callable $snapshot): array
    {
        $raw = $snapshot();
        if (!is_array($raw)) {
            throw new OrderFacadeConflictException(
                self::ERROR_EFFECT_SNAPSHOT,
                \__('影子副作用快照必须返回数组'),
            );
        }

        $normalized = [];
        foreach (self::EFFECT_KEYS as $key) {
            if (!array_key_exists($key, $raw) || !is_int($raw[$key]) || $raw[$key] < 0) {
                throw new OrderFacadeConflictException(
                    self::ERROR_EFFECT_SNAPSHOT,
                    \__('影子副作用快照字段 %{1} 必须是非负整数', [$key]),
                    ['field' => $key],
                );
            }
            $normalized[$key] = $raw[$key];
        }

        /** @var array{dml:int,lock:int,reservation:int,outbox:int,cache:int} $normalized */
        return $normalized;
    }
}
