<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;
use Weline\Theme\Service\Version\ThemeVersionPublicationService;
use Weline\Theme\Service\Version\ThemeVersionSelectionResolver;
use Weline\Theme\Service\Version\ThemeVersionSnapshotBuilder;

final class ThemeVersionPublicationTest extends TestCase
{
    private ThemeVersionPublicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThemeVersionPublicationService(
            new ThemeVersionSnapshotBuilder(),
            new ThemeVersionSelectionResolver(),
        );
    }

    /**
     * candidate_write_ok 已删除：UI 从不发送它，缺省恒为 true，是一条永不可达的死码。
     * 客户端不能再用一个布尔值让发布静默变成 no-op ——「候选写入失败」改由控制器的
     * 封存/烘焙步骤直接判失败并中止（ThemeEditor::publishScopeVersionPayload）。
     * 这里断言该参数即使被传入也已被忽略。
     */
    public function testClientSuppliedCandidateWriteFlagNoLongerAbortsPublish(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 20, 'draft', 4);
        $result = $this->service->publish($identity, [
            'candidate_write_ok' => false,
            'expected_selection_revision' => 2,
            'actual_selection_revision' => 2,
            'current_published_version_id' => 10,
            'current_draft_version_id' => 20,
        ]);
        self::assertTrue($result['ok']);
        self::assertFalse($result['conflict']);
        self::assertSame(20, $result['published_version_id']);
        self::assertTrue($result['n_equals_d']);
    }

    public function testSelectionCasFailureDoesNotConsumeDraft(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 20, 'draft', 5);
        $result = $this->service->publish($identity, [
            'expected_selection_revision' => 2,
            'actual_selection_revision' => 3,
            'current_published_version_id' => 10,
            'current_draft_version_id' => 20,
        ]);
        self::assertFalse($result['ok']);
        self::assertTrue($result['conflict']);
        self::assertSame(10, $result['published_version_id']);
        self::assertSame(20, $result['draft_version_id']);
    }

    public function testSinglePageRemainderGoesToDraftPrime(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 20, 'draft', 3);
        $result = $this->service->publish($identity, [
            'expected_selection_revision' => 1,
            'actual_selection_revision' => 1,
            'publish_set' => ['layout:homepage'],
            'draft_resources' => ['layout:homepage', 'layout:product', 'chrome'],
            'prepared_published_resources' => ['layout:homepage', 'layout:product', 'chrome'],
            'allocated_draft_prime_id' => 21,
        ]);
        self::assertTrue($result['ok']);
        self::assertSame(20, $result['published_version_id']);
        self::assertSame(['layout:homepage'], $result['published_resources']);
        self::assertSame(['layout:product', 'chrome'], $result['remaining_draft_resources']);
        self::assertSame(21, $result['draft_version_id']);
        self::assertSame(1, $result['draft_content_revision']);
        self::assertTrue($result['n_equals_d']);
    }

    public function testNamedSealDoesNotPublish(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 20, 'draft', 6);
        $result = $this->service->seal($identity, ['published_version_id' => 10]);
        self::assertFalse($result['published']);
        self::assertSame(10, $result['published_version_id']);
        self::assertSame(20, $result['theme_version_id']);
        self::assertTrue($result['n_equals_d']);
        self::assertSame(ThemeVersionIdentity::MODE_FORMAL, $result['identity']['mode']);
    }

    public function testSaveDraftCasConflictKeepsRevision(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 20, 'draft', 3);
        $result = $this->service->saveDraft($identity, ['layout:homepage' => []], 2);
        self::assertFalse($result['ok']);
        self::assertTrue($result['conflict']);
        self::assertSame(3, $result['content_revision']);
    }

    public function testParentDescendantAtomicPlanKeepsConflictsWhole(): void
    {
        $plan = $this->service->planDescendantPropagation([
            ['owner_hash' => 'a', 'has_local_override' => false, 'effective_changed' => true, 'conflict' => false],
            ['owner_hash' => 'b', 'has_local_override' => true, 'effective_changed' => true, 'conflict' => false],
            ['owner_hash' => 'c', 'has_local_override' => true, 'effective_changed' => true, 'conflict' => true],
        ]);
        self::assertSame(['a'], $plan['fallback']);
        self::assertSame(['b'], $plan['updates']);
        self::assertSame(['c'], $plan['conflicts']);
    }

    public function testContinueCurrentReusesExistingDraft(): void
    {
        $owner = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend');
        $result = $this->service->createDraft($owner, ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT, [
            'existing_draft_version_id' => 44,
            'existing_content_revision' => 7,
        ]);
        self::assertTrue($result['reused_existing_draft']);
        self::assertSame(44, $result['theme_version_id']);
        self::assertSame(7, $result['content_revision']);
    }

    public function testExplicitHistoricalBacksUpExistingDraft(): void
    {
        $owner = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend');
        $result = $this->service->createDraft($owner, ThemeVersionPublicationInterface::CREATION_EXPLICIT_HISTORICAL, [
            'source_theme_version_id' => 9,
            'allocated_version_id' => 50,
            'existing_draft_version_id' => 44,
            'source_decisions' => [[
                'resource_identity_hash' => 'abc',
                'injection_key' => 'k',
                'decision' => 'uninstall',
            ]],
        ]);
        self::assertFalse($result['reused_existing_draft']);
        self::assertSame(50, $result['theme_version_id']);
        self::assertSame(44, $result['auto_backup']['theme_version_id']);
        self::assertSame(50, $result['widget_decisions'][0]['theme_version_id']);
    }
}
