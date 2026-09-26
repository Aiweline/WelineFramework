<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\SlotRendererService;

/**
 * Current-scope chrome config (with layout assets) must win over ancestor bindings.
 */
final class SharedChromeAssetBindingTest extends TestCase
{
    public function testAncestorHeadBindingDoesNotDiscardCurrentScopeLayoutAssets(): void
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        RequestContext::init();
        $files = [
            \tempnam(\sys_get_temp_dir(), 'chrome-local-'),
            \tempnam(\sys_get_temp_dir(), 'chrome-parent-'),
        ];
        try {
            $uid = \str_repeat('a', 32);
            $assets = 'Weline_Theme::css/widgets/widget-product-cross-sell-default.css,Weline_Theme::js/widgets/widget-product-cross-sell-default-0.js';
            \file_put_contents($files[0], \json_encode([
                $uid => [
                    'node_uid' => $uid,
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'header',
                    'widget_code' => 'account',
                    'area' => 'header',
                    'slot_id' => 'user-area',
                    'layout_source' => $assets,
                    'config' => ['_layout_source' => $assets],
                ],
            ], \JSON_THROW_ON_ERROR));
            \file_put_contents($files[1], \json_encode([
                \str_repeat('b', 32) => [
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'header',
                    'widget_code' => 'account',
                    'area' => 'header',
                    'slot_id' => 'user-area',
                ],
            ], \JSON_THROW_ON_ERROR));

            $leafScope = 'website.default.default';
            $parentScope = 'default.default.default';
            $bindings = [];
            foreach ([$leafScope, $parentScope] as $i => $scope) {
                $identity = new ThemeVersionIdentity(3, $scope, 'normal', 'frontend', $i + 1, ThemeVersionIdentity::MODE_FORMAL, 1);
                $bindings[] = new EntityRenderBinding(
                    identity: $identity,
                    source: 'chrome',
                    layoutIdentityHash: '',
                    structureKey: \hash('sha256', 'chrome-struct-' . $i),
                    configKey: \hash('sha256', 'chrome-config-' . $i),
                    templatePath: '',
                    configPath: $files[$i],
                    assetsPath: '',
                    structurePath: '',
                    shellPath: '',
                    bindingPath: '',
                );
            }

            $sources = [];
            foreach ($bindings as $binding) {
                $sources[] = [
                    'path' => $binding->configPath,
                    'binding' => $binding,
                    'scope' => $binding->identity->canonicalScope,
                    'version_id' => $binding->identity->themeVersionId,
                    'preview' => false,
                ];
            }

            // ThemeLayoutEntityChrome is final — seed its RequestContext cache instead of mocking.
            RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(scope: $leafScope));
            $selection = RequestContext::get('theme.layout_entity.preview_entity');
            $sourcesKey = 'theme.layout_entity.chrome_sources.' . \hash(
                'sha256',
                \json_encode([3, $leafScope, false, $selection], \JSON_THROW_ON_ERROR),
            );
            RequestContext::set($sourcesKey, $sources);

            $chrome = (new \ReflectionClass(ThemeLayoutEntityChrome::class))->newInstanceWithoutConstructor();
            ObjectManager::setInstance(ThemeLayoutEntityChrome::class, $chrome);

            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            ObjectManager::setInstance(ThemeLayoutEntityConfigStore::class, $store);
            ObjectManager::setInstance(
                ThemeLayoutSlotTreeBuilder::class,
                (new \ReflectionClass(ThemeLayoutSlotTreeBuilder::class))->newInstanceWithoutConstructor(),
            );

            self::assertNotEmpty($store->readBoundConfig($bindings[0]));
            $renderer = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
            $slots = (new \ReflectionMethod($renderer, 'loadSharedChromeSlotWidgetsFromEntity'))
                ->invoke($renderer, 3, 'frontend');

            self::assertSame('account', $slots['user-area'][0]['widget_code'] ?? null);
            self::assertSame($uid, $slots['user-area'][0]['node_uid'] ?? null);
            self::assertSame($assets, $slots['user-area'][0]['config']['_layout_source'] ?? null);
        } finally {
            foreach ($files as $file) {
                if (\is_string($file) && \is_file($file)) {
                    @\unlink($file);
                }
            }
            RequestContext::cleanup();
            if ($previous !== null) {
                Context::enter($previous);
            } else {
                Context::leave();
            }
        }
    }
}
