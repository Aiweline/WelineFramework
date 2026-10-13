<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 主题可视化发布须按 scope+theme+themeVersionId bump；禁止再写全局 theme_static_version。
 */
final class ThemeStaticVersionPublishContractTest extends TestCase
{
    public function testBumpStaticVersionIsVersionScopedAndWiredFromPublishPaths(): void
    {
        $versionService = $this->read('app/code/Weline/Theme/Service/ThemeLayoutVersionService.php');
        self::assertStringContainsString('function bumpStaticVersion(', $versionService);
        self::assertStringContainsString('theme_static_versions', $versionService);
        self::assertStringContainsString('staticVersionMapKey', $versionService);
        self::assertStringNotContainsString("setConfig('theme_static_version'", $versionService);

        $trait = $this->read('app/code/Weline/Framework/View/TraitTemplate.php');
        self::assertStringContainsString('theme_static_versions', $trait);
        self::assertStringContainsString('ThemeApplicationContext::current', $trait);

        $scopedRequest = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspaceRequestService.php');
        self::assertStringContainsString('ThemeLayoutVersionService $layoutVersions', $scopedRequest);
        self::assertStringContainsString('bumpStaticVersion(', $scopedRequest);
        self::assertStringContainsString('invalidateAfterVersionPublish', $scopedRequest);
        self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $scopedRequest);

        $editor = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('bumpStaticVersion(', $editor);
        self::assertStringContainsString('invalidateAfterVersionPublish', $editor);
        self::assertStringNotContainsString('clearAllThemeRelatedCaches($resolvedThemeId', $editor);
        self::assertStringContainsString('finalizeStandardLayoutPublish(', $editor);
    }

    public function testThemeStorefrontTemplatesDoNotHardcodeStaticQueryVersions(): void
    {
        $roots = [
            'app/code/Weline/Theme/view/theme',
            'app/code/Weline/Theme/view/templates/backend/ThemeEditor',
        ];
        $violations = [];
        foreach ($roots as $root) {
            $dir = BP . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'phtml') {
                    continue;
                }
                $content = (string)file_get_contents($file->getPathname());
                if (preg_match('/@static\([^)]+\)\?(?:amp;)?v=/', $content)
                    || preg_match('/@static\([^)]+\)&amp;v=/', $content)
                ) {
                    $violations[] = substr($file->getPathname(), strlen(BP) + 1);
                }
            }
        }

        self::assertSame([], $violations, 'Hardcoded @static ?v= found: ' . implode(', ', $violations));
    }

    public function testPreviewInjectorsDoNotAppendHardcodedStamps(): void
    {
        $editorInjector = $this->read('app/code/Weline/Theme/Service/EditorModeAssetInjector.php');
        self::assertStringNotContainsString('ASSET_VERSION', $editorInjector);
        self::assertStringNotContainsString('theme-editor-virtual-gate', $editorInjector);

        $bootstrap = $this->read('app/code/Weline/Theme/Service/PreviewBootstrapAssetInjector.php');
        self::assertStringNotContainsString('live-preview-token', $bootstrap);
        self::assertStringNotContainsString("?v=", $bootstrap);

        $address = $this->read('app/code/Weline/Theme/Taglib/Address.php');
        self::assertStringContainsString('fetchTagSource(', $address);
        self::assertStringNotContainsString('address-loader.js?v=', $address);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
