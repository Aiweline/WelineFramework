<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SMTP 网站范围：SystemConfig 声明 + 配置页范围选择 + Data scoped API 契约。
 */
final class SmtpWebsiteScopeSettingsContractTest extends TestCase
{
    public function testSystemConfigDeclarationUsesWebsiteScope(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $declaration = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/backend/smtp.phtml'
        );

        self::assertStringContainsString('@config.area {backend}', $declaration);
        self::assertStringContainsString('@config.acl {Weline_Smtp::system_smtp_config}', $declaration);
        self::assertStringContainsString('key="smtp_senders"', $declaration);
        self::assertStringContainsString('key="smtp_sender_contacts"', $declaration);
        self::assertStringContainsString('key="smtp_host"', $declaration);
        self::assertStringContainsString('key="smtp_mail_bg_header"', $declaration);
        self::assertStringContainsString('key="smtp_mail_bg_body"', $declaration);
        self::assertStringContainsString('key="smtp_mail_bg_footer"', $declaration);
        self::assertStringContainsString('path-global="mail/backgrounds/header"', $declaration);
        self::assertStringContainsString('path="websites/{website}/{store}/mail/backgrounds/header"', $declaration);
        self::assertStringContainsString('lock-root-global="mail"', $declaration);
        self::assertStringContainsString('scope="global,website"', $declaration);
        self::assertStringContainsString('scope="global,website,store"', $declaration);
        self::assertStringContainsString('is_sensitive="true"', $declaration);

        $data = (string)file_get_contents($moduleRoot . '/Helper/Data.php');
        self::assertStringContainsString('key_smtp_mail_bg_header', $data);
        self::assertStringContainsString('getMailShellBackgrounds', $data);
    }

    public function testConfigPageUsesTargetScopeAndMenu(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        $template = (string)file_get_contents($moduleRoot . '/view/Backend/Config.phtml');
        $menuXml = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        $data = (string)file_get_contents($moduleRoot . '/Helper/Data.php');
        $provider = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php'
        );

        self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
        self::assertStringContainsString('getSenders($module,', $controller);
        self::assertStringContainsString('setSenders(', $controller);
        self::assertStringContainsString('target_scope', $controller);
        self::assertStringContainsString('isValidTransportId', $controller);

        self::assertStringContainsString('<w:scope', $template);
        self::assertStringContainsString('weline_systemconfig/backend/config', $template);
        self::assertStringContainsString('data-testid="smtp-config-scope"', $template);
        self::assertStringContainsString('pattern="[A-Za-z][A-Za-z0-9_]*"', $template);
        self::assertStringContainsString('sanitizeSenderCode', $template);
        self::assertStringContainsString('data-testid="smtp-section-identity"', $template);
        self::assertStringContainsString('data-testid="smtp-section-transport"', $template);
        self::assertStringContainsString('btnSaveSenders', $template);
        self::assertStringContainsString('weline-btn-test-sender, .btn-test-sender', $template);
        self::assertStringContainsString('网站维护中', $template);
        self::assertStringContainsString('isMaintenanceResult', $template);
        self::assertStringContainsString('不是业务代号，也不是邮箱', $template);
        self::assertStringContainsString('smtp-channel-bindings', $template);
        self::assertStringContainsString('smtp-account-management', $template);
        self::assertStringContainsString('WelineThemeSearchSelect', $template);
        self::assertStringContainsString('theme:search-select', $template);
        self::assertStringContainsString('data-smtp-ss="mail-account"', $template);
        self::assertStringContainsString('data-smtp-ss="account-channels"', $template);
        self::assertStringContainsString('data-smtp-ss="channel-transport"', $template);
        self::assertStringContainsString('collectChannelBindings', $template);
        self::assertStringContainsString('generateTransportId', $template);

        self::assertStringContainsString('source="Weline_Smtp::system_smtp_config"', $menuXml);
        self::assertStringContainsString('action="smtp/backend/config"', $menuXml);
        self::assertStringContainsString('title="Smtp配置"', $menuXml);

        self::assertStringContainsString('ConfigReader', $data);
        self::assertStringContainsString('ConfigStore', $data);
        self::assertStringContainsString('setScopedConfig', $data);
        self::assertStringContainsString('function getSenders(string $module = \'Weline_Smtp\', ?string $scope = null)', $data);
        self::assertStringContainsString('function setSenders(array $senders, string $module = \'Weline_Smtp\', ?string $scope = null)', $data);
        self::assertStringContainsString('function resolvePublicFromEmail(string $module = \'Weline_Smtp\', ?string $scope = null)', $data);

        self::assertStringContainsString("'scope'", $provider);
        self::assertStringContainsString('resolveScopeParam', $provider);
    }
}
