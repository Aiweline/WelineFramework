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
use Weline\Framework\Manager\ObjectManager;

/**
 * 账户管理服务
 * 
 * 提供账户的CRUD、默认账户设置、模块配置同步等功能
 */
class AccountManager
{
    /**
     * @var Account
     */
    private Account $accountModel;

    /**
     * 构造函数
     */
    public function __construct(Account $accountModel)
    {
        $this->accountModel = $accountModel;
    }

    /**
     * @DESC          # 获取默认账户（按适配器）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $adapter 适配器代码
     * @return Account|null
     */
    public function getDefaultAccount(string $adapter): ?Account
    {
        try {
            /** @var Account $account */
            $account = $this->accountModel->clear()
                ->reset()
                ->where(Account::fields_ADAPTER, $adapter)
                ->where(Account::fields_IS_DEFAULT, 1)
                ->where(Account::fields_STATUS, Account::STATUS_ACTIVE)
                ->find()
                ->fetch();
            
            return $account->getId() ? $account : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @DESC          # 设置默认账户
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int $accountId 账户ID
     * @return bool
     */
    public function setDefaultAccount(int $accountId): bool
    {
        try {
            /** @var Account $account */
            $account = $this->accountModel->clear()->reset()->load($accountId);
            if (!$account->getId()) {
                return false;
            }

            $adapter = $account->getData(Account::fields_ADAPTER);

            // 清除同适配器的其他默认账户
            $this->accountModel->clear()
                ->reset()
                ->where(Account::fields_ADAPTER, $adapter)
                ->where(Account::fields_IS_DEFAULT, 1)
                ->where(Account::fields_ID, $accountId, '!=')
                ->update([Account::fields_IS_DEFAULT => 0]);

            // 设置当前账户为默认
            $account->setData(Account::fields_IS_DEFAULT, 1)->save();

            return true;
        } catch (\Exception $e) {
            error_log("设置默认账户失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @DESC          # 获取所有账户
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string|null $adapter 适配器过滤（可选）
     * @return array
     */
    public function getAllAccounts(?string $adapter = null): array
    {
        try {
            $query = $this->accountModel->clear()->reset();
            
            if ($adapter) {
                $query->where(Account::fields_ADAPTER, $adapter);
            }
            
            return $query->select()->fetchArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @DESC          # 获取账户关联的域名数量
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int $accountId 账户ID
     * @return int
     */
    public function getDomainCount(int $accountId): int
    {
        try {
            /** @var \Weline\Cdn\Model\Domain $domainModel */
            $domainModel = ObjectManager::getInstance(\Weline\Cdn\Model\Domain::class);
            return (int)$domainModel->clear()
                ->reset()
                ->where(\Weline\Cdn\Model\Domain::fields_ACCOUNT_ID, $accountId)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @DESC          # 获取未关联账户的域名列表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return array
     */
    public function getUnlinkedDomains(): array
    {
        try {
            /** @var \Weline\Cdn\Model\Domain $domainModel */
            $domainModel = ObjectManager::getInstance(\Weline\Cdn\Model\Domain::class);
            return $domainModel->clear()
                ->reset()
                ->where(\Weline\Cdn\Model\Domain::fields_ACCOUNT_ID, [null, 0], 'in')
                ->where(\Weline\Cdn\Model\Domain::fields_INHERIT_DEFAULT, 0)
                ->select()
                ->fetchArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
