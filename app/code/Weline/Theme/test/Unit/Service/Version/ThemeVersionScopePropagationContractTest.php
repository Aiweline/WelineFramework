<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Version;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\Version\ThemeVersionPublicationService;
use Weline\Theme\Service\Version\ThemeVersionScopePropagator;
use Weline\Theme\Service\Version\ThemeVersionSelectionResolver;
use Weline\Theme\Service\Version\ThemeVersionSnapshotBuilder;

/**
 * UC-08 写入端传播的行为契约。
 *
 * 这些断言直接跑真实分类/解析代码（不是对源码做字符串匹配），
 * 覆盖三桶判定、祖先链回退与链式发布解析。
 */
final class ThemeVersionScopePropagationContractTest extends TestCase
{
    /**
     * @param array<string, list<string>> $chains scope => 近→远祖先链
     */
    private function propagator(array $chains, bool $hierarchyAvailable = true): ThemeVersionScopePropagator
    {
        $publication = new ThemeVersionPublicationService(
            new ThemeVersionSnapshotBuilder(),
            new ThemeVersionSelectionResolver(),
        );

        return new ThemeVersionScopePropagator(
            $publication,
            new ThemeVersionScopePropagationFakeHierarchy($chains, $hierarchyAvailable),
        );
    }

    public function testChangedResourcesSkipsUnknownFingerprintsOnBothSides(): void
    {
        $changed = ThemeVersionScopePropagator::detectChangedResources(
            ['layout:homepage', 'chrome', 'appearance'],
            ['layout:homepage' => 'old', 'chrome' => '', 'appearance' => ''],
            ['layout:homepage' => 'new', 'chrome' => '', 'appearance' => ''],
        );

        // 两侧都为空说明指纹未知，不能据此判定变化（否则会凭空造后代版本）。
        self::assertSame(['layout:homepage'], $changed);
    }

    public function testChangedResourcesFallsBackToFingerprintKeysWhenPublishSetIsWildcard(): void
    {
        $changed = ThemeVersionScopePropagator::detectChangedResources(
            ['*'],
            ['chrome' => 'a'],
            ['chrome' => 'b'],
        );

        self::assertSame(['chrome'], $changed);
    }

    public function testClassifyDescendantWithoutLocalOverrideFallsBack(): void
    {
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            0,
            ['chrome'],
            ['chrome' => 'p'],
            [],
            false,
        );

