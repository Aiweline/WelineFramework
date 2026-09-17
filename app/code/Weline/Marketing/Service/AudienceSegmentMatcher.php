<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Audience\AudienceSegment;

/**
 * 受众分群匹配：可注入客户事实，默认经 customer_signals / 保守回退。
 */
final class AudienceSegmentMatcher
{
    /** @var callable(int):array{created_at?:string,order_count?:int,last_active_at?:string} */
    private $customerFacts;

    /** @var callable(int):?array */
    private $loadSegment;

    /**
     * @param callable(int):array|null $customerFacts
     * @param callable(int):?array|null $loadSegment
     */
    public function __construct(?callable $customerFacts = null, ?callable $loadSegment = null)
    {
        $this->customerFacts = $customerFacts ?? [$this, 'defaultCustomerFacts'];
        $this->loadSegment = $loadSegment ?? [$this, 'defaultLoadSegment'];
    }

    public function matches(int $customerId, int $segmentId, ?int $nowTs = null): bool
    {
        if ($segmentId <= 0) {
            return true;
        }
        if ($customerId <= 0) {
            return false;
        }
        $segment = ($this->loadSegment)($segmentId);
        if ($segment === null) {
            return false;
        }
        $status = (string)($segment['status'] ?? '');
        if ($status !== '' && $status !== AudienceSegment::STATUS_ENABLED) {
            return false;
        }

        return $this->evaluate($customerId, (string)($segment['kind'] ?? ''), $this->configOf($segment), $nowTs);
    }

    public function matchesCode(int $customerId, string $segmentCode, ?int $nowTs = null): bool
    {
        $segmentCode = \trim($segmentCode);
        if ($segmentCode === '') {
            return true;
        }
        if ($customerId <= 0) {
            return false;
        }
        $segment = $this->defaultLoadSegmentByCode($segmentCode);
        if ($segment === null) {
            return false;
        }

        return $this->evaluate($customerId, (string)($segment['kind'] ?? ''), $this->configOf($segment), $nowTs);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function evaluate(int $customerId, string $kind, array $config = [], ?int $nowTs = null): bool
    {
        $now = $nowTs ?? \time();
        $facts = ($this->customerFacts)($customerId);
        $createdAt = \trim((string)($facts['created_at'] ?? ''));
        $createdTs = $createdAt !== '' ? (\strtotime($createdAt . ' UTC') ?: \strtotime($createdAt)) : false;
        $orderCount = (int)($facts['order_count'] ?? 0);
        $lastActive = \trim((string)($facts['last_active_at'] ?? $createdAt));
        $lastTs = $lastActive !== '' ? (\strtotime($lastActive . ' UTC') ?: \strtotime($lastActive)) : false;

        return match ($kind) {
            AudienceSegment::KIND_NEW_CUSTOMER => $orderCount <= 0,
            AudienceSegment::KIND_RETURNING => $orderCount >= 1,
            AudienceSegment::KIND_IDLE_DAYS => $this->idleMatch($config, $now, $lastTs === false ? null : (int)$lastTs),
            default => false,
        };
    }

    /** @param array<string, mixed> $config */
    private function idleMatch(array $config, int $now, ?int $lastTs): bool
    {
        $idleDays = max(1, (int)($config['idle_days'] ?? 30));
        if ($lastTs === null) {
            return false;
        }

        return $now >= ($lastTs + $idleDays * 86400);
    }

    /** @param array<string, mixed> $segment */
    private function configOf(array $segment): array
    {
        if (\is_array($segment['config'] ?? null)) {
            return $segment['config'];
        }
        $raw = \trim((string)($segment['config_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array{created_at?:string,order_count?:int,last_active_at?:string} */
    private function defaultCustomerFacts(int $customerId): array
    {
        if (\function_exists('w_query')) {
            try {
                $result = w_query('customer_signals', 'get_customer_facts', ['customer_id' => $customerId]);
                if (\is_array($result) && \is_array($result['item'] ?? null)) {
                    return $result['item'];
                }
            } catch (\Throwable) {
            }
        }

        return ['created_at' => '', 'order_count' => 0, 'last_active_at' => ''];
    }

    private function defaultLoadSegment(int $segmentId): ?array
    {
        /** @var AudienceSegment $model */
        $model = ObjectManager::getInstance(AudienceSegment::class);
        $model->load($segmentId);
        if (!$model->getId()) {
            return null;
        }

        return $model->getData();
    }

    private function defaultLoadSegmentByCode(string $code): ?array
    {
        /** @var AudienceSegment $model */
        $model = ObjectManager::getInstance(AudienceSegment::class);
        $model->clear()
            ->where(AudienceSegment::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }

        return $model->getData();
    }
}
