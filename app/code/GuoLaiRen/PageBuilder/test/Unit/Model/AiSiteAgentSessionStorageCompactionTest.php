<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Model;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentSessionStorageCompactionTest extends TestCase
{
    public function testVirtualThemeBuildArtifactsAreCompactedBeforeStorage(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'shared_components' => [
                'header' => [
                    'code' => 'shared_header',
                    'name' => 'Header',
                    'region' => 'header',
                    'phtml' => '<div><?= $title ?></div>',
                    'html' => '<header>Generated</header>',
                    'ai_data' => ['raw' => 'large raw response'],
                    'default_config' => [
                        'title' => 'Generated header',
                        'html_content' => '<header>Generated</header>',
                        'nested' => [
                            '_pb_server_template_phtml' => '<div>server only</div>',
                            'keep' => 'value',
                        ],
                    ],
                ],
            ],
            '_ai_generated_shared_components' => [
                'footer' => [
                    'code' => 'shared_footer',
                    'phtml' => '<footer><?= $title ?></footer>',
                    'html' => '<footer>Generated</footer>',
                    'ai_data' => ['raw' => 'large raw response'],
                ],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Home',
                    'blocks' => [
                        [
                            'type' => 'ai_generated_component',
                            'html' => '<section>Generated</section>',
                            'config' => ['html_content' => '<section>Generated</section>'],
                            '_pb_server_template_phtml' => '<section><?= $title ?></section>',
                        ],
                    ],
                ],
            ],
        ]);

        $stored = $session->getScopeArray();
        $header = $stored['shared_components']['header'] ?? [];

        self::assertArrayNotHasKey('phtml', $header);
        self::assertArrayNotHasKey('html', $header);
        self::assertArrayNotHasKey('ai_data', $header);
        self::assertSame('Generated header', $header['default_config']['title'] ?? null);
        self::assertArrayNotHasKey('html_content', $header['default_config']);
        self::assertSame('value', $header['default_config']['nested']['keep'] ?? null);
        self::assertArrayNotHasKey('_pb_server_template_phtml', $header['default_config']['nested']);
        self::assertSame([], $stored['virtual_pages_by_type']['home_page']['blocks'] ?? null);

        $footer = $stored['_ai_generated_shared_components']['footer'] ?? [];
        self::assertSame('shared_footer', $footer['code'] ?? null);
        self::assertArrayNotHasKey('phtml', $footer);
        self::assertArrayNotHasKey('html', $footer);
        self::assertArrayNotHasKey('ai_data', $footer);
    }

    public function testHtmlBlocksTrackKeepsGeneratedBlocksInScope(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            'workspace_track' => 'html_blocks',
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Home',
                    'blocks' => [
                        [
                            'type' => 'ai_generated_component',
                            'html' => '<section>Generated</section>',
                            'config' => ['html_content' => '<section>Generated</section>'],
                        ],
                    ],
                ],
            ],
        ]);

        $stored = $session->getScopeArray();

        self::assertSame(
            '<section>Generated</section>',
            $stored['virtual_pages_by_type']['home_page']['blocks'][0]['html'] ?? null
        );
        self::assertSame(
            '<section>Generated</section>',
            $stored['virtual_pages_by_type']['home_page']['blocks'][0]['config']['html_content'] ?? null
        );
    }

    public function testConfirmedSnapshotsKeepOnlyLatestStorageCopy(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'task_plan_confirmed' => 1,
            'task_plan_structured' => [
                'page_tasks' => ['home_page' => [['title' => 'Hero']]],
                'plan_signature' => 'task-plan-signature',
            ],
            'task_plan_markdown' => '',
            'virtual_theme_plan' => [
                'draft' => ['stale' => true],
                'draft_markdown' => '',
                'draft_generated_at' => '2026-04-24 15:00:00',
                'confirmed' => [
                    'signature' => 'task-plan-signature',
                    'page_tasks' => ['home_page' => [['title' => 'Hero']]],
                    'plan_signature' => 'task-plan-signature',
                ],
                'confirmed_markdown' => '# Confirmed task plan',
            ],
            'plan_workbench' => [
                'stage1' => [
                    'request_summary' => ['raw_requirement' => 'Need a marketing homepage'],
                    'progress' => ['queue_job_done' => 3],
                    'page_plans' => ['home_page' => ['heavy' => true]],
                    'interaction_state' => ['active_page_key' => 'home_page'],
                ],
                'confirmed' => [
                    'plan_book' => ['structured' => ['pages' => []]],
                ],
            ],
        ]);

        $stored = $session->getScopeArray();

        self::assertSame([], $stored['virtual_theme_plan']['draft'] ?? null);
        self::assertArrayNotHasKey('draft_generated_at', $stored['virtual_theme_plan']);
        self::assertSame([], $stored['task_plan_structured'] ?? null);
        self::assertSame(
            ['request_summary' => ['raw_requirement' => 'Need a marketing homepage'], 'progress' => ['queue_job_done' => 3]],
            $stored['plan_workbench']['stage1'] ?? null
        );
        self::assertArrayNotHasKey('page_plans', $stored['plan_workbench']['stage1'] ?? []);
        self::assertArrayNotHasKey('interaction_state', $stored['plan_workbench']['stage1'] ?? []);
    }
}
