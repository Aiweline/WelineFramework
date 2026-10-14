<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Extends\Module\Weline_Framework\Changed\Type\ThemeLayoutChangedType;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

final class ThemeLayoutSlugPurgeContractTest extends TestCase
{
    public function testUpsertWithSlugsPurgesFpcEvenWhenDraftStatus(): void
    {
        /** @var ResourceChangeFactory $factory */
        $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
        $change = $factory->create(
            resourceType: 'theme_layout',
            resourceId: 'policy-privacy',
            action: 'upsert',
            revision: time(),
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: ['layout_type' => 'policy', 'layout_option' => 'privacy', 'status' => 'draft', 'purge_current' => true],
            changedFields: ['storefront_slug_fpc'],
            impact: [
                'namespaces' => ['website/default/theme'],
                'slugs' => ['policy/privacy'],
            ],
            origin: ['entry' => 'unit'],
        );
        $type = new ThemeLayoutChangedType();
        $enriched = $type->enrich($change);
        self::assertSame(['policy/privacy'], $enriched['slugs']);
        self::assertNotContains('/', $enriched['urls']);

        $codes = [];
        foreach ($type->recipe($change) as $effect) {
            $codes[] = $effect->code;
        }
        self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
        self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
    }

    public function testDraftWithoutSlugsOnlyBumpsNamespaces(): void
    {
        /** @var ResourceChangeFactory $factory */
        $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
        $change = $factory->create(
            resourceType: 'theme_layout',
            resourceId: 'home',
            action: 'upsert',
            revision: time(),
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: ['status' => 'draft'],
            changedFields: ['layout'],
            impact: [
                'namespaces' => ['website/default/theme'],
            ],
            origin: ['entry' => 'unit'],
        );
        $type = new ThemeLayoutChangedType();
        $codes = [];
        foreach ($type->recipe($change) as $effect) {
            $codes[] = $effect->code;
        }
        self::assertSame([InvalidationEffect::CODE_BUMP_NAMESPACES], $codes);
    }

    public function testPublisherResolvesPolicySlug(): void
    {
        $publisher = new ThemeStorefrontSlugFpcPublisher();
        self::assertSame('policy/privacy', $publisher->resolveSlug('policy', 'privacy'));
        self::assertTrue($publisher->isActiveStorefrontSlug('policy', 'privacy'));
        self::assertFalse($publisher->isActiveStorefrontSlug('homepage', 'default'));
    }

    public function testEditorExposesPurgeCurrentStorefrontApi(): void
    {
        $editor = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php');
        $provider = \file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );
        self::assertIsString($editor);
        self::assertIsString($provider);
        self::assertStringContainsString('function purgeCurrentStorefrontPayload(', $editor);
        self::assertStringContainsString('purge-current-storefront', $provider);
        self::assertStringContainsString('ThemeStorefrontSlugFpcPublisher', $editor);
    }
}
