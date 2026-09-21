<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

/**
 * File-image stamps must use the website default locale, not Env alone.
 * Chang Hanfu (and similar sites) default to en_US while the framework
 * default stays zh_Hans_CN; mixing the two fails draft validation.
 */
final class ThemeFileImageLocaleStampContractTest extends TestCase
{
    public function testDraftValidationResolvesWebsiteDefaultLocale(): void
    {
        $source = $this->source(
            dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php',
        );
        $resolver = $this->functionBody($source, 'private function resolveFileAssetLocale');
        self::assertStringContainsString('$this->resolveWebsiteDefaultLocale(', $resolver);
        self::assertStringNotContainsString(
            'Env::default_LANGUAGE_CODE',
            $resolver,
            'Default layout identity must not stamp file-image validation with the framework locale alone',
        );

        $website = $this->functionBody($source, 'private function resolveWebsiteDefaultLocale');
        self::assertStringContainsString('website_id=0 is the system default site', $website);
        self::assertStringContainsString('KIND_GLOBAL', $website);
        $websiteModel = strpos($website, 'Websites\\Model\\Website::class');
        $websiteData = strpos($website, 'WebsiteData::getDefaultLanguage');
        $state = strpos($website, 'State::resolveWebsiteDefaultLanguage');
        $env = strpos($website, 'Env::default_LANGUAGE_CODE');
        self::assertNotFalse($websiteModel);
        self::assertNotFalse($websiteData);
        self::assertNotFalse($state);
        self::assertNotFalse($env);
        self::assertLessThan($websiteData, $websiteModel);
        self::assertLessThan($state, $websiteData);
        self::assertLessThan($env, $state);
    }

    public function testPreviewHydrationUsesWebsiteDefaultLocale(): void
    {
        $source = $this->source(
            dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php',
        );
        $hydration = $this->functionBody($source, 'private function resolvePreviewHydrationLocale');
        self::assertStringContainsString('$this->resolveEditorWebsiteDefaultLocale()', $hydration);
        self::assertStringNotContainsString(
            'Env::default_LANGUAGE_CODE',
            $hydration,
            'Preview hydration must share the website default used by the picker stamp',
        );
    }

    public function testFilePickerStampsWebsiteDefaultForDefaultLayout(): void
    {
        $files = [
            dirname(__DIR__, 5) . '/FileManager/view/statics/js/file-picker.js',
            dirname(__DIR__, 4) . '/view/statics/ui/components/weline-file-picker.js',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $src = $this->source($file);
            self::assertStringContainsString('function resolveThemeEditorFileImageLocale', $src);
            self::assertStringContainsString('data-default-locale', $src);
            self::assertStringContainsString('typedNode.usage.locale_code = stampLocale', $src);
            self::assertStringContainsString('url.searchParams.set(\'locale_code\', stampLocale)', $src);
            // Must not keep a prior file-image node's locale over the editor stamp.
            self::assertStringNotContainsString(
                'url.searchParams.set(\'locale_code\', String(node.usage.locale_code))',
                $src,
            );
        }
    }

    private function source(string $path): string
    {
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    private function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start, $signature);
        $next = strpos($source, "\n    private function ", $start + strlen($signature));
        if ($next === false) {
            $next = strpos($source, "\n    public function ", $start + strlen($signature));
        }
        self::assertNotFalse($next, $signature);

        return substr($source, $start, $next - $start);
    }
}
