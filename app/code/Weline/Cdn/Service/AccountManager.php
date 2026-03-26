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
     * @return Account|null
     */
    public function getDefaultAccount(string $adapter): ?Account
    {
        $account = $this->getAccountModel()->reset()
            ->where(Account::schema_fields_ADAPTER, $adapter)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_STATUS, Account::STATUS_ACTIVE)
            ->find()
            ->fetch();
        
        return $account->getId() ? $account : null;
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

