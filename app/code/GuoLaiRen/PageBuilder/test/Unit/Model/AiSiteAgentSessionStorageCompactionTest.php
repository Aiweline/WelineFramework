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

    public function testConfirmedExecutionBlueprintAndBuildTaskStateAreCompactedBeforeStorage(): void
    {
        $session = new AiSiteAgentSession();
        $stage2ContextSnapshot = [
            'context_hash' => 'stage2-context-hash',
            'theme_context_snapshot' => ['palette' => ['primary' => '#0f172a']],
            'shared_prompt_context' => ['nav_style' => 'compact trust nav'],
        ];

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'plan_confirmed' => 1,
            'execution_blueprint' => [
                'signature' => 'stage1-confirmed-signature',
                'page_types' => ['home_page'],
            ],
            'execution_blueprint_confirmed_signature' => 'stage1-confirmed-signature',
            'execution_blueprint_draft' => [
                'signature' => 'stale-draft-signature',
                'page_types' => ['home_page'],
            ],
            'plan_workbench' => [
                'stage1' => [
                    'request_summary' => ['raw_requirement' => 'Need a marketing homepage'],
                ],
                'confirmed' => [
                    'execution_blueprint' => ['signature' => 'duplicate-stage1-confirmed'],
                    'structured_plan' => ['pages' => ['home_page' => ['goal' => 'Keep me']]],
                    'plan_json' => ['pages' => ['home_page' => ['goal' => 'Keep me']]],
                    'plan_book' => ['structured' => ['source' => 'stage1.block_tree', 'pages' => ['home_page' => ['blocks' => []]]]],
                    'shared_prompt_context' => ['context_hash' => 'shared-hash'],
                ],
            ],
            'task_plan_confirmed' => 1,
            'task_plan_structured' => [
                'signature' => 'task-plan-signature',
                'page_tasks' => [
                    'home_page' => [
                        [
                            'task_key' => 'page:home_page:hero',
                            'label' => 'Hero',
                        ],
                    ],
                ],
                'execution_blueprint' => [
                    'signature' => 'task-blueprint-signature',
                    'tasks' => [
                        ['task_key' => 'page:home_page:hero'],
                    ],
                    'task_groups' => [
                        'pages' => [
                            'home_page' => [
                                ['task_key' => 'page:home_page:hero'],
                            ],
                        ],
                    ],
                ],
                'shared_block_tasks' => [
                    ['task_key' => 'shared:header'],
                ],
                'page_block_tasks' => [
                    'home_page' => [
                        ['task_key' => 'page:home_page:hero'],
                    ],
                ],
                'virtual_theme_build_tree' => [
                    'pages' => ['home_page' => ['blocks' => []]],
                ],
            ],
            'virtual_theme_plan' => [
                'draft' => [],
                'confirmed' => [
                    'signature' => 'task-plan-signature',
                    'page_tasks' => [
                        'home_page' => [
                            [
                                'task_key' => 'page:home_page:hero',
                                'label' => 'Hero',
                            ],
                        ],
                    ],
                    'execution_blueprint' => [
                        'signature' => 'task-blueprint-signature',
                        'tasks' => [
                            ['task_key' => 'page:home_page:hero'],
                        ],
                        'task_groups' => [
                            'pages' => [
                                'home_page' => [
                                    ['task_key' => 'page:home_page:hero'],
                                ],
                            ],
                        ],
                    ],
                    'shared_block_tasks' => [
                        ['task_key' => 'shared:header'],
                    ],
                    'page_block_tasks' => [
                        'home_page' => [
                            ['task_key' => 'page:home_page:hero'],
                        ],
                    ],
                    'virtual_theme_build_tree' => [
                        'pages' => ['home_page' => ['blocks' => []]],
                    ],
                ],
                'confirmed_markdown' => '# Confirmed task plan',
            ],
            'build_blueprint' => [
                'source' => 'stage2_confirmed_task_plan',
                'signature' => 'build-blueprint-signature',
                'task_plan_signature' => 'task-plan-signature',
                'page_types' => ['home_page'],
                'tasks' => [
                    [
                        'task_key' => 'page:home_page:hero',
                        'runtime_context' => [
                            'block_key' => 'hero',
                            'stage2_context_snapshot' => $stage2ContextSnapshot,
                            'theme_context_snapshot' => $stage2ContextSnapshot['theme_context_snapshot'],
                            'shared_prompt_context' => $stage2ContextSnapshot['shared_prompt_context'],
                        ],
                    ],
                ],
            ],
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'task_type' => 'page_section',
                    'group_key' => 'home_page',
                    'page_type' => 'home_page',
                    'section_code' => 'content/home-page-hero',
                    'dependencies' => ['shared:header', 'shared:footer'],
                    'can_parallel' => true,
                    'progress_weight' => 2.5,
                    'runtime_context' => ['block_key' => 'hero'],
                    'plan_context' => ['goal' => 'duplicate'],
                    'task_script' => ['story_goal' => 'duplicate'],
                    'block_task' => ['task_goal' => 'duplicate'],
                    'implementation_contract' => ['acceptance' => ['duplicate']],
                    'status' => 'running',
                    'attempt_no' => 2,
                    'message' => 'working',
                    'result_ref' => ['component_code' => 'hero'],
                ],
            ],
        ]);

        $stored = $session->getScopeArray();

        self::assertSame([], $stored['execution_blueprint_draft'] ?? null);
        self::assertSame(['pages' => ['home_page' => ['goal' => 'Keep me']]], $stored['plan_structured'] ?? null);
        self::assertSame(['pages' => ['home_page' => ['goal' => 'Keep me']]], $stored['plan_json'] ?? null);
        self::assertArrayNotHasKey('execution_blueprint', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('structured_plan', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('plan_json', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('plan_book', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertSame(1, $stored['plan_workbench']['confirmed']['_storage_compacted'] ?? null);
        self::assertArrayNotHasKey('confirmed_stage1_plan_book', $stored);
        self::assertSame(
            ['field' => 'plan_json'],
            $stored['plan_workbench']['confirmed']['plan_book_ref'] ?? null
        );

        self::assertSame([], $stored['task_plan_structured'] ?? null);
        self::assertArrayNotHasKey('execution_blueprint', $stored['virtual_theme_plan']['confirmed'] ?? []);
        self::assertArrayNotHasKey('page_tasks', $stored['virtual_theme_plan']['confirmed'] ?? []);
        self::assertArrayNotHasKey('shared_block_tasks', $stored['virtual_theme_plan']['confirmed'] ?? []);
        self::assertArrayNotHasKey('page_block_tasks', $stored['virtual_theme_plan']['confirmed'] ?? []);
        self::assertArrayNotHasKey('virtual_theme_build_tree', $stored['virtual_theme_plan']['confirmed'] ?? []);
        self::assertSame(1, $stored['virtual_theme_plan']['confirmed']['_storage_compacted'] ?? null);
        self::assertSame(1, $stored['virtual_theme_plan']['confirmed']['execution_blueprint_ref']['task_count'] ?? null);
        self::assertArrayNotHasKey('stage2_context_snapshot', $stored);
        self::assertSame(['block_key' => 'hero'], $stored['build_blueprint']['tasks'][0]['runtime_context'] ?? null);

        $buildTaskState = $stored['build_tasks']['page:home_page:hero'] ?? [];
        self::assertSame('running', $buildTaskState['status'] ?? null);
        self::assertSame(2, $buildTaskState['attempt_no'] ?? null);
        self::assertSame('working', $buildTaskState['message'] ?? null);
        self::assertSame(['component_code' => 'hero'], $buildTaskState['result_ref'] ?? null);
        self::assertArrayNotHasKey('task_script', $buildTaskState);
        self::assertArrayNotHasKey('plan_context', $buildTaskState);
        self::assertArrayNotHasKey('block_task', $buildTaskState);
        self::assertArrayNotHasKey('implementation_contract', $buildTaskState);
        self::assertArrayNotHasKey('runtime_context', $buildTaskState);
    }

    public function testUnconfirmedStageTwoDraftKeepsSingleStructuredStorageCopy(): void
    {
        $session = new AiSiteAgentSession();
        $structured = [
            'plan_signature' => 'stage-two-signature',
            'shared_tasks' => [
                ['task_key' => 'shared:header', 'label' => 'Header'],
            ],
            'page_tasks' => [
                'home_page' => [
                    ['task_key' => 'page:home_page:hero', 'label' => 'Hero'],
                ],
            ],
            'execution_blueprint' => [
                'signature' => 'stage-two-blueprint',
                'tasks' => [
                    ['task_key' => 'page:home_page:hero'],
                ],
                'task_groups' => [
                    'home_page' => [
                        ['task_key' => 'page:home_page:hero', 'duplicated' => true],
                    ],
                ],
            ],
        ];
        $draft = \array_replace($structured, ['signature' => 'stage-two-signature']);

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'task_plan_confirmed' => 0,
            'task_plan_structured' => $structured,
            'task_plan_markdown' => '# Stage two draft',
            'virtual_theme_plan' => [
                'draft' => $draft,
                'draft_markdown' => '# Stage two draft',
                'draft_generated_at' => '2026-04-25 15:50:00',
                'plan_signature' => 'stage-two-signature',
            ],
        ]);

        $stored = $session->getScopeArray();

        self::assertSame([], $stored['task_plan_structured'] ?? null);
        self::assertSame('stage-two-signature', $stored['virtual_theme_plan']['draft']['signature'] ?? null);
        self::assertArrayNotHasKey('task_groups', $stored['virtual_theme_plan']['draft']['execution_blueprint'] ?? []);
        self::assertSame('# Stage two draft', (string)($stored['task_plan_markdown'] ?? ''));
        self::assertSame('# Stage two draft', (string)($stored['virtual_theme_plan']['draft_markdown'] ?? ''));
    }
}
