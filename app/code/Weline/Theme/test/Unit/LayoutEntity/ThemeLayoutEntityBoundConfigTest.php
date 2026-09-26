<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

require_once dirname(__DIR__, 7) . '/vendor/autoload.php';

final class ThemeLayoutEntityBoundConfigTest extends TestCase
{
    private function binding(
        int $themeId,
        string $scope,
        string $source,
        string $assetsPath,
        string $configPath = '',
        int $versionId = 1,
    ): EntityRenderBinding {
        $identity = new ThemeVersionIdentity(
            $themeId,
            $scope,
            'normal',
            'frontend',
            $versionId,
            $source === 'chrome' ? ThemeVersionIdentity::MODE_FORMAL : ThemeVersionIdentity::MODE_DRAFT,
            1,
        );

        return new EntityRenderBinding(
            identity: $identity,
            source: $source,
            layoutIdentityHash: $source === 'page' ? hash('sha256', 'identity') : '',
            structureKey: hash('sha256', 'structure'),
            configKey: hash('sha256', basename($assetsPath !== '' ? $assetsPath : $configPath)),
            templatePath: '',
            configPath: $configPath,
            assetsPath: $assetsPath,
            structurePath: '',
            shellPath: '',
            bindingPath: '',
        );
    }

    public function testAncestorChromeAssetsUseEveryRenderedBinding(): void
    {
        $files = [tempnam(sys_get_temp_dir(), 'chrome-assets-'), tempnam(sys_get_temp_dir(), 'chrome-assets-')];
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $bindings = [];
            foreach ($files as $index => $file) {
                file_put_contents($file, json_encode(['layout_css' => ['scope' . $index . '.css']]));
                $bindings[] = $this->binding(1, 'scope' . $index, 'chrome', $file, '', $index + 1);
            }
            \Weline\Framework\Runtime\RequestContext::set('theme.layout_entity.rendered_chrome_bindings', $bindings);
            $paths = new ThemeLayoutEntityPaths();
            $head = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets(
                new ThemeLayoutEntityConfigStore($paths),
                new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector(null),
                $paths,
            );
            $result = (new \ReflectionMethod($head, 'renderedChromeAssets'))->invoke($head, 1);
            self::assertSame(['scope0.css', 'scope1.css'], $result['layout_css']);
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
            if ($previous !== null) {
                \Weline\Framework\Context::enter($previous);
            } else {
                \Weline\Framework\Context::leave();
            }
        }
    }

    public function testEmptyAssetManifestsCanBeMergedForHeadRendering(): void
    {
        $paths = new ThemeLayoutEntityPaths();
        $head = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets(
            new ThemeLayoutEntityConfigStore($paths),
            new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector(null),
            $paths,
        );
        self::assertStringContainsString('data-weline-widget-assets-fp', $head->mergeAndEmit([], []));
    }

    public function testAssetsUseTheBindingAlreadySelectedForTheBody(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'theme-bound-assets-');
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $assets = ['layout_css' => ['old-structure.css']];
            file_put_contents($file, json_encode($assets));
            $binding = $this->binding(1, 'scope', 'page', $file);
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            self::assertSame($assets, $store->readBoundAssets($binding));
        } finally {
            unlink($file);
            if ($previous !== null) {
                \Weline\Framework\Context::enter($previous);
            } else {
                \Weline\Framework\Context::leave();
            }
        }
    }

    public function testOldBindingKeepsConfigurationWhenNewRevisionAppears(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'theme-bound-');
        $next = tempnam(sys_get_temp_dir(), 'theme-bound-');
        try {
            $uid = str_repeat('a', 32);
            $old = [$uid => ['widget_module' => 'Weline_Theme', 'widget_code' => 'text', 'config' => ['label' => 'old']]];
            $new = [$uid => ['widget_module' => 'Weline_Theme', 'widget_code' => 'text', 'config' => ['label' => 'new']]];
            file_put_contents($file, json_encode($old));
            file_put_contents($next, json_encode($new));
            $binding = $this->binding(1, 'scope', 'page', '', $file, 1);
            $newBinding = $this->binding(1, 'scope', 'page', '', $next, 1);
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            self::assertSame($old, $store->readBoundConfig($binding));
            self::assertSame($new, $store->readBoundConfig($newBinding));
            self::assertSame($old, $store->readBoundConfig($binding));
        } finally {
            unlink($file);
            unlink($next);
        }
    }
}
