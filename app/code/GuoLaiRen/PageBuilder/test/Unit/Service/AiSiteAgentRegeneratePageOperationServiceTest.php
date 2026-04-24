<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * Characterization Test：锁定 runRegeneratePageOperation 从控制器抽出后的行为。
 *
 * 覆盖：
 *  - 参数校验：pageType 空 / 不在作用域内 → RuntimeException
 *  - html_blocks 轨道：使用占位符 blocks，不调 ensureAiGeneratedVirtualTheme / bindVirtualTheme，
 *    返回 virtual_theme_id=0，active_operation.message='页面区块已重建'
 *  - virtual_theme 轨道（默认）：调 ensureAiGeneratedVirtualTheme + bindVirtualTheme，
 *    返回 virtual_theme_id=theme['virtual_theme_id']，progress 发送两次（20/100）
 *  - 公共：assertActiveStreamLeaseAlive 先被调用、每个 section 走一次 markTaskDone、
 *    replaceScope + appendWorkspaceEvent 均被调用
 */
final class AiSiteAgentRegeneratePageOperationServiceTest extends TestCase
{
    private function session(int $id = 101, int $websiteId = 9): AiSiteAgentSession
    {
        $mock = $this->createMock(AiSiteAgentSession::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getWebsiteId')->willReturn($websiteId);
        $mock->method('getScopeArray')->willReturn([
            'draft_website_id' => 3,
            'website_id' => 9,
            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
        ]);
        return $mock;
    }

    private function sse(): SseWriter
    {
        return $this->createStub(SseWriter::class);
    }

    /**
     * 公共 ports 工厂；通过 $state 数组捕获调用痕迹，便于各 test 独立断言。
     *
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $state
     */
    private function buildPorts(array &$state, array $overrides = []): AiSiteAgentRegeneratePageOperationPorts
    {
        $state['calls'] = [
            'assertActiveStreamLeaseAlive' => 0,
            'sendOperationProgress' => [],
            'markTaskDone' => [],
            'replaceScope' => [],
            'bindVirtualTheme' => [],
            'appendWorkspaceEvent' => [],
            'ensureAiGeneratedVirtualTheme' => 0,
            'buildPlaceholderBlocksForPageType' => 0,
        ];

        $defaults = [
            'assertActiveStreamLeaseAlive' => function (AiSiteAgentSession $s, int $a) use (&$state): void {
                $state['calls']['assertActiveStreamLeaseAlive']++;
            },
            'normalizeScope' => fn (array $scope): array => $scope,
            'resolveScopedPageTypes' => fn (array $scope): array => ['home', 'about'],
            'generateProfile' => fn (array $scope): array => ['brand_name' => 'demo'],
            'ensureTaskScope' => fn (array $scope, array $profile, string $track): array => $scope,
            'resetPageTasksForRetry' => fn (array $scope, string $pageType): array => $scope,
            'normalizeWorkspaceTrack' => fn (string $t): string => $t === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                ? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            'resolvePageTypeLabels' => fn (): array => ['home' => '首页', 'about' => '关于'],
            'sendOperationProgress' => function ($sse, $session, $adminId, $stage, $op, $msg, $percent, $pageType) use (&$state): void {
                $state['calls']['sendOperationProgress'][] = [
                    'stage' => $stage,
                    'op' => $op,
                    'percent' => $percent,
                    'page_type' => $pageType,
                ];
            },
            'buildVirtualPagesByType' => fn (array $pageTypes, array $scope): array => [
                'home' => ['title' => 'legacy-home', 'ai_description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => ''],
                'about' => ['title' => 'legacy-about'],
            ],
            'buildPageBlueprint' => fn (string $pageType, array $scope, array $profile): array => [
                'page_title' => 'new-' . $pageType,
                'ai_description' => 'desc-' . $pageType,
                'meta_title' => 'mt-' . $pageType,
                'meta_description' => 'md-' . $pageType,
                'meta_keywords' => 'mk-' . $pageType,
                'section_refinements' => ['r1'],
                'sections' => [
                    ['code' => 'hero'],
                    ['code' => 'cta'],
                    'not-an-array',
                    ['code' => ''],
                ],
            ],
            'buildPlaceholderBlocksForPageType' => function (string $pageType, array $profile, array $scope) use (&$state): array {
                $state['calls']['buildPlaceholderBlocksForPageType']++;
                return [['block_code' => 'ph-' . $pageType]];
            },
            'markTaskDone' => function (array $scope, string $key, array $meta) use (&$state): array {
                $state['calls']['markTaskDone'][] = ['key' => $key, 'meta' => $meta];
                $scope['_tasks_marked'] = ($scope['_tasks_marked'] ?? 0) + 1;
                return $scope;
            },
            'materializeGeneratedPages' => fn (string $track, int $wid, array $profile, array $keys, array $layouts, array $vpages): array => [
                '_track' => $track,
                '_wid' => $wid,
            ],
            'mergeMaterializedPagesIntoScope' => fn (array $scope, array $materialized): array => \array_merge($scope, ['_materialized' => $materialized]),
            'summarizeBuildTasks' => fn (array $scope): array => ['summary_ok' => true],
            'replaceScope' => function (int $sessionId, int $adminId, array $scope) use (&$state): void {
                $state['calls']['replaceScope'][] = [
                    'session_id' => $sessionId,
                    'admin_id' => $adminId,
                    'scope_keys' => \array_keys($scope),
                    'workspace_status' => $scope['workspace_status'] ?? null,
                    'active_operation' => $scope['active_operation'] ?? null,
                    'virtual_pages' => $scope['virtual_pages_by_type'] ?? null,
                    'preview_page_type' => $scope['preview_page_type'] ?? null,
                ];
            },
            'bindVirtualTheme' => function (int $sessionId, int $adminId, int $themeId) use (&$state): void {
                $state['calls']['bindVirtualTheme'][] = ['session_id' => $sessionId, 'admin_id' => $adminId, 'theme_id' => $themeId];
            },
            'appendWorkspaceEvent' => function (int $sessionId, int $adminId, string $stage, string $type, string $msg, array $meta) use (&$state): void {
                $state['calls']['appendWorkspaceEvent'][] = [
                    'stage' => $stage,
                    'type' => $type,
                    'message' => $msg,
                    'meta' => $meta,
                ];
            },
            'normalizePageTypeLayouts' => fn ($layouts, array $pageTypes): array => [],
            'normalizeLayoutConfig' => fn (array $cfg, string $pageType): array => ['layout' => 'default-' . $pageType],
            'ensureAiGeneratedVirtualTheme' => function (array $scope, array $profile, array $pageTypes, array $layouts, int $sid, bool $force) use (&$state): array {
                $state['calls']['ensureAiGeneratedVirtualTheme']++;
                return [
                    'virtual_theme_id' => 777,
                    'page_type_layouts' => ['home' => ['layout' => 'theme-home']],
                ];
            },
        ];

        $merged = \array_merge($defaults, $overrides);

        return new AiSiteAgentRegeneratePageOperationPorts(
            $merged['assertActiveStreamLeaseAlive'],
            $merged['normalizeScope'],
            $merged['resolveScopedPageTypes'],
            $merged['generateProfile'],
            $merged['ensureTaskScope'],
            $merged['resetPageTasksForRetry'],
            $merged['normalizeWorkspaceTrack'],
            $merged['resolvePageTypeLabels'],
            $merged['sendOperationProgress'],
            $merged['buildVirtualPagesByType'],
            $merged['buildPageBlueprint'],
            $merged['buildPlaceholderBlocksForPageType'],
            $merged['markTaskDone'],
            $merged['materializeGeneratedPages'],
            $merged['mergeMaterializedPagesIntoScope'],
            $merged['summarizeBuildTasks'],
            $merged['replaceScope'],
            $merged['bindVirtualTheme'],
            $merged['appendWorkspaceEvent'],
            $merged['normalizePageTypeLayouts'],
            $merged['normalizeLayoutConfig'],
            $merged['ensureAiGeneratedVirtualTheme'],
        );
    }

    private function svc(): AiSiteAgentRegeneratePageOperationService
    {
        return new AiSiteAgentRegeneratePageOperationService();
    }

    // ---- 参数校验 ----------------------------------------------------

    public function testThrowsWhenPageTypeEmpty(): void
    {
        $state = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('缺少要重建的页面类型');
        $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            1,
            '',
            $this->buildPorts($state)
        );
    }

    public function testThrowsWhenPageTypeNotInScope(): void
    {
        $state = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('页面类型不在当前工作区中');
        $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            1,
            'pricing',
            $this->buildPorts($state)
        );
    }

