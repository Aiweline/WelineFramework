<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Resolves human-readable coupon source labels and backfills missing attribution.
 */
final class CouponSourceAttribution
{
    /**
     * @param array<string, mixed>|Coupon $coupon
     * @return array{
     *   source_module:string,
     *   source_type:string,
     *   source_id:string,
     *   source_key:string,
     *   label:string,
     *   tone:string
     * }
     */
    public function describe(array|Coupon $coupon): array
    {
        $data = $coupon instanceof Coupon ? $coupon->getData() : $coupon;
        $module = trim((string)($data[Coupon::schema_fields_SOURCE_MODULE] ?? ''));
        $type = trim((string)($data[Coupon::schema_fields_SOURCE_TYPE] ?? ''));
        $id = trim((string)($data[Coupon::schema_fields_SOURCE_ID] ?? ''));
        $key = trim((string)($data[Coupon::schema_fields_SOURCE_KEY] ?? ''));

        if ($type === '' || $type === Coupon::SOURCE_TYPE_MANUAL) {
            return [
                'source_module' => $module !== '' ? $module : Coupon::SOURCE_MODULE_MARKETING,
                'source_type' => Coupon::SOURCE_TYPE_MANUAL,
                'source_id' => $id,
                'source_key' => $key,
                'label' => (string)__('后台手工'),
                'tone' => 'neutral',
            ];
        }

        return [
            'source_module' => $module,
            'source_type' => $type,
            'source_id' => $id,
            'source_key' => $key,
            'label' => $this->labelForType($type, $module),
            'tone' => 'info',
        ];
    }

    public function labelForType(string $type, string $module = ''): string
    {
        $type = trim($type);

        return match ($type) {
            Coupon::SOURCE_TYPE_MANUAL, '' => (string)__('后台手工'),
            Coupon::SOURCE_TYPE_MAINTENANCE_WAIT_GIFT => (string)__('维护等待礼金'),
            'promotion_activity_theme' => (string)__('促销活动主题'),
            default => $module !== ''
                ? (string)__('%{1}（%{2}）', [$module, $type])
                : ($type !== '' ? $type : (string)__('未知来源')),
        };
    }

