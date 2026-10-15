<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 默认 Smtp 提交账号：常量 smtp@ + ensure/set + 域名指针字段。
 */
final class MailAccountEnsureDefaultSmtpContractTest extends TestCase
{
    public function testEnsureServiceDefinesSmtpDefaultAndPersistApis(): void
    {
        $path = BP . '/app/code/Weline/Mail/Service/MailAccountEnsureService.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("LOCAL_SMTP_DEFAULT = 'smtp'", $src);
        self::assertStringContainsString('function ensureDefaultSmtpAccount', $src);
        self::assertStringContainsString('function setDefaultSmtpAccount', $src);
        self::assertStringContainsString('DEFAULT_SMTP_ACCOUNT_ID', $src);
        self::assertStringContainsString('SMTP 提交', $src);
    }

    public function testMailDomainHasDefaultSmtpAccountIdColumn(): void
    {
        $path = BP . '/app/code/Weline/Mail/Model/MailDomain.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('default_smtp_account_id', $src);
        self::assertStringContainsString('schema_fields_DEFAULT_SMTP_ACCOUNT_ID', $src);
    }

    public function testQueryExposesDefaultSmtpOps(): void
    {
        $path = BP . '/app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("'ensureDefaultSmtpAccount'", $src);
        self::assertStringContainsString("'setDefaultSmtpAccount'", $src);
    }

    public function testClientSettingsPreferSmtpSubdomain(): void
    {
        $path = BP . '/app/code/Weline/Mail/Service/StalwartEngineAdapter.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("'smtp.'", $src);
        self::assertStringContainsString('$smtpHost', $src);
    }
}
