<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Changed;

// 本测试直接触 ObjectManager（BP 依赖引导常量）；Pest 裸跑时无 TestCore 预引导，
// 按模块测试约定显式引入 app 引导（同 Ui/DesignSystemContractTest.php）。
require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Extends\Module\Weline_Framework\Changed\Type\ThemeLayoutChangedType;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeChangedContractsPass().
 */
final class ThemeChangedContractsTest extends TestCase
{
    public function testAllThemeChangedContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ThemeLayoutPublishPurgeUrls::testPublishWithConcreteUrlsUsesPurgeFpcUrlsNotAll' => function () {
            $this->assert_ThemeLayoutPublishPurgeUrls_testPublishWithConcreteUrlsUsesPurgeFpcUrlsNotAll();
        },
            'ThemeLayoutPublishPurgeUrls::testPublishWithWebsiteContextNeverPurgesAllEvenWithoutUrls' => function () {
            $this->assert_ThemeLayoutPublishPurgeUrls_testPublishWithWebsiteContextNeverPurgesAllEvenWithoutUrls();
        },
            'ThemeLayoutSlugPurge::testUpsertWithSlugsPurgesFpcEvenWhenDraftStatus' => function () {
            $this->assert_ThemeLayoutSlugPurge_testUpsertWithSlugsPurgesFpcEvenWhenDraftStatus();
        },
            'ThemeLayoutSlugPurge::testDraftWithoutSlugsOnlyBumpsNamespaces' => function () {
            $this->assert_ThemeLayoutSlugPurge_testDraftWithoutSlugsOnlyBumpsNamespaces();
        },
            'ThemeLayoutSlugPurge::testPublisherResolvesPolicySlug' => function () {
            $this->assert_ThemeLayoutSlugPurge_testPublisherResolvesPolicySlug();
        },
            'ThemeLayoutSlugPurge::testEditorExposesPurgeCurrentStorefrontApi' => function () {
            $this->assert_ThemeLayoutSlugPurge_testEditorExposesPurgeCurrentStorefrontApi();
        },
            'ThemeLayoutSlugPurge::testPublisherExposesStorefrontPathPurgeWithoutPurgeAll' => function () {
            $this->assert_ThemeLayoutSlugPurge_testPublisherExposesStorefrontPathPurgeWithoutPurgeAll();
        },
        ];
    }

private function assert_ThemeLayoutPublishPurgeUrls_testPublishWithConcreteUrlsUsesPurgeFpcUrlsNotAll(): void
{
    /** @var ResourceChangeFactory $factory */
    $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
    $change = $factory->create(resourceType: 'theme_layout', resourceId: 'policy-privacy', action: 'publish', revision: time(), websiteId: 0, websiteCode: 'default', before: [], after: ['layout_type' => 'policy', 'layout_option' => 'privacy', 'status' => 'published'], changedFields: ['published_release'], impact: ['namespaces' => ['website/default/theme'], 'urls' => ['/policy/privacy']], origin: ['entry' => 'unit']);
    $type = new ThemeLayoutChangedType();
    $codes = [];
    foreach ($type->recipe($change) as $effect) {
        self::assertInstanceOf(InvalidationEffect::class, $effect);
        $codes[] = $effect->code;
    }
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
    self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
}

private function assert_ThemeLayoutPublishPurgeUrls_testPublishWithWebsiteContextNeverPurgesAllEvenWithoutUrls(): void
{
    /** @var ResourceChangeFactory $factory */
    $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
    $change = $factory->create(resourceType: 'theme_layout', resourceId: 'whole-theme', action: 'publish', revision: time(), websiteId: 0, websiteCode: 'default', before: [], after: ['status' => 'published'], changedFields: ['published_release'], impact: ['namespaces' => ['website/default/theme'], 'urls' => []], origin: ['entry' => 'unit']);
    $type = new ThemeLayoutChangedType();
    $codes = [];
    foreach ($type->recipe($change) as $effect) {
        $codes[] = $effect->code;
    }
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
    self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
}

private function assert_ThemeLayoutSlugPurge_testUpsertWithSlugsPurgesFpcEvenWhenDraftStatus(): void
{
    /** @var ResourceChangeFactory $factory */
    $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
    $change = $factory->create(resourceType: 'theme_layout', resourceId: 'policy-privacy', action: 'upsert', revision: time(), websiteId: 0, websiteCode: 'default', before: [], after: ['layout_type' => 'policy', 'layout_option' => 'privacy', 'status' => 'draft', 'purge_current' => true], changedFields: ['storefront_slug_fpc'], impact: ['namespaces' => ['website/default/theme'], 'slugs' => ['policy/privacy']], origin: ['entry' => 'unit']);
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

private function assert_ThemeLayoutSlugPurge_testDraftWithoutSlugsOnlyBumpsNamespaces(): void
{
    /** @var ResourceChangeFactory $factory */
    $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
    $change = $factory->create(resourceType: 'theme_layout', resourceId: 'home', action: 'upsert', revision: time(), websiteId: 0, websiteCode: 'default', before: [], after: ['status' => 'draft'], changedFields: ['layout'], impact: ['namespaces' => ['website/default/theme']], origin: ['entry' => 'unit']);
    $type = new ThemeLayoutChangedType();
    $codes = [];
    foreach ($type->recipe($change) as $effect) {
        $codes[] = $effect->code;
    }
    self::assertSame([InvalidationEffect::CODE_BUMP_NAMESPACES], $codes);
}

private function assert_ThemeLayoutSlugPurge_testPublisherResolvesPolicySlug(): void
{
    $publisher = new ThemeStorefrontSlugFpcPublisher();
    self::assertSame('policy/privacy', $publisher->resolveSlug('policy', 'privacy'));
    self::assertTrue($publisher->isActiveStorefrontSlug('policy', 'privacy'));
    self::assertFalse($publisher->isActiveStorefrontSlug('homepage', 'default'));
}

private function assert_ThemeLayoutSlugPurge_testEditorExposesPurgeCurrentStorefrontApi(): void
{
    $editor = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php');
    $provider = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
    self::assertIsString($editor);
    self::assertIsString($provider);
    self::assertStringContainsString('function purgeCurrentStorefrontPayload(', $editor);
    self::assertStringContainsString('purge-current-storefront', $provider);
    self::assertStringContainsString('ThemeStorefrontSlugFpcPublisher', $editor);
}

private function assert_ThemeLayoutSlugPurge_testPublisherExposesStorefrontPathPurgeWithoutPurgeAll(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeStorefrontSlugFpcPublisher.php');
    self::assertStringContainsString('function purgeByStorefrontPath', $src);
    self::assertStringContainsString('path_not_recognized', $src);
    self::assertStringNotContainsString('purge_fpc_all', $src);
    self::assertStringNotContainsString('CODE_PURGE_FPC_ALL', $src);
}
}