        self::assertFalse($classified['has_local_override']);
        self::assertFalse($classified['effective_changed']);
        self::assertFalse($classified['conflict']);
    }

    public function testClassifyDescendantKeepsVersionWhenLocalOverrideShieldsEveryChange(): void
    {
        // 父改了 chrome，但后代自己也覆盖了 chrome → 有效值不变 → 保留 C。
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['chrome'],
            ['chrome' => 'parent-fingerprint'],
            ['chrome' => 'descendant-fingerprint'],
            false,
        );

        self::assertTrue($classified['has_local_override']);
        self::assertFalse($classified['effective_changed']);
        self::assertSame([], $classified['inherited_resources']);
        self::assertSame(['chrome'], $classified['overridden_resources']);
    }

    public function testClassifyDescendantGeneratesDerivedVersionWhenChangePassesThrough(): void
    {
        // 后代只覆盖了 chrome，homepage 是继承来的 → 父的 homepage 变更会穿透 → 需要 C'。
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['layout:homepage', 'chrome'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-old'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-mine'],
            false,
        );

        self::assertTrue($classified['effective_changed']);
        self::assertFalse($classified['conflict']);
        self::assertSame(['layout:homepage'], $classified['inherited_resources']);
        self::assertSame(['chrome'], $classified['overridden_resources']);
    }

    public function testClassifyDescendantTreatsEmptyDescendantFingerprintAsInherited(): void
    {
        // 后代从未烘焙过该资源（指纹为空）说明它在那里没有自己的内容，
        // 不能被当成「本级覆盖」——否则纯继承的普通情况永远生成不出 C'。
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['chrome'],
            ['chrome' => 'parent-fingerprint'],
            ['chrome' => ''],
            false,
        );

        self::assertTrue($classified['has_local_override']);
        self::assertTrue($classified['effective_changed']);
        self::assertSame(['chrome'], $classified['inherited_resources']);
        self::assertSame([], $classified['overridden_resources']);
    }

    public function testClassifyDescendantReportsConflictWhenParentChromeStructureChanged(): void
    {
        // 父同时改了 homepage 与 chrome 结构；后代有自己的 chrome 但 homepage 是继承的。
        // homepage 的变更会穿透（effective_changed=true），chrome 又被后代本地占有且父结构变了
        // → 结构冲突：此时不能把页面换成 C' 却把 chrome 留在 C，必须整体保留 C。
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['layout:homepage', 'chrome'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-old'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-mine'],
            true,
        );

        self::assertTrue($classified['effective_changed']);
        self::assertTrue($classified['conflict']);
        self::assertSame(['layout:homepage'], $classified['inherited_resources']);
        self::assertSame(['chrome'], $classified['overridden_resources']);
    }

    public function testClassifyDescendantHasNoConflictWhenParentStructureUnchanged(): void
    {
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['layout:homepage', 'chrome'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-old'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-mine'],
            false,
        );

        self::assertTrue($classified['effective_changed']);
        self::assertFalse($classified['conflict']);
    }

    public function testClassifyDescendantHasNoConflictWhenDescendantDoesNotOwnChrome(): void
    {
        // 父结构变了，但后代没有自己的 chrome → 无歧义，走 C' 而不是冲突。
        $classified = ThemeVersionScopePropagator::classifyDescendant(
            42,
            ['layout:homepage', 'chrome'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-old'],
            ['layout:homepage' => 'home-old', 'chrome' => 'chrome-old'],
            true,
        );

        self::assertTrue($classified['effective_changed']);
        self::assertFalse($classified['conflict']);
    }

    public function testPlanDescendantPropagationSplitsClassifierOutputIntoThreeBuckets(): void
    {
        $rows = [
            ['owner_hash' => 'fallback-owner', 'has_local_override' => false, 'effective_changed' => false, 'conflict' => false],
            ['owner_hash' => 'kept-owner', 'has_local_override' => true, 'effective_changed' => false, 'conflict' => false],
            ['owner_hash' => 'updated-owner', 'has_local_override' => true, 'effective_changed' => true, 'conflict' => false],
            ['owner_hash' => 'conflict-owner', 'has_local_override' => true, 'effective_changed' => true, 'conflict' => true],
        ];

        $publication = new ThemeVersionPublicationService(
            new ThemeVersionSnapshotBuilder(),
            new ThemeVersionSelectionResolver(),
        );
        $plan = $publication->planDescendantPropagation($rows);

        self::assertSame(['fallback-owner'], $plan['fallback']);
        self::assertSame(['updated-owner'], $plan['updates']);
        self::assertSame(['conflict-owner'], $plan['conflicts']);
        // 保留 C 的后代不出现在任何桶里：它不需要任何写入。
        self::assertNotContains('kept-owner', $plan['updates']);
        self::assertNotContains('kept-owner', $plan['conflicts']);
        self::assertNotContains('kept-owner', $plan['fallback']);
    }

    public function testIsStrictDescendantFollowsHierarchyChain(): void
    {
        $propagator = $this->propagator([
            'w.s.c' => ['w.s.c', 'w.s.default', 'w.default.default', 'default.default.default'],
            'w.s.default' => ['w.s.default', 'w.default.default', 'default.default.default'],
            'w.default.default' => ['w.default.default', 'default.default.default'],
        ]);

        self::assertTrue($propagator->isStrictDescendant('w.default.default', 'w.s.c'));
        self::assertTrue($propagator->isStrictDescendant('w.s.default', 'w.s.c'));
        // 自身不是自己的后代。
        self::assertFalse($propagator->isStrictDescendant('w.s.c', 'w.s.c'));
        // 祖先不是后代。
        self::assertFalse($propagator->isStrictDescendant('w.s.c', 'w.default.default'));
        self::assertFalse($propagator->isStrictDescendant('', 'w.s.c'));
    }

    public function testAncestorChainFallsBackToDottedTrimWhenHierarchyUnavailable(): void
    {
        $propagator = $this->propagator([], false);

        self::assertSame(
            ['a.b.c', 'a.b', 'a'],
            $propagator->scopeAncestorChain('a.b.c'),
        );
        self::assertSame([], $propagator->scopeAncestorChain('   '));
    }

    public function testResolvePublishedChainedPrefersLocalThenWalksAncestors(): void
    {
        $resolver = new ThemeVersionSelectionResolver();
        $owner = new ThemeVersionIdentity(
            themeId: 3,
            canonicalScope: 'w.s.c',
            storeMode: 'normal',
            area: ThemeVersionIdentity::AREA_FRONTEND,
        );

        $resolved = $resolver->resolvePublishedChained($owner, [
            ['scope' => 'w.s.c', 'selection' => ['published_version_id' => 0]],
            ['scope' => 'w.s.default', 'selection' => ['published_version_id' => 0]],
            ['scope' => 'w.default.default', 'selection' => [
                'published_version_id' => 7,
                'published_content_revision' => 2,
            ]],
        ]);

        self::assertNotNull($resolved);
        self::assertSame(7, $resolved['identity']->themeVersionId);
        self::assertSame(ThemeVersionIdentity::MODE_FORMAL, $resolved['identity']->mode);
        self::assertSame(2, $resolved['identity']->contentRevision);
        self::assertSame('w.default.default', $resolved['source_scope']);
        self::assertTrue($resolved['inherited']);
        // 身份必须落在真正提供版本的 owner 上，否则会读到别的 scope 的产物。
        self::assertSame('w.default.default', $resolved['identity']->canonicalScope);
    }

    public function testResolvePublishedChainedMarksLocalHitAsNotInherited(): void
    {
        $resolver = new ThemeVersionSelectionResolver();
        $owner = new ThemeVersionIdentity(
            themeId: 3,
            canonicalScope: 'w.s.c',
            storeMode: 'normal',
            area: ThemeVersionIdentity::AREA_FRONTEND,
        );

        $resolved = $resolver->resolvePublishedChained($owner, [
            ['scope' => 'w.s.c', 'selection' => ['published_version_id' => 11, 'published_content_revision' => 1]],
            ['scope' => 'w.default.default', 'selection' => ['published_version_id' => 7]],
        ]);

        self::assertNotNull($resolved);
        self::assertSame(11, $resolved['identity']->themeVersionId);
        self::assertFalse($resolved['inherited']);
    }

    public function testResolvePublishedChainedReturnsNullWhenNothingPublished(): void
    {
        $resolver = new ThemeVersionSelectionResolver();
        $owner = new ThemeVersionIdentity(
            themeId: 3,
            canonicalScope: 'w.s.c',
            storeMode: 'normal',
            area: ThemeVersionIdentity::AREA_FRONTEND,
        );

        self::assertNull($resolver->resolvePublishedChained($owner, [
            ['scope' => 'w.s.c', 'selection' => ['published_version_id' => 0]],
            ['scope' => 'w.default.default', 'selection' => null],
        ]));
    }

    public function testResolveDraftIsNeverInheritedFromAncestors(): void
    {
        $resolver = new ThemeVersionSelectionResolver();
        $owner = new ThemeVersionIdentity(
            themeId: 3,
            canonicalScope: 'w.s.c',
            storeMode: 'normal',
            area: ThemeVersionIdentity::AREA_FRONTEND,
        );

        // 只有 published、没有 draft → 草稿必须为 null（不能把祖先/正式版伪装成本级草稿）。
        self::assertNull($resolver->resolveDraft($owner, [
            'published_version_id' => 7,
            'draft_version_id' => null,
        ]));
    }
}

