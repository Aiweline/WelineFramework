<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteTaskPlanDetectBootstrapSseIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testDetectBootstrapSseStreamsDecodedMarkdownAndPersistsStructuredState(): void
    {
        ['session' => $session, 'scope' => $scope, 'buildBlueprint' => $buildBlueprint] = $this->prepareFixture();

        $deterministicArtifacts = (new AiSiteVirtualThemePlanService())->buildTaskPlanArtifacts(
            \array_replace($scope, ['fake_mode' => 1]),
            $buildBlueprint
        );
        $response = $this->buildAiTaskPlanResponseJson(
            "# Stage-2 Task Plan\n\n- Shared header\n- Home hero",
            \is_array($deterministicArtifacts['virtual_theme_plan'] ?? null) ? $deterministicArtifacts['virtual_theme_plan'] : []
        );
        $chunks = [
            \substr($response, 0, 96),
            \substr($response, 96),
        ];

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(2))
            ->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback) use ($chunks): void {
                foreach ($chunks as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $callback($chunk);
                }
            });
        $aiService->method('generate')->willReturn($response);

        $controller = $this->buildTaskPlanController($aiService);
        $writer = new InMemorySseWriter();

        $method = new \ReflectionMethod($controller, 'handleTaskPlanDetectBootstrapSse');
        $method->setAccessible(true);
        $method->invoke($controller, $writer, $session, 1, $scope, $buildBlueprint);

        $chunkText = $this->collectChunkText($writer);
        self::assertStringContainsString('shared:header', $chunkText);
        self::assertStringContainsString('page:home_page', $chunkText);
        self::assertStringNotContainsString('"markdown"', $chunkText);
        self::assertStringNotContainsString('"virtual_theme_plan"', $chunkText);

        $doneEvents = $writer->eventsByName('done');
        self::assertCount(1, $doneEvents);
        $donePayload = $doneEvents[0]['data'] ?? null;
        self::assertIsArray($donePayload);
        self::assertTrue((bool)($donePayload['success'] ?? false));

        $fresh = $this->sessionService->loadById((int)$session->getId(), 1);
        self::assertNotNull($fresh);
        $freshScope = $fresh->getScopeArray();
        self::assertIsArray($freshScope['task_plan_structured'] ?? null);
        self::assertIsArray($freshScope['virtual_theme_plan']['draft'] ?? null);
        self::assertSame('ai', (string)($freshScope['task_plan_summary']['generation_source'] ?? ''));
    }

    public function testDetectBootstrapSseRecoversWhenAiStreamFails(): void
    {
        ['session' => $session, 'scope' => $scope, 'buildBlueprint' => $buildBlueprint] = $this->prepareFixture();

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(2))
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('Simulated stream interruption'));
        $deterministicArtifacts = (new AiSiteVirtualThemePlanService())->buildTaskPlanArtifacts(
            \array_replace($scope, ['fake_mode' => 1]),
            $buildBlueprint
        );
        $vtPlan = \is_array($deterministicArtifacts['virtual_theme_plan'] ?? null) ? $deterministicArtifacts['virtual_theme_plan'] : [];
        $aiService->method('generate')->willReturnCallback(static function (
            string $prompt,
            $modelCode,
            string $scenarioCode,
            $locale,
            array $params
        ) use ($vtPlan): string {
            if (($params['max_tokens'] ?? 0) <= 2500) {
                return \json_encode([
                    'shared_tasks' => \is_array($vtPlan['shared_tasks'] ?? null) ? $vtPlan['shared_tasks'] : [],
                    'risk_notes' => ['shared fallback'],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
            }

            return \json_encode([
                'page_type' => 'home_page',
                'page_tasks' => \is_array($vtPlan['page_tasks']['home_page'] ?? null) ? $vtPlan['page_tasks']['home_page'] : [],
                'risk_notes' => ['page fallback'],
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        });

        $controller = $this->buildTaskPlanController($aiService);
        $writer = new InMemorySseWriter();

        $method = new \ReflectionMethod($controller, 'handleTaskPlanDetectBootstrapSse');
        $method->setAccessible(true);
        $method->invoke($controller, $writer, $session, 1, $scope, $buildBlueprint);

        self::assertLessThanOrEqual(1, $writer->countEvents('error'));

        $chunkText = $this->collectChunkText($writer);
        self::assertNotSame('', \trim($chunkText));
        self::assertStringNotContainsString('"markdown"', $chunkText);
        self::assertStringNotContainsString('"virtual_theme_plan"', $chunkText);

        $doneEvents = $writer->eventsByName('done');
        self::assertCount(1, $doneEvents);
        $donePayload = $doneEvents[0]['data'] ?? null;
        self::assertIsArray($donePayload);
        self::assertTrue((bool)($donePayload['success'] ?? false));

        $fresh = $this->sessionService->loadById((int)$session->getId(), 1);
        self::assertNotNull($fresh);
        $freshScope = $fresh->getScopeArray();
        self::assertContains((string)($freshScope['task_plan_summary']['generation_source'] ?? ''), ['ai', 'deterministic']);
        self::assertIsArray($freshScope['task_plan_structured'] ?? null);
    }

    /**
     * @return array{session:AiSiteAgentSession, scope:array<string,mixed>, buildBlueprint:array<string,mixed>}
     */
    private function prepareFixture(): array
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'site_title' => 'Task plan detect SSE',
            'site_tagline' => 'Verify task-plan detect SSE',
            'target_domain' => 'task-plan-detect.local.test',
            'brief_description' => 'Verify detect-bootstrap task-plan SSE behavior.',
            'user_description' => 'Verify detect-bootstrap task-plan SSE behavior.',
            'page_types' => [Page::TYPE_HOME],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $phaseOne = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($phaseOne['confirm_plan']['data']['plan_confirmed'] ?? 0));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        /** @var AiSiteScopeCompatibilityService $scopeCompatibilityService */
        $scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        /** @var AiSiteBuildTaskService $buildTaskService */
        $buildTaskService = ObjectManager::getInstance(AiSiteBuildTaskService::class);

        $scope = $scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $scope = $buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            (string)($scope['workspace_track'] ?? '')
        );
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        self::assertNotSame([], $buildBlueprint);

        return [
            'session' => $session,
            'scope' => $scope,
            'buildBlueprint' => $buildBlueprint,
        ];
    }

    private function buildTaskPlanController(AiService $aiService): AiSiteAgent
    {
        return new AiSiteAgent(
            $this->sessionService,
            virtualThemePlanService: new AiSiteVirtualThemePlanService($aiService)
        );
    }

    private function collectChunkText(InMemorySseWriter $writer): string
    {
        $parts = [];
        foreach ($writer->eventsByName('chunk') as $event) {
            $payload = \is_array($event['data'] ?? null) ? $event['data'] : [];
            $parts[] = (string)($payload['content'] ?? $payload['chunk'] ?? '');
        }

        return \implode('', $parts);
    }

    /**
     * @param array<string, mixed> $virtualThemePlan
     */
    private function buildAiTaskPlanResponseJson(string $markdown, array $virtualThemePlan): string
    {
        return \json_encode([
            'markdown' => $markdown,
            'virtual_theme_plan' => $virtualThemePlan,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
