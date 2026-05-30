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

    public function testConfirmedBuildPlanAndLegacyArtifactsAreStrippedBeforeStorage(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'plan_confirmed' => 1,
            'plan_json' => ['pages' => ['home_page' => ['goal' => 'Keep me']]],
            'plan_structured' => ['pages' => ['home_page' => ['goal' => 'Keep me']]],
            'execution_blueprint' => [
                'signature' => 'legacy-stage1-signature',
                'page_types' => ['home_page'],
            ],
            'execution_blueprint_draft' => [
                'signature' => 'stale-draft-signature',
            ],
            'build_blueprint' => [
                'signature' => 'legacy-build-blueprint',
                'tasks' => [['task_key' => 'page:home_page:hero']],
            ],
            'build_tasks' => [
                'page:home_page:hero' => [
                    'status' => 'running',
                    'attempt_no' => 2,
                ],
            ],
            'build_plan_v2' => [
                'contract_meta' => ['status' => 'confirmed'],
                'blocks' => [
                    [
                        'block_id' => 'hero',
                        'execution' => [
                            'task_key' => 'page:home_page:hero',
                            'status' => 'running',
                            'attempt_no' => 2,
                            'message' => 'working',
                        ],
                    ],
                ],
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
                ],
            ],
        ]);

        $stored = $session->getScopeArray();

        self::assertArrayNotHasKey('execution_blueprint', $stored);
        self::assertArrayNotHasKey('execution_blueprint_draft', $stored);
        self::assertArrayNotHasKey('build_blueprint', $stored);
        self::assertArrayNotHasKey('build_tasks', $stored);
        self::assertSame(['pages' => ['home_page' => ['goal' => 'Keep me']]], $stored['plan_structured'] ?? null);
        self::assertSame(['pages' => ['home_page' => ['goal' => 'Keep me']]], $stored['plan_json'] ?? null);
        self::assertArrayNotHasKey('execution_blueprint', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('structured_plan', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('plan_json', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertArrayNotHasKey('plan_book', $stored['plan_workbench']['confirmed'] ?? []);
        self::assertSame(1, $stored['plan_workbench']['confirmed']['_storage_compacted'] ?? null);
        self::assertSame('running', $stored['build_plan_v2']['blocks'][0]['execution']['status'] ?? null);
    }

    public function testDraftStageOnePlanWorkbenchConfirmedPayloadsAreCompactedBeforeStorage(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            'workspace_track' => 'virtual_theme',
            'plan_confirmed' => 0,
            'plan_json' => ['pages' => ['home_page' => ['goal' => 'Draft plan']]],
            'plan_structured' => ['pages' => ['home_page' => ['goal' => 'Draft plan']]],
            'plan_workbench' => [
                'contract_context' => ['selected_skill_codes' => ['virtual_theme']],
                'stage1' => [
                    'request_summary' => ['raw_requirement' => 'Need a marketing homepage'],
                    'page_plans' => ['home_page' => ['blocks' => [['block_key' => 'hero']]]],
                ],
                'confirmed' => [
                    'execution_blueprint' => ['signature' => 'duplicate-draft'],
                    'structured_plan' => ['pages' => ['home_page' => ['goal' => 'Draft plan']]],
                    'plan_json' => ['pages' => ['home_page' => ['goal' => 'Draft plan']]],
                    'plan_book' => ['structured' => ['source' => 'stage1.block_tree', 'pages' => ['home_page' => ['blocks' => []]]]],
                    'block_index' => ['pages' => ['home_page' => ['hero']]],
                    'contract_context' => ['selected_skill_codes' => ['duplicate']],
                ],
            ],
        ]);

        $stored = $session->getScopeArray();
        $confirmed = $stored['plan_workbench']['confirmed'] ?? [];

        self::assertSame(0, $stored['plan_confirmed'] ?? null);
        self::assertArrayNotHasKey('execution_blueprint', $confirmed);
        self::assertArrayNotHasKey('structured_plan', $confirmed);
        self::assertArrayNotHasKey('plan_json', $confirmed);
        self::assertArrayNotHasKey('plan_book', $confirmed);
        self::assertArrayNotHasKey('block_index', $confirmed);
        self::assertSame(1, $confirmed['_storage_compacted'] ?? null);
        self::assertSame(['field' => 'plan_json'], $confirmed['plan_book_ref'] ?? null);
        self::assertSame(
            ['selected_skill_codes' => ['virtual_theme']],
            $stored['plan_workbench']['contract_context'] ?? null
        );
        self::assertArrayNotHasKey('page_plans', $stored['plan_workbench']['stage1'] ?? []);
    }

    public function testBuildPlanArtifactBackedPayloadsAreNotStoredBackIntoScopeJson(): void
    {
        $session = new AiSiteAgentSession();

        $session->setScopeArray([
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'plan_json' => ['storage' => 'session_artifact_v1', 'hash' => 'plan-json-hash'],
                    'plan_structured' => ['storage' => 'session_artifact_v1', 'hash' => 'plan-structured-hash'],
                    'build_plan_v2' => ['storage' => 'session_artifact_v1', 'hash' => 'build-plan-hash'],
                    'plan_projection' => ['storage' => 'session_artifact_v1', 'hash' => 'projection-hash'],
                    'content_manifest' => ['storage' => 'session_artifact_v1', 'hash' => 'manifest-hash'],
                ],
                AiSiteAgentSession::STAGE_VISUAL_EDIT => [
                    'build_workbench' => ['storage' => 'session_artifact_v1', 'hash' => 'build-workbench-hash'],
                    'build_contracts' => ['storage' => 'session_artifact_v1', 'hash' => 'build-contracts-hash'],
                    'render_data_contract' => ['storage' => 'session_artifact_v1', 'hash' => 'render-contract-hash'],
                ],
            ],
            'plan_json' => ['pages' => ['home_page' => ['heavy' => \str_repeat('a', 1024)]]],
            'plan_structured' => ['pages' => ['home_page' => ['heavy' => \str_repeat('b', 1024)]]],
            'build_plan_v2' => ['tasks' => [['task_key' => 'page:home_page:hero', 'heavy' => \str_repeat('c', 1024)]]],
            'plan_projection' => ['pages' => ['home_page' => ['blocks' => ['hero']]]],
            'content_manifest' => ['pages' => ['home_page' => ['copy' => \str_repeat('d', 1024)]]],
            'build_workbench' => ['heavy' => \str_repeat('f', 1024)],
            'build_contracts' => ['heavy' => \str_repeat('g', 1024)],
            'render_data_contract' => ['heavy' => \str_repeat('h', 1024)],
        ]);

        $stored = $session->getScopeArray();

        self::assertSame([], $stored['plan_json'] ?? null);
        self::assertSame([], $stored['plan_structured'] ?? null);
        self::assertSame([], $stored['build_plan_v2'] ?? null);
        self::assertSame([], $stored['plan_projection'] ?? null);
        self::assertSame([], $stored['content_manifest'] ?? null);
        self::assertArrayNotHasKey('build_blueprint', $stored);
        self::assertSame([], $stored['build_workbench'] ?? null);
        self::assertSame([], $stored['build_contracts'] ?? null);
        self::assertSame([], $stored['render_data_contract'] ?? null);
        self::assertSame('build-plan-hash', $stored['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['build_plan_v2']['hash'] ?? null);
        self::assertSame('render-contract-hash', $stored['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['render_data_contract']['hash'] ?? null);
    }

    public function testAssetImageGenerationFailuresAreCompactedBeforeStorage(): void
    {
        $session = new AiSiteAgentSession();
        $failures = [];
        for ($i = 0; $i < 85; $i++) {
            $failures[] = [
                'slotId' => 'slot-' . $i,
                'message' => \str_repeat((string)($i % 10), 900),
                'updated_at' => '2026-05-24 08:00:00',
            ];
        }

        $session->setScopeArray([
            'asset_image_generation_failures' => $failures,
        ]);

        $stored = $session->getScopeArray();
        $storedFailures = $stored['asset_image_generation_failures'] ?? [];

        self::assertCount(80, $storedFailures);
        self::assertSame('slot-5', $storedFailures[0]['slot_id'] ?? null);
        self::assertArrayNotHasKey('slotId', $storedFailures[0]);
        self::assertLessThanOrEqual(803, \mb_strlen((string)($storedFailures[0]['message'] ?? '')));
    }
}
