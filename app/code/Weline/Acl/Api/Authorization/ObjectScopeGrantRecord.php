<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 一条角色→对象 Scope 授权（内存/持久化共用形状）。
 *
 * @param list<string> $actions
 */
final readonly class ObjectScopeGrantRecord
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        public int $roleId,
        public bool $isAllSites,
        public ?string $scopeKind,
        public ?int $websiteId,
        public ?string $websiteCode,
        public ?string $storeCode,
        public ?string $channelCode,
        public array $actions,
        public int $grantVersion,
    ) {
    }

    public function covers(ScopeIdentity $object): bool
    {
        if ($this->isAllSites) {
            return true;
        }
        if ($this->scopeKind === null || $this->scopeKind === '') {
            return false;
        }

        return match ($this->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => $object->isGlobal()
                && $this->websiteId === null
                && $this->websiteCode === null
                && $this->storeCode === null
                && $this->channelCode === null,
            ScopeIdentity::KIND_WEBSITE => $object->websiteId !== null
                && $this->websiteId !== null
                && $object->websiteId === $this->websiteId
                && ($this->websiteCode === null || $this->websiteCode === $object->websiteCode),
            ScopeIdentity::KIND_STORE => $object->websiteId !== null
                && $object->storeCode !== null
                && $this->websiteId !== null
                && $this->storeCode !== null
                && $object->websiteId === $this->websiteId
                && $object->storeCode === $this->storeCode,
            ScopeIdentity::KIND_CHANNEL => $object->websiteId !== null
                && $object->storeCode !== null
                && $object->channelCode !== null
                && $this->websiteId !== null
                && $this->storeCode !== null
                && $this->channelCode !== null
                && $object->websiteId === $this->websiteId
                && $object->storeCode === $this->storeCode
                && $object->channelCode === $this->channelCode,
            default => false,
        };
    }

    public function allowsAction(string $action): bool
    {
        if ($this->isAllSites) {
            return ObjectAction::isAllSitesReadable($action);
        }

        return \in_array($action, $this->actions, true);
    }
}
