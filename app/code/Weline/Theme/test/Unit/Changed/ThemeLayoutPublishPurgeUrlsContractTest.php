<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Extends\Module\Weline_Framework\Changed\Type\ThemeLayoutChangedType;

final class ThemeLayoutPublishPurgeUrlsContractTest extends TestCase
{
    public function testPublishWithConcreteUrlsUsesPurgeFpcUrlsNotAll(): void
    {
        /** @var ResourceChangeFactory $factory */
        $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
        $change = $factory->create(
            resourceType: 'theme_layout',
            resourceId: 'policy-privacy',
            action: 'publish',
            revision: time(),
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: ['layout_type' => 'policy', 'layout_option' => 'privacy', 'status' => 'published'],
            changedFields: ['published_release'],
            impact: [
                'namespaces' => ['website/default/theme'],
                'urls' => ['/policy/privacy'],
            ],
            origin: ['entry' => 'unit'],
        );
        $type = new ThemeLayoutChangedType();
        $codes = [];
        foreach ($type->recipe($change) as $effect) {
            self::assertInstanceOf(InvalidationEffect::class, $effect);
            $codes[] = $effect->code;
        }
        self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
        self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
    }

    public function testPublishWithWebsiteContextNeverPurgesAllEvenWithoutUrls(): void
    {
        /** @var ResourceChangeFactory $factory */
        $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
        $change = $factory->create(
            resourceType: 'theme_layout',
            resourceId: 'whole-theme',
            action: 'publish',
            revision: time(),
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: ['status' => 'published'],
            changedFields: ['published_release'],
            impact: [
                'namespaces' => ['website/default/theme'],
                'urls' => [],
            ],
            origin: ['entry' => 'unit'],
        );
        $type = new ThemeLayoutChangedType();
        $codes = [];
        foreach ($type->recipe($change) as $effect) {
            $codes[] = $effect->code;
        }
        self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
        self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
    }
}
