<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model\Website;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\Website\LocalDescription as WebsiteLocalDescription;

final class WebsiteLocalDescriptionContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModelAndMapsParent(): void
    {
        self::assertTrue(is_subclass_of(WebsiteLocalDescription::class, LocalModel::class));
        self::assertSame(Website::schema_fields_ID, WebsiteLocalDescription::schema_fields_ID);
        self::assertSame(Website::schema_fields_NAME, WebsiteLocalDescription::schema_fields_NAME);
        self::assertSame(Website::schema_fields_DESCRIPTION, WebsiteLocalDescription::schema_fields_DESCRIPTION);
        self::assertSame(Website::schema_fields_NAME, WebsiteLocalDescription::schema_fields_name);
        self::assertSame('weline_websites_website_local', WebsiteLocalDescription::schema_table);
    }

    public function testParentInferenceMatchesWebsiteModel(): void
    {
        $local = WebsiteLocalDescription::class;
        self::assertTrue(str_ends_with($local, '\\LocalDescription'));
        $parent = substr($local, 0, -strlen('\\LocalDescription'));
        self::assertSame(Website::class, $parent);
        self::assertTrue(class_exists($parent));
    }

    public function testFormExposesLocalTriggersForNameAndDescription(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('Weline\\Websites\\Model\\Website\\LocalDescription', $tpl);
        self::assertStringContainsString('data-testid="website-edit-name-local"', $tpl);
        self::assertStringContainsString('data-testid="website-edit-description-local"', $tpl);
        self::assertStringContainsString('data-label-layout="stacked"', $tpl);
        self::assertStringContainsString('w-website-local-preview', $tpl);
        self::assertStringNotContainsString('w-website-local-preview__badge', $tpl);
        self::assertStringNotContainsString('点击编辑各语言文案', $tpl);
        self::assertStringContainsString('field="name"', $tpl);
        self::assertStringContainsString('field="description"', $tpl);
        self::assertStringContainsString('保存后可翻译多语言', $tpl);
        self::assertStringContainsString('LocalModel 翻译', $tpl);
    }

    public function testWebsiteDataPrefersLocalDescription(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Data/WebsiteData.php',
        );
        self::assertStringContainsString('WebsiteLocalDescription', $source);
        self::assertStringContainsString('resolveLocalizedField', $source);
        self::assertStringContainsString('schema_fields_NAME', $source);
        self::assertStringContainsString('schema_fields_DESCRIPTION', $source);
    }

    public function testUpgradeRegistersLocalDescriptionModel(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Setup/Upgrade.php',
        );
        self::assertStringContainsString('WebsiteLocalDescription::class', $source);
        self::assertStringContainsString('putModel', $source);
    }
}