/**
 * 只实现传播器用到的两个层级方法；其余方法在本契约测试中不可达。
 *
 * chainFromIdentity 无入参可辨 scope，因此由 fromStorageScope 记录最近一次请求的 scope，
 * 再按该 scope 返回对应链 —— 否则所有 scope 会拿到同一条链，后代判定就失去意义。
 */
final class ThemeVersionScopePropagationFakeHierarchy implements ScopeHierarchyInterface
{
    private string $lastScope = '';

    /**
     * @param array<string, list<string>> $chains
     */
    public function __construct(
        private readonly array $chains,
        private readonly bool $available = true,
    ) {
    }

    public function contextFromIdentity(ScopeIdentity $identity): ScopeContext
    {
        throw new \LogicException('not_used_in_contract_test');
    }

    public function contextFromClaims(array $claims, ScopeIdentity $authoritativeIdentity): ScopeContext
    {
        throw new \LogicException('not_used_in_contract_test');
    }

    public function chainFromIdentity(ScopeIdentity $identity): array
    {
        return $this->chains[$this->lastScope] ?? [];
    }

    public function parentIdentity(ScopeIdentity $identity): ?ScopeIdentity
    {
        return null;
    }

    public function toStorageScope(ScopeIdentity $identity): string
    {
        return $this->lastScope;
    }

    public function fromStorageScope(string $storageScope, bool $allowLegacy = true): ?ScopeIdentity
    {
        $this->lastScope = \trim($storageScope);

        return $this->available ? ScopeIdentity::global() : null;
    }

    public function assertWritableRawScope(?string $rawScope): void
    {
    }
}
