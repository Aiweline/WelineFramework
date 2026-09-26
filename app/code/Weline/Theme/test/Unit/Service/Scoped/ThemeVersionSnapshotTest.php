<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionWidgetDecision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\Version\ThemeVersionSelectionResolver;
use Weline\Theme\Service\Version\ThemeVersionSnapshotBuilder;

final class ThemeVersionSnapshotTest extends TestCase
{
    public function testAreaModeAndVersionIsolation(): void
    {
        $builder = new ThemeVersionSnapshotBuilder();
        $a = new ThemeVersionIdentity(5, 'a.default.default', 'normal', 'frontend', 10, 'draft', 2);
        $b = new ThemeVersionIdentity(5, 'b.default.default', 'normal', 'frontend', 10, 'draft', 2);
        $c = new ThemeVersionIdentity(5, 'a.default.default', 'test', 'frontend', 10, 'draft', 2);
        $d = new ThemeVersionIdentity(5, 'a.default.default', 'normal', 'backend', 10, 'draft', 2);
        $e = $a->withVersion(11, 'formal', 2);

        self::assertTrue($builder->compareOwnerIsolation($a, $b)['isolated']);
        self::assertTrue($builder->compareOwnerIsolation($a, $c)['isolated']);
        self::assertTrue($builder->compareOwnerIsolation($a, $d)['isolated']);
        self::assertTrue($builder->compareOwnerIsolation($a, $e)['isolated']);
        self::assertSame($a->ownerHash(), $e->ownerHash());
        self::assertNotSame($a->scopeKey(), $c->scopeKey());
        self::assertNotSame($a->cacheKey(), $e->cacheKey());
        self::assertNotSame(
            $a->cacheKey(),
            $a->withVersion(10, 'formal', 2)->cacheKey(),
            'draft and formal of same V must not share HotCache keys',
        );
    }

    public function testThemeBindingHasNoVersionCycle(): void
    {
        $builder = new ThemeVersionSnapshotBuilder();
        $scope = new ScopeContext(
            identity: ScopeIdentity::website(0, 'default'),
            storageScope: 'default.default.default',
            storeMode: ScopeIdentity::MODE_NORMAL,
            fallbackStorageScopes: ['default.default.default'],
        );
        $binding = new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
            themeId: 99,
        );
        // 绑定身份恒不内嵌主题 id，故身份哈希与版本号无关 —— 这就是「无循环」的可证形式。
        self::assertSame(0, $binding->identityThemeId());
        $builder->assertThemeBindingHasNoVersionCycle($binding, ThemeScopeWorkspace::THEME_VERSION_EXTERNAL);

        // 守卫只对绑定生效：换成版本内资源后，带真实版本号也不得被它拦下。
        // （旧实现末尾还有一段「身份不得含版本号」的分支，但版本号非 0 时前面已抛错，
        //   该分支恒不可达，已删除。）
        $builder->assertThemeBindingHasNoVersionCycle(
            $binding->withResource(ThemeEditorContext::RESOURCE_LAYOUT),
            42,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_binding_must_not_own_theme_version');
        $builder->assertThemeBindingHasNoVersionCycle($binding, 42);
    }

    public function testWidgetDecisionsBelongToTargetVersion(): void
    {
        $builder = new ThemeVersionSnapshotBuilder();
        $copied = $builder->copyDecisionsToTargetVersion(
            [
                [
                    'theme_version_id' => 7,
                    'resource_identity_hash' => \hash('sha256', 'chrome'),
                    'injection_key' => 'Weline_Demo::footer',
                    'decision' => ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL,
                    'slot_identity' => 'footer',
                    'widget_identity' => 'Weline_Demo::footer',
                    'actor_id' => 'admin',
                ],
            ],
            100,
            3,
            7,
        );

        self::assertCount(1, $copied);
        self::assertSame(100, $copied[0]['theme_version_id']);
        self::assertSame(3, $copied[0]['content_revision']);
        self::assertSame(7, $copied[0]['source_version_id']);
        self::assertSame(ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL, $copied[0]['decision']);
    }

