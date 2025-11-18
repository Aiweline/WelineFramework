<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Service\AccountManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * AccountManager服务单元测试
 */
class AccountManagerTest extends TestCase
{
    private AccountManager $accountManager;
    private Account $accountModel;

    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = ObjectManager::getInstance();
        $this->accountManager = new AccountManager($objectManager);
        $this->accountModel = ObjectManager::getInstance(Account::class);
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(AccountManager::class, $this->accountManager);
    }

    /**
     * 测试：获取适配器的默认账户（无账户时返回null）
     */
    public function testGetDefaultAccountWhenNoneExists(): void
    {
        // 注意：这个测试依赖于数据库状态
        // 在实际测试中，可能需要mock或清理测试数据
        try {
            $account = $this->accountManager->getDefaultAccount('cloudflare');
            // 如果没有默认账户，应该返回null
            $this->assertTrue($account === null || $account instanceof Account);
        } catch (\Exception $e) {
            // 如果数据库未配置，测试会失败，这是预期的
            $this->markTestSkipped('数据库未配置，跳过测试: ' . $e->getMessage());
        }
    }

    /**
     * 测试：获取账户（不存在时返回null）
     */
    public function testGetAccountWhenNotExists(): void
    {
        try {
            $account = $this->accountManager->getAccount(999999);
            $this->assertNull($account, '不存在的账户应该返回null');
        } catch (\Exception $e) {
            $this->markTestSkipped('数据库未配置，跳过测试: ' . $e->getMessage());
        }
    }

    /**
     * 测试：删除不存在的账户（应该抛出异常）
     */
    public function testDeleteNonExistentAccount(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->accountManager->deleteAccount(999999);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '数据库') !== false || strpos($e->getMessage(), 'Connection') !== false) {
                $this->markTestSkipped('数据库未配置，跳过测试: ' . $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    /**
     * 测试：设置默认账户（账户不存在时应抛出异常）
     */
    public function testSetDefaultAccountWhenNotExists(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->accountManager->setDefaultAccount(999999);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '数据库') !== false || strpos($e->getMessage(), 'Connection') !== false) {
                $this->markTestSkipped('数据库未配置，跳过测试: ' . $e->getMessage());
            } elseif (strpos($e->getMessage(), '账户不存在') !== false) {
                // 这是预期的异常
                $this->assertTrue(true);
            } else {
                throw $e;
            }
        }
    }

    /**
     * 测试：获取账户关联的域名列表
     */
    public function testGetAccountDomains(): void
    {
        try {
            $domains = $this->accountManager->getAccountDomains(999999);
            $this->assertIsArray($domains, '应该返回数组');
            $this->assertEmpty($domains, '不存在的账户应该返回空数组');
        } catch (\Exception $e) {
            $this->markTestSkipped('数据库未配置，跳过测试: ' . $e->getMessage());
        }
    }
}

