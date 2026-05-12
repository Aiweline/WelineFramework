<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\TwoFactorAuth\Controller\Frontend\Accounts;
use Weline\TwoFactorAuth\Controller\Frontend\Setup;
use Weline\TwoFactorAuth\Model\TotpAccount;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

final class FrontendControllerConstructionTest extends TestCase
{
    public function testSetupControllerConstructsWithoutCallingMissingParentConstructor(): void
    {
        $service = $this->createMock(TwoFactorAuthService::class);

        $controller = new Setup($service);

        $this->assertInstanceOf(Setup::class, $controller);
    }

    public function testAccountsControllerConstructsWithoutCallingMissingParentConstructor(): void
    {
        $totpAccount = $this->createMock(TotpAccount::class);

        $controller = new Accounts($totpAccount);

        $this->assertInstanceOf(Accounts::class, $controller);
    }
}
