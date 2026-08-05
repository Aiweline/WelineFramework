<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Acl\Service\ArrayObjectScopeGrantStore;
use Weline\Acl\Service\ObjectAuthorizationService;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Vendor\Model\VendorIdentity;

/**
 * Backend role Scope ACL for Vendor management（default-deny）.
 *
 * Uses Weline_Acl ObjectAuthorization; does not invent a parallel ACL.
 */
final class VendorAclGuard
{
    public const ERROR_DENIED = 'vendor_acl_denied';

    public function __construct(
        private readonly ObjectAuthorizationServiceInterface $authorization,
        private readonly ?ArrayObjectScopeGrantStore $mutableStore = null,
    ) {
    }

    public static function forTesting(?ArrayObjectScopeGrantStore $store = null): self
    {
        $store ??= new ArrayObjectScopeGrantStore();

        return new self(new ObjectAuthorizationService($store), $store);
    }

    /**
     * @param list<ObjectScopeGrantRecord> $grants
     */
    public function replaceGrantsForTesting(array $grants): void
    {
        if ($this->mutableStore === null) {
            throw new \RuntimeException('vendor_acl_store_not_mutable');
        }
        $this->mutableStore->replaceAll($grants);
    }

    /**
     * @throws VendorConflictException
     */
    public function assertRoleMayManage(
        int $roleId,
        int $websiteId,
        string $websiteCode,
        string $action = ObjectAction::UPDATE,
    ): void {
        VendorIdentity::assertWebsiteId($websiteId);
        if ($websiteCode === '' && $websiteId === 0) {
            $websiteCode = 'default';
        }
        if ($websiteCode === '') {
            throw new \InvalidArgumentException(__('website_code 必填'));
        }
        if (!ObjectAction::isKnown($action) || $action === ObjectAction::ALL_SITES) {
            throw new \InvalidArgumentException(__('未知 ACL 动作：%{1}', [$action]));
        }

        $scope = ScopeIdentity::website($websiteId, $websiteCode);
        $result = $this->authorization->authorize($roleId, $action, $scope);
        if (!$result->allowed) {
            throw new VendorConflictException(
                self::ERROR_DENIED,
                __('角色无权管理 Vendor：role=%{1} website=%{2} action=%{3}', [$roleId, $websiteId, $action]),
                [
                    'role_id' => $roleId,
                    'website_id' => $websiteId,
                    'action' => $action,
                    'reason' => $result->reason,
                ],
            );
        }
    }
}
