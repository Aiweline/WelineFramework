<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteQueueStateService;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the freshly extracted `AiSiteQueueStateService`.
 *
 * These tests lock the existing observable behavior (field names, aliases, masking rule)
 * previously implemented inside `AiSiteAgent.php` so future edits do not accidentally
 * break queue panel / SSE payload consumers.
 */
final class AiSiteQueueStateServiceTest extends TestCase
{
    public function testNormalizeTokenCountRejectsNegativeAndNonNumeric(): void
    {
        $service = new AiSiteQueueStateService();

        self::assertSame(10, $service->normalizeTokenCount(10));
        self::assertSame(10, $service->normalizeTokenCount('10'));
        self::assertSame(11, $service->normalizeTokenCount(10.7));
        self::assertNull($service->normalizeTokenCount(-1));
        self::assertNull($service->normalizeTokenCount('abc'));
        self::assertNull($service->normalizeTokenCount(''));
        self::assertNull($service->normalizeTokenCount(null));
    }

    public function testNormalizeTokenUsageAcceptsOpenAiAliasesAndFillsTotal(): void
    {
        $service = new AiSiteQueueStateService();

        $normalized = $service->normalizeTokenUsage([
            'prompt_tokens' => 123,
            'completion_tokens' => 77,
            'token_cost_meta' => ['model' => 'gpt-4o-mini'],
        ]);

        self::assertSame(123, $normalized['input_tokens']);
        self::assertSame(77, $normalized['output_tokens']);
        self::assertSame(200, $normalized['total_tokens']);
        self::assertSame(['model' => 'gpt-4o-mini'], $normalized['token_cost_meta']);
    }

    public function testNormalizeTokenUsagePrefersNestedTokenUsageAndExplicitTotal(): void
    {
        $service = new AiSiteQueueStateService();

        $normalized = $service->normalizeTokenUsage([
            'token_usage' => [
                'input_tokens' => 5,
                'output_tokens' => 6,
                'total_tokens' => 999,
                'token_cost_meta' => ['cost' => 0.001],
            ],
            'prompt_tokens' => 9999,
        ]);

        self::assertSame(5, $normalized['input_tokens']);
        self::assertSame(6, $normalized['output_tokens']);
        self::assertSame(999, $normalized['total_tokens']);
        self::assertSame(['cost' => 0.001], $normalized['token_cost_meta']);
    }

    public function testBuildObserverPublicStateMasksPublicIdAndPrefersTerminalStatus(): void
    {
        $service = new AiSiteQueueStateService();

        $state = $service->buildObserverPublicState([
            'queue_id' => 42,
            'name' => 'AiSiteBuild-42',
            'module' => 'GuoLaiRen_PageBuilder',
            'biz_key' => 'ai_site_build:abc',
            'status' => 'error',
            'pid' => 1234,
            'type_id' => 7,
            'finished' => 0,
            'start_at' => '2026-04-23 16:00:00',
            'end_at' => '',
            'token_usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22],
            'content' => \json_encode([
                'public_id' => 'abcdef0123456789ffff',
                'job_key' => 'build.page.home',
                'job_type' => 'build.page',
                'status' => 'running',
                'execution_token' => 'exec-token',
            ], \JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame(42, $state['queue_id']);
        self::assertSame('AiSiteBuild-42', $state['name']);
        self::assertSame('error', $state['status']);
        self::assertSame('error', $state['job_status'], 'terminal queue status must override content-level job_status');
        self::assertSame('build.page.home', $state['job_key']);
        self::assertSame('build.page', $state['job_type']);
        self::assertSame('exec-token', $state['token']);
        self::assertSame(11, $state['token_usage']['input_tokens']);
        self::assertSame(22, $state['token_usage']['output_tokens']);
        self::assertSame(33, $state['token_usage']['total_tokens']);
        if (\defined('DEV') && DEV) {
            self::assertSame('abcdef0123456789ffff', $state['public_id_hint']);
        } else {
            self::assertSame('abcdef…ffff', $state['public_id_hint'], 'production environments must mask public_id');
        }
    }

    public function testBuildObserverPublicStateFallsBackToContentTokenUsage(): void
    {
        $service = new AiSiteQueueStateService();

        $state = $service->buildObserverPublicState([
            'queue_id' => 7,
            'status' => 'running',
            'content' => \json_encode([
                'public_id' => 'xx',
                'job_key' => 'plan.shared.theme',
                'token_usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'total_tokens' => 150,
                    'token_cost_meta' => ['source' => 'content'],
                ],
            ], \JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame(100, $state['token_usage']['input_tokens']);
        self::assertSame(50, $state['token_usage']['output_tokens']);
        self::assertSame(150, $state['token_usage']['total_tokens']);
        self::assertSame(['source' => 'content'], $state['token_usage']['token_cost_meta']);
        self::assertSame('running', $state['job_status']);
    }

    public function testBuildObserverPublicStateKeepsStageOneProgressFromLargeContent(): void
    {
        $service = new AiSiteQueueStateService();

        $content = \json_encode([
            'public_id' => 'large-progress-session',
            'job_key' => 'plan.stage1',
            'filler' => \str_repeat('x', 270000),
            'stage1_page_progress' => [
                'total' => 3,
                'done_count' => 1,
                'running_count' => 2,
                'running' => ['home_page', 'blog_list'],
                'details' => [
                    [
                        'page_type' => 'home_page',
                        'status' => 'running',
                        'block_rows' => [
                            [
                                'block_key' => 'hero',
                                'status' => 'running',
                                'message' => 'Generating {hero} copy',
                            ],
                        ],
                    ],
                ],
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        self::assertIsString($content);

        $state = $service->buildObserverPublicState([
            'queue_id' => 99,
            'status' => 'running',
            'content' => $content,
        ]);

        self::assertSame('plan.stage1', $state['job_key']);
        self::assertSame(3, (int)($state['stage1_page_progress']['total'] ?? 0));
        self::assertSame(['home_page', 'blog_list'], $state['stage1_page_progress']['running'] ?? []);
        self::assertSame(
            'hero',
            (string)($state['stage1_page_progress']['details'][0]['block_rows'][0]['block_key'] ?? '')
        );
    }
}
