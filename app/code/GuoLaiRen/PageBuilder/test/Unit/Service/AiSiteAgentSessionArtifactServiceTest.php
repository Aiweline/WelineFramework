<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionArtifact;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionArtifactService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentSessionArtifactServiceTest extends TestCase
{
    public function testPrepareScopeForStorageSplitsLargeStageArtifacts(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            'plan_json' => ['pages' => ['home_page' => ['title' => 'Home']]],
            'plan_structured' => ['summary' => 'stage one'],
            'plan_markdown' => '# Stage one plan',
            'build_plan_v2' => ['blocks' => [['block_id' => 'hero', 'execution' => ['task_key' => 'page:home_page:hero']]]],
            'plan_projection' => ['pages' => ['home_page' => ['blocks' => ['hero']]]],
            'content_manifest' => ['pages' => ['home_page' => ['copy' => 'Hero copy']]],
            'plan_workbench' => ['confirmed' => ['source' => 'stage_one']],
            'confirmed_stage1_plan_book' => ['plan' => ['home_page']],
            'execution_blueprint' => ['legacy' => true],
            'build_blueprint' => ['legacy' => true],
            'build_workbench' => ['contracts' => ['render_data' => ['id' => 'rd']]],
            'build_contracts' => ['render_data' => ['payload' => ['page_types' => ['home_page']]]],
            'render_data_contract' => ['payload' => ['page_types' => ['home_page']]],
        ]);

        $scope = $prepared['scope'];
        $artifactKeys = \array_map(
            static fn(array $artifact): string => (string)$artifact['artifact_key'],
            $prepared['artifacts']
        );

        self::assertSame([], $scope['plan_json']);
        self::assertSame([], $scope['plan_structured']);
        self::assertSame('', $scope['plan_markdown']);
        self::assertSame([], $scope['build_plan_v2']);
        self::assertSame([], $scope['plan_projection']);
        self::assertSame([], $scope['content_manifest']);
        self::assertSame([], $scope['plan_workbench']);
        self::assertArrayNotHasKey('confirmed_stage1_plan_book', $scope);
        self::assertArrayNotHasKey('execution_blueprint', $scope);
        self::assertArrayNotHasKey('build_blueprint', $scope);
        self::assertSame([], $scope['build_workbench']);
        self::assertSame([], $scope['build_contracts']);
        self::assertSame([], $scope['render_data_contract']);
        self::assertContains('plan_json', $artifactKeys);
        self::assertContains('plan_markdown', $artifactKeys);
        self::assertContains('build_plan_v2', $artifactKeys);
        self::assertContains('plan_projection', $artifactKeys);
        self::assertContains('content_manifest', $artifactKeys);
        self::assertContains('plan_workbench', $artifactKeys);
        self::assertNotContains('confirmed_stage1_plan_book', $artifactKeys);
        self::assertNotContains('execution_blueprint', $artifactKeys);
        self::assertNotContains('build_blueprint', $artifactKeys);
        self::assertContains('build_workbench', $artifactKeys);
        self::assertContains('build_contracts', $artifactKeys);
        self::assertContains('render_data_contract', $artifactKeys);
        self::assertSame(
            'session_artifact_v1',
            $scope['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_workbench']['storage'] ?? null
        );
    }

    public function testVisualEditArtifactKeyListHydratesBuildContracts(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $keys = $service->artifactKeysForStage(AiSiteAgentSession::STAGE_VISUAL_EDIT);

        self::assertContains('build_workbench', $keys);
        self::assertContains('build_contracts', $keys);
        self::assertContains('render_data_contract', $keys);
        self::assertNotContains('build_blueprint', $keys);
        self::assertContains('build_workbench', $service->resolveTouchedArtifactKeysFromPatch(['build_workbench' => []]));
        self::assertContains('build_contracts', $service->resolveTouchedArtifactKeysFromPatch(['build_contracts' => []]));
        self::assertContains('render_data_contract', $service->resolveTouchedArtifactKeysFromPatch(['render_data_contract' => []]));
    }

    public function testDecodedPayloadCacheIsBoundedToSmallArtifacts(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(AiSiteAgentSessionArtifactService::class))->getFileName());

        self::assertStringContainsString('private const PAYLOAD_VALUE_CACHE_LIMIT = 1;', $source);
        self::assertStringContainsString('public function releasePayloadCache(): void', $source);
        self::assertStringContainsString('private const PAYLOAD_VALUE_CACHE_MAX_BYTES = 1048576;', $source);
        self::assertStringContainsString('shouldCachePayloadValue($artifact->getPayloadBytes())', $source);
        self::assertStringContainsString('shouldCachePayloadValue($payloadBytes)', $source);
    }

    public function testUntouchedEmptyPayloadKeepsExistingArtifactReference(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_VISUAL_EDIT => [
                    'build_workbench' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
                        'artifact_key' => 'build_workbench',
                    ],
                ],
            ],
            'build_workbench' => [],
        ]);

        self::assertSame(
            'session_artifact_v1',
            $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_workbench']['storage'] ?? null
        );
        self::assertSame([], $prepared['artifacts']);
    }

    public function testUntouchedMetadataOnlyPlanPayloadKeepsExistingPlanArtifactReference(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'plan_json' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'plan_json',
                        'hash' => 'full-plan-json-hash',
                    ],
                    'plan_structured' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'plan_structured',
                        'hash' => 'full-plan-structured-hash',
                    ],
                ],
            ],
            'plan_json' => ['content_locale' => 'en_US'],
            'plan_structured' => ['content_locale' => 'en_US'],
        ]);

        self::assertSame([], $prepared['scope']['plan_json']);
        self::assertSame([], $prepared['scope']['plan_structured']);
        self::assertSame([], $prepared['artifacts']);
        self::assertSame(
            'full-plan-json-hash',
            $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['plan_json']['hash'] ?? null
        );
        self::assertSame(
            'full-plan-structured-hash',
            $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['plan_structured']['hash'] ?? null
        );
    }

    public function testUnchangedHydratedPayloadIsClearedWithoutRewritingArtifact(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());
        $payload = ['blocks' => [['block_id' => 'hero', 'heavy' => \str_repeat('x', 1024)]]];
        $hash = \sha1(\json_encode(['value' => $payload], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'build_plan_v2' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'build_plan_v2',
                        'hash' => $hash,
                        'bytes' => 1234,
                        'updated_at' => '2026-04-25 12:00:00',
                    ],
                ],
            ],
            'build_plan_v2' => $payload,
        ]);

        self::assertSame([], $prepared['scope']['build_plan_v2']);
        self::assertSame([], $prepared['artifacts']);
        self::assertSame($hash, $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['build_plan_v2']['hash'] ?? null);
        self::assertSame('2026-04-25 12:00:00', $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['build_plan_v2']['updated_at'] ?? null);
    }

    public function testUnchangedLargePayloadIsRewrittenWhenStoragePolicyMovesExternal(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());
        $payload = ['blocks' => [['block_id' => 'hero', 'heavy' => \str_repeat('x', 600 * 1024)]]];
        $hash = \sha1(\json_encode(['value' => $payload], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'build_plan_v2' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'build_plan_v2',
                        'hash' => $hash,
                        'bytes' => 600 * 1024,
                    ],
                ],
            ],
            'build_plan_v2' => $payload,
        ]);

        self::assertSame([], $prepared['scope']['build_plan_v2']);
        self::assertSame('session_artifact_file_v1', $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['build_plan_v2']['storage'] ?? null);
        self::assertCount(1, $prepared['artifacts']);
        self::assertSame('session_artifact_file_v1', $prepared['artifacts'][0]['storage'] ?? null);
    }

    public function testLargePreparedArtifactCarriesExternalPointerInsteadOfFullPayloadJson(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());
        $heavy = \str_repeat('x', 600 * 1024);

        $prepared = $service->prepareScopeForStorage(123, [
            'build_plan_v2' => [
                'blocks' => [
                    [
                        'block_id' => 'hero',
                        'execution' => ['runtime_context' => ['large_prompt_context' => $heavy]],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $prepared['artifacts']);
        $artifact = $prepared['artifacts'][0];
        self::assertSame('build_plan_v2', $artifact['artifact_key'] ?? null);
        self::assertSame('session_artifact_file_v1', $artifact['storage'] ?? null);
        self::assertLessThan(2048, \strlen((string)($artifact['payload_json'] ?? '')));
        self::assertStringContainsString(AiSiteAgentSessionArtifact::EXTERNAL_PAYLOAD_FILE_KEY, (string)$artifact['payload_json']);
        self::assertStringNotContainsString($heavy, (string)$artifact['payload_json']);
    }

    public function testTouchedEmptyPayloadRemovesStaleArtifactReference(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'build_plan_v2' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'build_plan_v2',
                    ],
                ],
            ],
            'build_plan_v2' => [],
        ], ['build_plan_v2']);

        self::assertArrayNotHasKey('_artifact_refs', $prepared['scope']);
        self::assertSame([], $prepared['artifacts']);
    }

    public function testPlanMarkdownPatchMarksArtifactTouched(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        self::assertContains('plan_markdown', $service->resolveTouchedArtifactKeysFromPatch([
            'plan_markdown' => '',
        ]));
    }
}
