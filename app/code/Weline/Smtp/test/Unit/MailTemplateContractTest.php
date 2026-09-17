<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ch1：渠道契约透传 + SmtpMailTemplate 唯一键 + Seeder/Renderer/Resolver 入口。
 */
final class MailTemplateContractTest extends TestCase
{
    public function testCollectorPassesVariablesAndDefaultTemplates(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $collector = (string)file_get_contents($moduleRoot . '/Service/MailChannelCollector.php');
        $interface = (string)file_get_contents($moduleRoot . '/Api/MailChannelProviderInterface.php');

        self::assertStringContainsString('variables', $interface);
        self::assertStringContainsString('default_templates', $interface);
        self::assertStringContainsString("'variables'", $collector);
        self::assertStringContainsString("'default_templates'", $collector);
    }

    public function testModelHasUniqueChannelScopeLocale(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $model = (string)file_get_contents($moduleRoot . '/Model/SmtpMailTemplate.php');

        self::assertStringContainsString("schema_fields_CHANNEL_CODE = 'channel_code'", $model);
        self::assertStringContainsString("schema_fields_STORAGE_SCOPE = 'storage_scope'", $model);
        self::assertStringContainsString("schema_fields_LOCALE = 'locale'", $model);
        self::assertStringContainsString("schema_fields_USE_DEFAULT = 'use_default'", $model);
        self::assertStringContainsString("schema_fields_SEED_HASH = 'seed_hash'", $model);
        self::assertStringContainsString('uk_smtp_mail_template_channel_scope_locale', $model);
        self::assertStringContainsString("type: 'UNIQUE'", $model);
    }

    public function testServicesExist(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        self::assertFileExists($moduleRoot . '/Service/MailTemplateRenderer.php');
        self::assertFileExists($moduleRoot . '/Service/MailTemplateResolver.php');
        self::assertFileExists($moduleRoot . '/Service/MailTemplateSeeder.php');

        $renderer = (string)file_get_contents($moduleRoot . '/Service/MailTemplateRenderer.php');
        self::assertStringContainsString('strip_tags', $renderer);
        self::assertStringContainsString('|raw', $renderer);
        self::assertStringContainsString('htmlspecialchars', $renderer);
    }

    public function testModuleDoesNotRequireCkEditor(): void
    {
        $module = include dirname(__DIR__, 2) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertArrayNotHasKey('Weline_EditorManager', $module['requires'] ?? []);
        self::assertArrayNotHasKey('Weline_CKEditorEditorManager', $module['requires'] ?? []);
    }
}
