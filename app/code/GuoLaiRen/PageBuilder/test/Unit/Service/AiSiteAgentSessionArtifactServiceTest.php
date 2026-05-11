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
            'build_plan_v2' => ['tasks' => [['task_key' => 'page:home_page:hero']]],
            'plan_projection' => ['pages' => ['home_page' => ['blocks' => ['hero']]]],
            'content_manifest' => ['pages' => ['home_page' => ['copy' => 'Hero copy']]],
            'confirmed_stage1_plan_book' => ['plan' => ['home_page']],
            'build_blueprint' => [
                'signature' => 'build-signature',
                'tasks' => [['task_key' => 'shared:header']],
            ],
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
        self::assertArrayNotHasKey('confirmed_stage1_plan_book', $scope);
        self::assertSame([], $scope['build_blueprint']);
        self::assertContains('plan_json', $artifactKeys);
        self::assertContains('plan_markdown', $artifactKeys);
        self::assertContains('build_plan_v2', $artifactKeys);
        self::assertContains('plan_projection', $artifactKeys);
        self::assertContains('content_manifest', $artifactKeys);
        self::assertNotContains('confirmed_stage1_plan_book', $artifactKeys);
        self::assertContains('build_blueprint', $artifactKeys);
        self::assertSame(
            'session_artifact_v1',
            $scope['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_blueprint']['storage'] ?? null
        );
    }

    public function testUntouchedEmptyPayloadKeepsExistingArtifactReference(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_VISUAL_EDIT => [
                    'build_blueprint' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
                        'artifact_key' => 'build_blueprint',
                    ],
                ],
            ],
            'build_blueprint' => [],
        ]);

        self::assertSame(
            'session_artifact_v1',
            $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_blueprint']['storage'] ?? null
        );
        self::assertSame([], $prepared['artifacts']);
    }

    public function testUnchangedHydratedPayloadIsClearedWithoutRewritingArtifact(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());
        $payload = ['tasks' => [['task_key' => 'page:home_page:hero', 'heavy' => \str_repeat('x', 1024)]]];
        $hash = \sha1(\json_encode(['value' => $payload], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_VISUAL_EDIT => [
                    'build_blueprint' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
                        'artifact_key' => 'build_blueprint',
                        'hash' => $hash,
                        'bytes' => 1234,
                        'updated_at' => '2026-04-25 12:00:00',
                    ],
                ],
            ],
            'build_blueprint' => $payload,
        ]);

        self::assertSame([], $prepared['scope']['build_blueprint']);
        self::assertSame([], $prepared['artifacts']);
        self::assertSame($hash, $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_blueprint']['hash'] ?? null);
        self::assertSame('2026-04-25 12:00:00', $prepared['scope']['_artifact_refs'][AiSiteAgentSession::STAGE_VISUAL_EDIT]['build_blueprint']['updated_at'] ?? null);
    }

    public function testTouchedEmptyPayloadRemovesStaleArtifactReference(): void
    {
        $service = new AiSiteAgentSessionArtifactService(new AiSiteAgentSessionArtifact());

        $prepared = $service->prepareScopeForStorage(123, [
            '_artifact_refs' => [
                AiSiteAgentSession::STAGE_VISUAL_EDIT => [
                    'build_blueprint' => [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
                        'artifact_key' => 'build_blueprint',
                    ],
                ],
            ],
            'build_blueprint' => [],
        ], ['build_blueprint']);

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
