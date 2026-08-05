<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 对象 Scope 授权服务：动作矩阵 + Scope 交集 + All Sites 只读 + 提交重鉴权。
 *
 * 超管（role_id=1）不会获得 Store=* 写权限；仅在显式 All Sites 授权下可读。
 */
final class ObjectAuthorizationService implements ObjectAuthorizationServiceInterface
{
    public function __construct(
        private readonly ObjectScopeGrantStoreInterface $grantStore,
    ) {
    }

    public function authorize(int $roleId, string $action, ScopeIdentity $objectScope): ObjectAuthorizationResult
    {
        if ($roleId <= 0) {
            return ObjectAuthorizationResult::deny('invalid_role');
        }
        if (!ObjectAction::isKnown($action) || $action === ObjectAction::ALL_SITES) {
            return ObjectAuthorizationResult::deny('unknown_action');
        }

        $grants = $this->grantStore->findByRole($roleId);
        if ($grants === []) {
            return ObjectAuthorizationResult::deny('no_grants');
        }

        $bestVersion = 0;
        foreach ($grants as $grant) {
            if (!$grant->covers($objectScope) || !$grant->allowsAction($action)) {
                continue;
            }
            if ($grant->isAllSites && ObjectAction::isWrite($action)) {
                continue;
            }
            $bestVersion = \max($bestVersion, $grant->grantVersion);
        }

        if ($bestVersion <= 0) {
            return ObjectAuthorizationResult::deny('scope_or_action_denied');
        }

        return ObjectAuthorizationResult::allow('granted', $bestVersion);
    }

    public function authorizeForSubmit(
        int $roleId,
        string $action,
        ScopeIdentity $objectScope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult {
        if ($expectedGrantVersion <= 0) {
            return ObjectAuthorizationResult::deny('missing_grant_version');
        }
        $result = $this->authorize($roleId, $action, $objectScope);
        if (!$result->allowed) {
            return $result;
        }
        if ($result->matchedGrantVersion !== $expectedGrantVersion) {
            return ObjectAuthorizationResult::deny('grant_version_mismatch');
        }

        return $result;
    }

    public function isObjectActionAllowed(int $roleId, string $action, ScopeIdentity $objectScope): bool
    {
        return $this->authorize($roleId, $action, $objectScope)->allowed;
    }
}
