<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 当前后台会话的对象 Scope 授权守卫。
 *
 * QueryProvider 使用 require*ForQuery() 获得固定 403；筛选列表使用 isAllowed()，
 * 避免逐对象拒绝日志形成噪声。所有写入必须在实际 DML 前调用 requireSubmitForQuery()。
 */
interface BackendObjectAuthorizationGuardInterface
{
    public function currentRoleId(): int;

    public function check(string $action, ScopeIdentity $scope): ObjectAuthorizationResult;

    public function checkForSubmit(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult;

    public function isAllowed(string $action, ScopeIdentity $scope): bool;

    /**
     * @throws \Weline\Framework\Service\Query\FrontendQueryException
     */
    public function requireForQuery(string $action, ScopeIdentity $scope): ObjectAuthorizationResult;

    /**
     * @throws \Weline\Framework\Service\Query\FrontendQueryException
     */
    public function requireSubmitForQuery(
        string $action,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
    ): ObjectAuthorizationResult;

    /**
     * 对缺失/外部对象使用与普通越权完全相同的固定拒绝。
     *
     * @throws \Weline\Framework\Service\Query\FrontendQueryException
     */
    public function denyForQuery(string $action, ScopeIdentity $scope): never;
}
