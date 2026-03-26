<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Service\AccountManager;
use Weline\Framework\Manager\ObjectManager;

class AccountManagerTest extends TestCase
{
    private AccountManager $accountManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountManager = new AccountManager(ObjectManager::getInstance());
    }

    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(AccountManager::class, $this->accountManager);
    }

    public function testGetDefaultAccountWhenNoneExists(): void
    {
        try {
            $account = $this->accountManager->getDefaultAccount('cloudflare');
            $this->assertTrue($account === null || $account instanceof Account);
        } catch (\Exception $e) {
            $this->skipForConnectionError($e);
        }
    }

    public function testGetAccountWhenNotExists(): void
    {
        try {
            $account = $this->accountManager->getAccount(999999);
            $this->assertNull($account);
        } catch (\Exception $e) {
            $this->skipForConnectionError($e);
        }
    }

    public function testDeleteNonExistentAccount(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->accountManager->deleteAccount(999999);
        } catch (\Exception $e) {
            if ($this->isConnectionError($e)) {
                $this->markTestSkipped('Database is not configured: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    public function testSetDefaultAccountWhenNotExists(): void
    {
        try {
            $this->accountManager->setDefaultAccount(999999);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->skipForConnectionError($e);
        }
    }

    public function testGetAccountDomains(): void
    {
        try {
            $domains = $this->accountManager->getAccountDomains(999999);
            $this->assertIsArray($domains);
            $this->assertEmpty($domains);
        } catch (\Exception $e) {
            $this->skipForConnectionError($e);
        }
    }

    private function skipForConnectionError(\Exception $e): void
    {
        if ($this->isConnectionError($e)) {
            $this->markTestSkipped('Database is not configured: ' . $e->getMessage());
        }

        throw $e;
    }

    private function isConnectionError(\Exception $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Connection')
            || str_contains($message, 'database')
            || str_contains($message, 'Database')
            || str_contains($message, '鏁版嵁搴?');
    }
}
