<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelConversionDedupe;

/**
 * 转化事件前后端约定去重：按 website+event+business_key，TTL 内重复直接丢弃。
 */
class PixelConversionDedupeService
{
    public const DEFAULT_TTL_DAYS = 180;
    public const DEFAULT_EVENTS = [
        'payment_success',
        'checkout_success',
        'checkout_failure',
        '*_checkout_success',
    ];

    public const BUSINESS_KEY_FIELDS = [
        'transaction_id',
        'order_uuid',
        'order_id',
        'checkout_group_uuid',
    ];

    public function __construct(
        private ?VisitorTrackingConfig $trackingConfig = null,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function isDuplicate(int $websiteId, string $event, array $post, ?string $scope = null): bool
    {
        $cfg = $this->resolveConfig($scope);
        if (!$cfg['enabled']) {
            return false;
        }
        $eventName = $this->normalizeEventName($event);
        if ($eventName === '' || !$this->eventMatches($eventName, $cfg['events'])) {
            return false;
        }
        $businessKey = $this->resolveBusinessKey($post);
        if ($businessKey === '') {
            return false;
        }

        $now = time();
        $existing = $this->findRow($websiteId, $eventName, $businessKey);
        if ($existing !== null) {
            $expiresAt = strtotime((string)($existing[PixelConversionDedupe::schema_fields_EXPIRES_AT] ?? '')) ?: 0;
            if ($expiresAt > $now) {
                return true;
            }
            $this->deleteRow((int)($existing[PixelConversionDedupe::schema_fields_ID] ?? 0));
        }

        return !$this->claim($websiteId, $eventName, $businessKey, $cfg['ttlDays'], $now);
    }

    /**
     * @return array{enabled:bool,ttlDays:int,ttlSeconds:int,events:list<string>}
     */
    public function resolveConfig(?string $scope = null): array
    {
        try {
            $runtime = $this->tracking()->getRuntimeConfig($scope);
            $block = \is_array($runtime['conversionDedupe'] ?? null)
                ? $runtime['conversionDedupe']
                : [];
            $enabled = !empty($block['enabled']);
            $ttlDays = max(1, min(730, (int)($block['ttlDays'] ?? self::DEFAULT_TTL_DAYS)));
            $events = \is_array($block['events'] ?? null) ? $block['events'] : self::DEFAULT_EVENTS;
            $normalized = [];
            foreach ($events as $event) {
                $name = $this->normalizeEventName((string)$event, true);
                if ($name !== '') {
                    $normalized[$name] = $name;
                }
            }
            if ($normalized === []) {
                foreach (self::DEFAULT_EVENTS as $event) {
                    $normalized[$event] = $event;
                }
            }

            return [
                'enabled' => $enabled,
                'ttlDays' => $ttlDays,
                'ttlSeconds' => $ttlDays * 86400,
                'events' => array_values($normalized),
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'ttlDays' => self::DEFAULT_TTL_DAYS,
                'ttlSeconds' => self::DEFAULT_TTL_DAYS * 86400,
                'events' => self::DEFAULT_EVENTS,
            ];
        }
    }

    /**
     * @param list<string> $events
     */
    public function eventMatches(string $eventName, array $events): bool
    {
        $eventName = $this->normalizeEventName($eventName);
        if ($eventName === '') {
            return false;
        }
        foreach ($events as $candidate) {
            $candidate = $this->normalizeEventName((string)$candidate, true);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $eventName) {
                return true;
            }
            if ($candidate === '*_checkout_success' && str_ends_with($eventName, '_checkout_success')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $post
     */
    public function resolveBusinessKey(array $post): string
    {
        $bags = [$post];
        $meta = \is_array($post['meta'] ?? null) ? $post['meta'] : [];
        $bags[] = $meta;
        $additional = \is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [];
        $bags[] = $additional;
        $ecommerce = \is_array($additional['ecommerce'] ?? null) ? $additional['ecommerce'] : [];
        if ($ecommerce === [] && \is_array($post['ecommerce'] ?? null)) {
            $ecommerce = $post['ecommerce'];
        }
        $bags[] = $ecommerce;

        foreach (self::BUSINESS_KEY_FIELDS as $field) {
            foreach ($bags as $bag) {
                if (!\is_array($bag) || !array_key_exists($field, $bag)) {
                    continue;
                }
                $value = trim((string)$bag[$field]);
                if ($value !== '') {
                    return mb_substr($value, 0, 191);
                }
            }
        }

        // payment_success 模板常用 transaction_id 别名
        foreach ($bags as $bag) {
            if (!\is_array($bag)) {
                continue;
            }
            foreach (['transaction_no', 'transactionNo'] as $alias) {
                if (!array_key_exists($alias, $bag)) {
                    continue;
                }
                $value = trim((string)$bag[$alias]);
                if ($value !== '') {
                    return mb_substr($value, 0, 191);
                }
            }
        }

        return '';
    }

    private function normalizeEventName(string $event, bool $allowWildcard = false): string
    {
        $event = strtolower(trim($event));
        if ($allowWildcard && $event === '*_checkout_success') {
            return $event;
        }
        $event = preg_replace('/[^a-z0-9_]+/', '_', $event) ?: '';
        $event = trim($event, '_');

        return $event !== '' ? substr($event, 0, 64) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(int $websiteId, string $event, string $businessKey): ?array
    {
        try {
            /** @var PixelConversionDedupe $model */
            $model = ObjectManager::getInstance(PixelConversionDedupe::class);
            $row = $model->reset()
                ->where(PixelConversionDedupe::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PixelConversionDedupe::schema_fields_EVENT, $event)
                ->where(PixelConversionDedupe::schema_fields_BUSINESS_KEY, $businessKey)
                ->find()
                ->getData();
            if (!\is_array($row) || $row === []) {
                return null;
            }

            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    private function deleteRow(int $dedupeId): void
    {
        if ($dedupeId <= 0) {
            return;
        }
        try {
            /** @var PixelConversionDedupe $model */
            $model = ObjectManager::getInstance(PixelConversionDedupe::class);
            $model->reset()
                ->where(PixelConversionDedupe::schema_fields_ID, $dedupeId)
                ->delete();
        } catch (\Throwable) {
        }
    }

    private function claim(int $websiteId, string $event, string $businessKey, int $ttlDays, int $now): bool
    {
        $createdAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + max(1, $ttlDays) * 86400);
        try {
            /** @var PixelConversionDedupe $model */
            $model = ObjectManager::getInstance(PixelConversionDedupe::class);
            $model->clear()
                ->setData(PixelConversionDedupe::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(PixelConversionDedupe::schema_fields_EVENT, $event)
                ->setData(PixelConversionDedupe::schema_fields_BUSINESS_KEY, $businessKey)
                ->setData(PixelConversionDedupe::schema_fields_CREATED_AT, $createdAt)
                ->setData(PixelConversionDedupe::schema_fields_EXPIRES_AT, $expiresAt)
                ->save();

            return true;
        } catch (\Throwable) {
            // UNIQUE 冲突：视为已有他人先 claim
            $existing = $this->findRow($websiteId, $event, $businessKey);
            if ($existing === null) {
                // 表未升级等：不去重阻断主路径
                return true;
            }
            $expiresAtTs = strtotime((string)($existing[PixelConversionDedupe::schema_fields_EXPIRES_AT] ?? '')) ?: 0;

            return $expiresAtTs <= $now;
        }
    }

    private function tracking(): VisitorTrackingConfig
    {
        if (!$this->trackingConfig) {
            $this->trackingConfig = ObjectManager::getInstance(VisitorTrackingConfig::class);
        }

        return $this->trackingConfig;
    }
}
