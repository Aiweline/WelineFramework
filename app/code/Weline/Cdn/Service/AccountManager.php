<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;

/**
 * 账户管理服务
 * 
 * @package Weline_Cdn
 */
class AccountManager
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取账户模型实例
     * 
     * @return Account
     */
    private function getAccountModel(): Account
    {
        return $this->objectManager->getInstance(Account::class);
    }

    /**
     * 获取域名模型实例
     * 
     * @return Domain
     */
    private function getDomainModel(): Domain
    {
        return $this->objectManager->getInstance(Domain::class);
    }

    /**
     * 设置默认账户
     * 
     * @param int $accountId 账户ID
     * @return void
     */
    public function setDefaultAccount(int $accountId): void
    {
        $account = $this->getAccount($accountId);
        
        if (!$account instanceof Account || !$account->getId()) {
            throw new \InvalidArgumentException(__('账户不存在'));
        }

        $adapter = $account->getData(Account::schema_fields_ADAPTER);
        
        // 先取消该适配器的所有默认账户
        $this->getAccountModel()->reset()
            ->where(Account::schema_fields_ADAPTER, $adapter)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->update([Account::schema_fields_IS_DEFAULT => 0])
            ->fetch();
        
        // 设置新的默认账户
        $account->setData(Account::schema_fields_IS_DEFAULT, 1)->save();
    }

    /**
     * 获取适配器的默认账户
     *
     * @param string $adapter 适配器代码
     * @param \Weline\Framework\Runtime\ScopeIdentity|null $scope 若提供则仅返回 Scope 授权账户
     */
    public function getDefaultAccount(string $adapter, ?\Weline\Framework\Runtime\ScopeIdentity $scope = null): ?Account
    {
        if ($scope !== null) {
            return $this->resolveAuthorizedAccount($adapter, $scope);
        }

        $account = $this->getAccountModel()->reset()
            ->where(Account::schema_fields_ADAPTER, $adapter)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_STATUS, Account::STATUS_ACTIVE)
            ->find()
            ->fetch();

        return $account->getId() ? $account : null;
    }

    /**
     * Scope 授权账户：绑定表优先，且 account_id 必须存在且 active；跨 Scope 不串。
     */
    public function resolveAuthorizedAccount(
        string $adapter,
        \Weline\Framework\Runtime\ScopeIdentity $scope,
    ): ?Account {
        /** @var ScopedAccountBindingService $bindings */
        $bindings = $this->objectManager->getInstance(ScopedAccountBindingService::class);
        $hit = $bindings->resolve($scope, $adapter);
        if ($hit === null) {
            return null;
        }
        $account = $this->getAccount((int)$hit['account_id']);
        if ($account === null || !$account->isActive()) {
            return null;
        }
        if (\strtolower((string)$account->getData(Account::schema_fields_ADAPTER)) !== \strtolower($adapter)) {
            return null;
        }

        return $account;
    }

    public function bindAccountToScope(
        int $accountId,
        \Weline\Framework\Runtime\ScopeIdentity $scope,
        string $adapter,
        string $mediaBaseUrl = '',
        string $globalAlias = '',
    ): array {
        $account = $this->getAccount($accountId);
        if ($account === null) {
            throw new \InvalidArgumentException('cdn_account_not_found');
        }
        if (!$account->isActive()) {
            throw new \InvalidArgumentException('cdn_account_inactive');
        }
        $adapter = \strtolower(\trim($adapter));
        $accountAdapter = \strtolower(\trim((string)$account->getData(Account::schema_fields_ADAPTER)));
        if ($adapter === '' || $accountAdapter !== $adapter) {
            throw new \InvalidArgumentException('cdn_account_adapter_mismatch');
        }
        /** @var ScopedAccountBindingService $bindings */
        $bindings = $this->objectManager->getInstance(ScopedAccountBindingService::class);

        return $bindings->bind($scope, $adapter, $accountId, $mediaBaseUrl, $globalAlias);
    }

    public function restoreScopeInheritance(
        \Weline\Framework\Runtime\ScopeIdentity $scope,
        string $adapter,
    ): bool {
        /** @var ScopedAccountBindingService $bindings */
        $bindings = $this->objectManager->getInstance(ScopedAccountBindingService::class);

        return $bindings->restoreInheritance($scope, $adapter);
    }

    public function getAccount(int $accountId): ?Account
    {
        $account = $this->getAccountModel()->reset()
            ->where(Account::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();

        return $account instanceof Account && $account->getId() ? $account : null;
    }

    public function deleteAccount(int $accountId): void
    {
        $account = $this->getAccount($accountId);
        if ($account === null) {
            throw new \InvalidArgumentException('Account does not exist.');
        }

        $account->delete();
    }

    /**
     * 获取账户关联的域名列表
     * 
     * @param int $accountId 账户ID
     * @return array
     */
    public function getAccountDomains(int $accountId): array
    {
        $domains = $this->getDomainModel()->reset()
            ->where(Domain::schema_fields_ACCOUNT_ID, $accountId)
            ->select()
            ->fetch();
        
        return $domains->getItems();
    }
}
