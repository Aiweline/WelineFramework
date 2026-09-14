<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: password_reset 发信仅 channel+vars，不硬编码 subject/content。 */
final class PasswordResetMailTemplateContractTest extends TestCase
{
    public function testRequestResetUsesChannelAndVarsWithoutSubject(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PasswordResetService.php');
        self::assertStringContainsString("w_query('smtp', 'send'", $src);
        self::assertStringContainsString("'channel' => 'Weline_Customer::password_reset'", $src);
        self::assertStringContainsString("'vars'", $src);
        self::assertStringContainsString("'reset_url'", $src);
        self::assertStringContainsString("'customer_email'", $src);
        self::assertStringNotContainsString("'subject'", $src);
        self::assertStringNotContainsString("'content'", $src);

        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/MailChannelProvider.php');
        self::assertStringContainsString("'default_templates'", $provider);
        self::assertStringContainsString('view/email/password_reset/', $provider);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/email/password_reset/zh_Hans_CN.html');
        self::assertFileExists(dirname(__DIR__, 3) . '/view/email/password_reset/en_US.html');
    }
}
