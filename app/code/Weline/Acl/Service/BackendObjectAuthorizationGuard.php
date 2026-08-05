<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Auth\BackendIdentityContextProviderInterface;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAuthorizationAuditInterface;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;

final class BackendObjectAuthorizationGuard implements BackendObjectAuthorizationGuardInterface
{
    private const FIXED_ERROR_CODE = 'object_scope_access_denied';

    public function __construct(
        private readonly ObjectAuthorizationServiceInterface $authorization,
        private readonly ObjectAuthorizationAuditInterface $audit,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function currentRoleId(): int
    {
        return $this->currentActor()['role_id'];
    }

    public function check(string $action, ScopeIdentity $scope): ObjectAuthorizationResult
    {
        $actor = $this->currentActor();
        if ($actor['role_id'] <= 0) {
            return ObjectAuthorizationResult::deny('missing_backend_identity');
        }

        return $this->authorization->authorize($actor['role_id'], $action, $scope);
    }

    public function checkForSubmit(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult {
        $actor = $this->currentActor();
        if ($actor['role_id'] <= 0) {
            return ObjectAuthorizationResult::deny('missing_backend_identity');
        }

        return $this->authorization->authorizeForSubmit(
            $actor['role_id'],
            $action,
            $scope,
            $expectedGrantVersion,
        );
    }

    public function isAllowed(string $action, ScopeIdentity $scope): bool
    {
        return $this->check($action, $scope)->allowed;
    }

    public function requireForQuery(string $action, ScopeIdentity $scope): ObjectAuthorizationResult
    {
        $result = $this->check($action, $scope);
        if (!$result->allowed) {
            $this->audit($action, $scope, $result, 'read');
            $this->deny();
        }

        return $result;
    }

    public function requireSubmitForQuery(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult {
        $result = $this->checkForSubmit($action, $scope, $expectedGrantVersion);
        $this->audit($action, $scope, $result, 'submit');
        if (!$result->allowed) {
            $this->deny();
        }

        return $result;
    }

    public function denyForQuery(string $action, ScopeIdentity $scope): never
    {
        $this->audit(
            $action,
            $scope,
            ObjectAuthorizationResult::deny('object_not_accessible'),
            'read',
        );
        $this->deny();
    }

    /**
     * @return array{user_id:int,role_id:int}
     */
    private function currentActor(): array
    {
        $provider = $this->runtimeProviders->resolve(BackendIdentityContextProviderInterface::class);
        if (!$provider instanceof BackendIdentityContextProviderInterface) {
            return ['user_id' => 0, 'role_id' => 0];
        }
        $context = $provider->currentAclContext();
        if (!\is_array($context) || (int)($context['is_enabled'] ?? 0) !== 1) {
            return ['user_id' => 0, 'role_id' => 0];
        }

        $userId = (int)($context['user_id'] ?? 0);
        $roleId = (int)($context['role_id'] ?? 0);
        if ($userId <= 0 || $roleId <= 0) {
            return ['user_id' => 0, 'role_id' => 0];
        }

        return ['user_id' => $userId, 'role_id' => $roleId];
    }

    private function audit(
        string $action,
        ScopeIdentity $scope,
        ObjectAuthorizationResult $result,
        string $phase,
    ): void {
        $actor = $this->currentActor();
        $this->audit->record(
            $actor['user_id'],
            $actor['role_id'],
            $action,
            $scope,
            $result->allowed,
            $result->reason,
            $result->matchedGrantVersion,
            $phase,
        );
    }

    private function deny(): never
    {
        throw new FrontendQueryException(
            self::FIXED_ERROR_CODE,
            (string)\__('操作授权条件不满足'),
            403,
        );
    }
}
