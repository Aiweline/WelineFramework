<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSiteTaskPlanFlowIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
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
