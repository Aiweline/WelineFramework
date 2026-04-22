<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSiteTaskPlanFlowIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testManualTaskPlanFieldEditPersistsStructuredDraft(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $structured = [
            'signature' => 'manual-field-edit-test',
            'shared_tasks' => [],
            'page_tasks' => [
                Page::TYPE_HOME => [
                    [
                        'task_key' => 'page:home:hero',
                        'label' => 'Hero copy',
                        'task_script' => [
                            'scene' => 'home hero',
                            'field_content_requirements' => [
                                [
                                    'field' => 'hero_title',
                                    'sample' => 'Edited hero H1 from inline field editor',
                                    'implementation_note' => 'Persist as structured task data, not markdown only.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'autosave' => '1',
                'scope_patch' => [
                    'virtual_theme_plan' => [
                        'draft_markdown' => 'Manual field edit draft',
                    ],
                    'task_plan_markdown' => 'Manual field edit draft',
                    'task_plan_structured' => $structured,
                ],
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();

        self::assertSame(
            'Edited hero H1 from inline field editor',
            (string)($scope['task_plan_structured']['page_tasks'][Page::TYPE_HOME][0]['task_script']['field_content_requirements'][0]['sample'] ?? '')
        );
        self::assertSame(
            'Edited hero H1 from inline field editor',
            (string)($scope['virtual_theme_plan']['draft']['page_tasks'][Page::TYPE_HOME][0]['task_script']['field_content_requirements'][0]['sample'] ?? '')
        );
        self::assertSame(0, (int)($scope['task_plan_summary']['shared_task_count'] ?? -1));
        self::assertSame(1, (int)($scope['task_plan_summary']['page_task_count'] ?? 0));
        self::assertSame('manual_structured_edit', (string)($scope['task_plan_summary']['generation_source'] ?? ''));
    }

    public function testTaskPlanMustBeConfirmedBeforeBuildCanStart(): void
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
            'site_title' => 'Task plan gate test',
            'site_tagline' => 'Second-stage plan must be confirmed',
            'target_domain' => 'task-plan-gate.local.test',
            'brief_description' => 'Verify the second-stage task plan must be confirmed before build starts.',
            'user_description' => 'Verify the second-stage task plan must be confirmed before build starts.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
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
        self::assertSame(
            'visual_edit',
            (string)($phaseOne['confirm_plan']['data']['stage'] ?? ''),
            'post-confirm-plan should advance persisted workspace stage to visual_edit'
        );

        $startTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-task-plan',
            'POST',
            'postStartTaskPlan',
            [],
            [
                'public_id' => $publicId,
            ]
        );
        self::assertTrue((bool)($startTaskPlanPayload['success'] ?? false), \json_encode($startTaskPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertIsArray($startTaskPlanPayload['task_plan'] ?? null);
        self::assertNotSame('', (string)($startTaskPlanPayload['task_plan']['markdown'] ?? ''));
        self::assertTrue((bool)($startTaskPlanPayload['data']['has_virtual_theme_plan'] ?? false));
        self::assertSame(0, (int)($startTaskPlanPayload['data']['task_plan_confirmed'] ?? 0));

        $blockedBuildPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertFalse((bool)($blockedBuildPayload['success'] ?? true), \json_encode($blockedBuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame('TASK_PLAN_REQUIRED_BEFORE_BUILD', (string)($blockedBuildPayload['code'] ?? ''));

        $confirmTaskPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-confirm-task-plan',
            'POST',
            'postConfirmTaskPlan',
            [],
            [
                'public_id' => $publicId,
            ]
        );
        self::assertTrue((bool)($confirmTaskPlanPayload['success'] ?? false), \json_encode($confirmTaskPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertSame(1, (int)($confirmTaskPlanPayload['data']['task_plan_confirmed'] ?? 0));

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $scope['task_plan_confirmed'] = 0;
        $this->sessionService->replaceScope($session->getId(), 1, $scope);

        $startBuildPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-build',
            'POST',
            'postStartBuild',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($startBuildPayload['success'] ?? false), \json_encode($startBuildPayload, \JSON_UNESCAPED_UNICODE));
        self::assertNotSame('', (string)($startBuildPayload['execution_token'] ?? ''));
    }
}