    /**
     * Filter options for backend coupon list (value => label).
     *
     * @return list<array{value:string,label:string}>
     */
    public function listFilterOptions(): array
    {
        $known = [
            Coupon::SOURCE_TYPE_MANUAL,
            Coupon::SOURCE_TYPE_MAINTENANCE_WAIT_GIFT,
            'promotion_activity_theme',
        ];

        try {
            /** @var Coupon $couponModel */
            $couponModel = ObjectManager::getInstance(Coupon::class, [], false);
            $couponModel
                ->clear()
                ->fields(Coupon::schema_fields_SOURCE_TYPE)
                ->group(Coupon::schema_fields_SOURCE_TYPE)
                ->select()
                ->fetch();
            foreach ($couponModel->getItems() ?: [] as $row) {
                $type = trim((string)($row[Coupon::schema_fields_SOURCE_TYPE] ?? $row['source_type'] ?? ''));
                if ($type === '' || in_array($type, $known, true)) {
                    continue;
                }
                $known[] = $type;
            }
        } catch (\Throwable) {
            // Group query best-effort; known options still available.
        }

        $options = [];
        foreach ($known as $value) {
            $options[] = [
                'value' => (string)$value,
                'label' => $this->labelForType((string)$value),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *   source_module:string,
     *   source_type:string,
     *   source_id:string,
     *   source_key:string
     * }
     */
    public function fromIssueContext(Rule $rule, array $context = []): array
    {
        $fromRule = $this->fromRule($rule);
        $ctxSource = trim((string)($context['source'] ?? $context['source_type'] ?? ''));
        $module = trim((string)($context['source_module'] ?? $fromRule['source_module']));
        $type = $ctxSource !== '' ? $ctxSource : $fromRule['source_type'];
        $id = trim((string)($context['source_id'] ?? $context['wave_id'] ?? $fromRule['source_id']));
        $key = trim((string)($context['source_key'] ?? $fromRule['source_key']));

        if ($type === '') {
            $type = Coupon::SOURCE_TYPE_MANUAL;
        }
        if ($module === '' && $type === Coupon::SOURCE_TYPE_MAINTENANCE_WAIT_GIFT) {
            $module = Coupon::SOURCE_MODULE_MAINTENANCE;
        }
        if ($module === '' && $type === Coupon::SOURCE_TYPE_MANUAL) {
            $module = Coupon::SOURCE_MODULE_MARKETING;
        }

        return [
            'source_module' => $module,
            'source_type' => $type,
            'source_id' => $id,
            'source_key' => $key,
        ];
    }

    /**
     * @return array{
     *   source_module:string,
     *   source_type:string,
     *   source_id:string,
     *   source_key:string
     * }
     */
    public function fromRule(Rule $rule): array
    {
        $actions = $rule->getActions() ?? [];
        if ($actions !== [] && is_array($actions[0] ?? null)) {
            $action = $actions[0];
            if (!empty($action['external_managed'])
                || trim((string)($action['source_type'] ?? '')) !== ''
            ) {
                return [
                    'source_module' => trim((string)($action['source_module'] ?? '')),
                    'source_type' => trim((string)($action['source_type'] ?? '')),
                    'source_id' => trim((string)($action['source_id'] ?? '')),
                    'source_key' => trim((string)($action['source_key'] ?? '')),
                ];
            }
        }

        /** @var ExternalManagedRuleOwnership $ownership */
        $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
        $meta = $ownership->describe($rule);
        if (!empty($meta['managed'])) {
            return [
                'source_module' => (string)$meta['source_module'],
                'source_type' => (string)$meta['source_type'],
                'source_id' => (string)$meta['source_id'],
                'source_key' => (string)$meta['source_key'],
            ];
        }

        return [
            'source_module' => '',
            'source_type' => '',
            'source_id' => '',
            'source_key' => '',
        ];
    }

    /**
     * Rewrite coupons still marked manual/empty when their rule is externally managed.
     *
     * @return int number of updated rows
     */
    public function backfillMissing(int $limit = 500): int
    {
        /** @var Coupon $couponModel */
        $couponModel = ObjectManager::getInstance(Coupon::class, [], false);
        $couponModel->clear()->order(Coupon::schema_fields_ID, 'DESC')->limit(max(1, $limit))->select()->fetch();
        $items = $couponModel->getItems() ?: [];

        $updated = 0;
        foreach ($items as $row) {
            $id = (int)($row[Coupon::schema_fields_ID] ?? $row['id'] ?? 0);
            $currentType = trim((string)($row[Coupon::schema_fields_SOURCE_TYPE] ?? ''));
            if ($id <= 0) {
                continue;
            }
            if ($currentType !== '' && $currentType !== Coupon::SOURCE_TYPE_MANUAL) {
                continue;
            }

            $ruleId = (int)($row[Coupon::schema_fields_RULE_ID] ?? 0);
            if ($ruleId <= 0) {
                continue;
            }

            /** @var Rule $rule */
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->load($ruleId);
            if (!$rule->getId()) {
                continue;
            }

            $attr = $this->fromRule($rule);
            if ($attr['source_type'] === '' || $attr['source_type'] === Coupon::SOURCE_TYPE_MANUAL) {
                continue;
            }

            /** @var Coupon $coupon */
            $coupon = ObjectManager::getInstance(Coupon::class);
            $coupon->load($id);
            if (!$coupon->getId()) {
                continue;
            }

            $coupon->setData([
                Coupon::schema_fields_SOURCE_MODULE => $attr['source_module'],
                Coupon::schema_fields_SOURCE_TYPE => $attr['source_type'],
                Coupon::schema_fields_SOURCE_ID => $attr['source_id'],
                Coupon::schema_fields_SOURCE_KEY => $attr['source_key'],
                Coupon::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ]);
            $coupon->save();
            $updated++;
        }

        return $updated;
    }
}
