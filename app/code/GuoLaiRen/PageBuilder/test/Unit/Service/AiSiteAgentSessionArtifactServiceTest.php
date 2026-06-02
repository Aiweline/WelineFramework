<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionArtifact;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionArtifactService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentSessionArtifactServiceTest extends TestCase
{
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

    public function testArtifactStorageDoesNotReadDetachedFilesystemFallbacks(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(AiSiteAgentSessionArtifactService::class))->getFileName());

        self::assertStringNotContainsString('loadLatestArtifactFromFilesystem', $source);
        self::assertStringNotContainsString('emergency_fallback.log', $source);
        self::assertStringNotContainsString('[hydrateScope]', $source);
        self::assertStringNotContainsString('latestFile', $source);
        self::assertStringContainsString('$filename = $artifactKey . \'.json\';', $source);
        self::assertStringContainsString('deleteLegacyExternalPayloadDocuments(', $source);
        self::assertStringContainsString('deleteSessionExternalPayloadDirectory($sessionId);', $source);
        self::assertStringContainsString('$storage !== self::STORAGE_EXTERNAL_FILE', $source);
        self::assertStringNotContainsString('$filename = $artifactKey . \'-\' . $hash . \'.json\';', $source);
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

        $scope = [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'plan_json' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_PLAN,
                        'artifact_key' => 'plan_json',
                        'hash' => 'full-plan-json-hash',
                    ],
                ],
            ],
            'plan_json' => ['content_locale' => 'en_US'],
        ];

        $prepared = $service->prepareScopeForStorage(123, $scope);

        self::assertSame([], $prepared['scope']['plan_json']);
        self::assertSame([], $prepared['artifacts']);
        self::assertSame(
            'full-plan-json-hash',
            $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['plan_json']['hash'] ?? null
        );
        self::assertArrayNotHasKey('plan_structured', $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN] ?? []);

        $preparedWhenTouched = $service->prepareScopeForStorage(123, $scope, ['plan_json']);

        self::assertSame([], $preparedWhenTouched['scope']['plan_json']);
        self::assertSame([], $preparedWhenTouched['artifacts']);
        self::assertSame(
            'full-plan-json-hash',
            $preparedWhenTouched['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_PLAN]['plan_json']['hash'] ?? null
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
        self::assertStringContainsString('build_plan_v2.json', (string)$artifact['payload_json']);
        self::assertStringNotContainsString('build_plan_v2-', (string)$artifact['payload_json']);
        self::assertStringNotContainsString($heavy, (string)$artifact['payload_json']);
    }

    public function testUnchangedExternalPayloadStillWritesDeterministicPointer(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());
        $heavy = \str_repeat('x', 600 * 1024);
        $payload = [
            'pages' => ['home_page' => ['blocks' => ['hero']]],
            'blocks' => [['block_id' => 'hero', 'heavy' => $heavy]],
        ];
        $hash = \sha1(\json_encode(['value' => $payload], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_PLAN => [
                    'build_plan_v2' => [
                        'storage' => 'session_artifact_file_v1',
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
        self::assertCount(1, $prepared['artifacts']);
        self::assertSame('session_artifact_file_v1', $prepared['artifacts'][0]['storage'] ?? null);
        self::assertStringContainsString('build_plan_v2.json', (string)($prepared['artifacts'][0]['payload_json'] ?? ''));
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