    public function testDryRunArchivesUnprovenHistoryAndBlocksOpaqueCurrentIntent(): void
    {
        $builder = new ThemeVersionSnapshotBuilder();
        $report = $builder->dryRunConvert(
            [
                [
                    'version_id' => 1,
                    'theme_id' => 3,
                    'scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => 'frontend',
                    'is_published' => 1,
                    'is_current' => 0,
                    'has_revision_patch_evidence' => true,
                    'widget_decisions' => [
                        [
                            'resource_identity_hash' => 'abc',
                            'injection_key' => 'k1',
                            'decision' => ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL,
                        ],
                    ],
                ],
                [
                    'version_id' => 2,
                    'theme_id' => 3,
                    'scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => 'frontend',
                    'is_published' => 0,
                    'is_current' => 0,
                ],
                [
                    'version_id' => 3,
                    'theme_id' => 3,
                    'scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => '',
                    'is_published' => 1,
                ],
                [
                    'version_id' => 4,
                    'theme_id' => 3,
                    'scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => 'frontend',
                    'is_published' => 1,
                    'effective_payload_without_source' => true,
                ],
            ],
            [],
            [],
            'test-receipt-1',
        );

        self::assertSame('test-receipt-1', $report['receipt_id']);
        self::assertSame('dry-run', $report['mode']);
        self::assertCount(1, $report['mappings']);
        self::assertSame(1, $report['mappings'][0]['legacy_version_id']);
        self::assertFalse($report['mappings'][0]['legacy_number_reused']);
        self::assertGreaterThan(0, $report['mappings'][0]['new_theme_version_id']);
        self::assertSame(
            $report['mappings'][0]['new_theme_version_id'],
            $report['mappings'][0]['widget_decisions'][0]['theme_version_id'],
        );
        self::assertSame(ThemeScopeVersion::LIFECYCLE_SEALED, $report['mappings'][0]['lifecycle']);

        $archiveReasons = \array_column($report['archive_only'], 'reason');
        self::assertContains('insufficient_page_chrome_decision_evidence', $archiveReasons);
        self::assertContains('chrome_missing_area_without_consumer_proof', $archiveReasons);

        $blockedReasons = \array_column($report['blocked'], 'reason');
        self::assertContains('current_state_lacks_intent_provenance', $blockedReasons);
        self::assertNotEmpty($report['fingerprint']);
    }

    public function testSelectionResolverSeparatesPublishedAndDraft(): void
    {
        $resolver = new ThemeVersionSelectionResolver();
        $owner = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend');
        $selection = [
            'published_version_id' => 10,
            'draft_version_id' => 11,
            'published_content_revision' => 4,
            'draft_content_revision' => 5,
            'selection_revision' => 2,
        ];
        $published = $resolver->resolvePublished($owner, $selection);
        $draft = $resolver->resolveDraft($owner, $selection);
        self::assertSame(10, $published->themeVersionId);
        self::assertSame(ThemeVersionIdentity::MODE_FORMAL, $published->mode);
        self::assertSame(4, $published->contentRevision);
        self::assertNotNull($draft);
        self::assertSame(11, $draft->themeVersionId);
        self::assertSame(ThemeVersionIdentity::MODE_DRAFT, $draft->mode);
        self::assertSame(5, $draft->contentRevision);
    }

    public function testConvertCommandExistsWithDryRunAndApplyEntry(): void
    {
        $command = \dirname(__DIR__, 4) . '/Console/Theme/Layout/ConvertVersionArtifacts.php';
        self::assertFileExists($command);
        $src = (string)\file_get_contents($command);
        self::assertStringContainsString('theme:layout:convert-version-artifacts', $src);
        self::assertStringContainsString('--dry-run', $src);
        self::assertStringContainsString('--apply', $src);
        self::assertStringContainsString('ThemeVersionArtifactConverter', $src);
        self::assertStringContainsString("mode === 'apply'", $src);
        self::assertStringNotContainsString('apply 模式在任务 6 之前不可执行', $src);
        $converter = \dirname(__DIR__, 4) . '/Service/Version/ThemeVersionArtifactConverter.php';
        $converterSrc = (string)\file_get_contents($converter);
        self::assertStringContainsString('function apply(', $converterSrc);
        self::assertStringContainsString('function loadLegacySnapshotFromDatabase(', $converterSrc);
        self::assertStringNotContainsString('database_snapshot_loader_requires_task6', $converterSrc);
    }
}
