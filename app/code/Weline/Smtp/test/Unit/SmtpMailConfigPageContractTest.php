<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smtp 侧栏「邮件配置」+ 分组 embed 契约。
 */
final class SmtpMailConfigPageContractTest extends TestCase
{
    public function testMenuAndPageEmbedShellBackgroundGroup(): void
    {
        $root = dirname(__DIR__, 2);
        $menu = (string)file_get_contents($root . '/etc/backend/menu.xml');
        self::assertStringContainsString('system_smtp_mail_config', $menu);
        self::assertStringContainsString('title="邮件配置"', $menu);
        self::assertStringContainsString('action="smtp/backend/mail-config"', $menu);

        $controller = (string)file_get_contents($root . '/Controller/Backend/MailConfig.php');
        self::assertStringContainsString('Weline_Smtp::system_smtp_mail_config', $controller);
        self::assertStringContainsString('smtp/backend/mail-config', $controller);

        $view = (string)file_get_contents($root . '/view/Backend/MailConfig.phtml');
        self::assertStringContainsString('data-testid="smtp-mail-config"', $view);
        self::assertStringContainsString('w-backend-page', $view);
        self::assertStringContainsString('w-card__body', $view);
        self::assertStringContainsString('<w:config:embed', $view);
        self::assertStringContainsString('group="smtp_mail_shell"', $view);
        self::assertStringContainsString('module="Weline_Smtp"', $view);
        self::assertStringContainsString('<w:scope', $view);
        self::assertStringContainsString('邮件壳背景图', $view);

        $declaration = (string)file_get_contents(
            $root . '/extends/module/Weline_SystemConfig/Config/backend/smtp.phtml'
        );
        self::assertStringContainsString('code="smtp_mail_shell"', $declaration);
        self::assertStringContainsString('key="smtp_mail_bg_header"', $declaration);
    }
}