    public function testAssertsStreamLeaseAliveBeforeAnyWork(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'resolveScopedPageTypes' => function (array $scope) use (&$state): array {
                self::assertSame(1, $state['calls']['assertActiveStreamLeaseAlive'], 'lease check must run first');
                return ['home'];
            },
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 2, 'home', $ports);
        self::assertSame(1, $state['calls']['assertActiveStreamLeaseAlive']);
    }

    // ---- html_blocks 轨道 -------------------------------------------

    public function testHtmlBlocksTrackUsesPlaceholderBlocksAndSkipsVirtualTheme(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
        ]);
        $result = $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            17,
            'home',
            $ports
        );

        self::assertSame([
            'message' => '页面重建完成',
            'page_type' => 'home',
            'virtual_theme_id' => 0,
        ], $result);
        self::assertSame(0, $state['calls']['ensureAiGeneratedVirtualTheme'], 'html_blocks 不应调用 ensureAiGeneratedVirtualTheme');
        self::assertSame(1, $state['calls']['buildPlaceholderBlocksForPageType']);
        self::assertSame([], $state['calls']['bindVirtualTheme'], 'html_blocks 不应 bindVirtualTheme');
    }

    public function testHtmlBlocksTrackEmitsProgressAndFinishMessage(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(2, $state['calls']['sendOperationProgress']);
        self::assertSame(20, $state['calls']['sendOperationProgress'][0]['percent']);
        self::assertSame(100, $state['calls']['sendOperationProgress'][1]['percent']);
        self::assertSame('home', $state['calls']['sendOperationProgress'][0]['page_type']);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $replace['workspace_status']);
        self::assertSame('done', $replace['active_operation']['status']);
        self::assertSame('页面区块已重建', $replace['active_operation']['message']);
        self::assertSame('home', $replace['preview_page_type']);
    }

    public function testHtmlBlocksTrackMarksEachNonEmptySectionAsDone(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $keys = \array_column($state['calls']['markTaskDone'], 'key');
        self::assertSame(['page:home:hero', 'page:home:cta'], $keys, '空 section_code / 非数组 section 应被跳过');
    }

    public function testHtmlBlocksTrackAttachesGeneratedBlocksToRow(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $row = $state['calls']['replaceScope'][0]['virtual_pages']['home'];
        self::assertSame([['block_code' => 'ph-home']], $row['blocks']);
        self::assertSame('new-home', $row['title']);
        self::assertSame('desc-home', $row['ai_description']);
        self::assertSame('mt-home', $row['meta_title']);
        self::assertSame(['r1'], $row['section_refinements']);
        self::assertNotEmpty($row['last_generated_at']);
    }

    public function testHtmlBlocksTrackAppendsPageGeneratedEvent(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(1, $state['calls']['appendWorkspaceEvent']);
        $ev = $state['calls']['appendWorkspaceEvent'][0];
        self::assertSame('visual_edit', $ev['stage']);
        self::assertSame('page_generated', $ev['type']);
        self::assertSame('regenerate_page', $ev['meta']['operation']);
        self::assertSame('home', $ev['meta']['page_type']);
        self::assertSame(4, $ev['meta']['details']['section_count'], 'section_count 锁定为 count(sections) 原始长度，不做过滤');
        self::assertArrayNotHasKey('virtual_theme_id', $ev['meta']['details'], 'html_blocks 事件无 virtual_theme_id');
    }

    // ---- virtual_theme 轨道 -----------------------------------------

    public function testVirtualThemeTrackEnsuresThemeAndBindsIt(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $result = $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        self::assertSame([
            'message' => '页面重建完成',
            'page_type' => 'home',
            'virtual_theme_id' => 777,
        ], $result);
        self::assertSame(1, $state['calls']['ensureAiGeneratedVirtualTheme']);
        self::assertSame(0, $state['calls']['buildPlaceholderBlocksForPageType']);
        self::assertCount(1, $state['calls']['bindVirtualTheme']);
        self::assertSame(777, $state['calls']['bindVirtualTheme'][0]['theme_id']);
    }

    public function testVirtualThemeTrackFinishMessageDiffersFromHtmlBlocks(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame('done', $replace['active_operation']['status']);
        self::assertSame('页面重建完成', $replace['active_operation']['message']);
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $replace['workspace_status']);
        self::assertCount(2, $state['calls']['sendOperationProgress']);
        self::assertSame(20, $state['calls']['sendOperationProgress'][0]['percent']);
        self::assertSame(100, $state['calls']['sendOperationProgress'][1]['percent']);
    }

    public function testVirtualThemeTrackMergesBlueprintMetaIntoVirtualPage(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $row = $state['calls']['replaceScope'][0]['virtual_pages']['home'];
        self::assertSame('new-home', $row['title']);
        self::assertSame('mk-home', $row['meta_keywords']);
        self::assertSame(['r1'], $row['section_refinements']);
    }

    public function testVirtualThemeTrackAppendsEventWithVirtualThemeId(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $ev = $state['calls']['appendWorkspaceEvent'][0];
        self::assertSame('page_generated', $ev['type']);
        self::assertSame(777, $ev['meta']['details']['virtual_theme_id']);
        self::assertSame(4, $ev['meta']['details']['section_count']);
    }

    public function testVirtualThemeTrackMarksSectionsAndAppliesLayoutOverride(): void
    {
        $state = [];
        $capturedLayouts = null;
        $ports = $this->buildPorts($state, [
            'ensureAiGeneratedVirtualTheme' => function (array $scope, array $profile, array $pageTypes, array $layouts, int $sid, bool $force) use (&$state, &$capturedLayouts): array {
                $state['calls']['ensureAiGeneratedVirtualTheme']++;
                $capturedLayouts = $layouts;
                return [
                    'virtual_theme_id' => 777,
                    'page_type_layouts' => ['home' => ['layout' => 'theme-home']],
                ];
            },
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        self::assertIsArray($capturedLayouts);
        self::assertSame(['layout' => 'default-home'], $capturedLayouts['home'], 'home 布局应被重置为 normalizeLayoutConfig 默认值');
        $keys = \array_column($state['calls']['markTaskDone'], 'key');
        self::assertSame(['page:home:hero', 'page:home:cta'], $keys);
    }
}
