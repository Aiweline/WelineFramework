<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceContractsPass().
 */
final class CustomerServiceContractsTest extends TestCase
{
    public function testAllCustomerServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CustomerLocalCredentialService::testCredentialServiceIsSharedByLoginAndSocialBind' => function () {
            $this->assert_CustomerLocalCredentialService_testCredentialServiceIsSharedByLoginAndSocialBind();
        },
            'PasswordResetMailTemplate::testRequestResetUsesChannelAndVarsWithoutSubject' => function () {
            $this->assert_PasswordResetMailTemplate_testRequestResetUsesChannelAndVarsWithoutSubject();
        },
        ];
    }

private function assert_CustomerLocalCredentialService_testCredentialServiceIsSharedByLoginAndSocialBind(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/CustomerLocalCredentialService.php');
    $linker = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/SocialLogin/SocialLoginAccountLinker.php');
    $login = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Login.php');
    self::assertStringContainsString('function authenticate', $service);
    self::assertStringContainsString('password_verify', $service);
    self::assertStringContainsString('CustomerLocalCredentialService', $linker);
    self::assertStringContainsString('CustomerLocalCredentialService', $login);
    self::assertStringNotContainsString('password_verify($password, (string) $user->getPassword())', $linker);
}

private function assert_PasswordResetMailTemplate_testRequestResetUsesChannelAndVarsWithoutSubject(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/PasswordResetService.php');
    self::assertStringContainsString("w_query('smtp', 'send'", $src);
    self::assertStringContainsString("'channel' => 'Weline_Customer::password_reset'", $src);
    self::assertStringContainsString("'vars'", $src);
    self::assertStringContainsString("'reset_url'", $src);
    self::assertStringContainsString("'customer_email'", $src);
    self::assertStringNotContainsString("'subject'", $src);
    self::assertStringNotContainsString("'content'", $src);
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/MailChannelProvider.php');
    self::assertStringContainsString("'default_templates'", $provider);
    self::assertStringContainsString('view/email/password_reset/', $provider);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/email/password_reset/zh_Hans_CN.html');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/email/password_reset/en_US.html');
}
}
