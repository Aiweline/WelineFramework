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
        self::assertArrayNotHasKey('data', $mergePayload, 'Autosave responses must stay compact and avoid echoing full task-plan drafts.');

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

    public function testSavePlanDraftCommandPersistsExistingServerPlanWithoutLargePatch(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $this->sessionService->mergeScope($session->getId(), 1, [
            'plan_markdown' => '# Existing server-side stage-one plan',
            'plan_json' => [
                'site_strategy' => ['site_display_name' => 'Server plan'],
                'pages' => [Page::TYPE_HOME => ['blocks' => []]],
            ],
            'plan_structured' => [
                'site_strategy' => ['site_display_name' => 'Server plan'],
                'pages' => [Page::TYPE_HOME => ['blocks' => []]],
            ],
        ]);

        $savePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'autosave' => '1',
                'save_target' => 'plan',
                'save_plan_draft' => '1',
            ]
        );

        self::assertTrue((bool)($savePayload['success'] ?? false), \json_encode($savePayload, \JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('data', $savePayload, 'Stage-one save command must not echo full workspace state.');

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $scope = $fresh->getScopeArray();
        self::assertSame('# Existing server-side stage-one plan', (string)($scope['plan_markdown'] ?? ''));
        self::assertSame('Server plan', (string)($scope['plan_json']['site_strategy']['site_display_name'] ?? ''));
    }

    public function testSaveTaskPlanDraftCommandPersistsExistingServerDraftWithoutLargePatch(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $structured = [
            'signature' => 'save-command-signature',
            'shared_tasks' => [],
            'page_tasks' => [
                Page::TYPE_HOME => [
                    [
                        'task_key' => 'page:home_page:hero',
                        'task_type' => 'page_section',
                        'task_script' => [
                            'field_content_requirements' => [
                                ['field' => 'title', 'sample' => 'Server-side draft save'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->sessionService->mergeScope($session->getId(), 1, [
            'virtual_theme_plan' => [
                'draft' => $structured,
                'draft_markdown' => '# Server-side draft save',
                'draft_generated_at' => \date('Y-m-d H:i:s'),
            ],
            'task_plan_structured' => $structured,
            'task_plan_markdown' => '# Server-side draft save',
        ]);

        $savePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'autosave' => '1',
                'save_target' => 'task_plan',
                'save_task_plan_draft' => '1',
            ]
        );

        self::assertTrue((bool)($savePayload['success'] ?? false), \json_encode($savePayload, \JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('data', $savePayload, 'Save command must not echo full workspace state.');

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $scope = $fresh->getScopeArray();
        self::assertSame('Server-side draft save', (string)($scope['virtual_theme_plan']['draft']['page_tasks'][Page::TYPE_HOME][0]['task_script']['field_content_requirements'][0]['sample'] ?? ''));
    }

    public function testConfirmTaskPlanPersistsStructuredDraftAndConfirmedPlan(): void
    {
        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));

        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);

        $structured = [
            'signature' => 'confirm-persist-signature',
            'shared_tasks' => [
                [
                    'task_key' => 'shared:header',
                    'task_type' => 'shared_component',
                    'task_script' => ['field_content_requirements' => []],
                ],
            ],
            'page_tasks' => [
                Page::TYPE_HOME => [
                    [
                        'task_key' => 'page:home_page:hero',
                        'task_type' => 'page_section',
                        'task_script' => [
                            'field_content_requirements' => [
                                ['field' => 'title', 'sample' => 'Persisted hero title'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $markdown = '# Persisted second-stage plan';
        $this->sessionService->mergeScope($session->getId(), 1, [
            'plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'draft' => $structured,
                'draft_markdown' => $markdown,
                'draft_generated_at' => \date('Y-m-d H:i:s'),
                'confirmed' => [],
                'confirmed_markdown' => '',
                'confirmed_at' => '',
                'confirmed_signature' => '',
            ],
            'task_plan_structured' => $structured,
            'task_plan_markdown' => $markdown,
            'task_plan_confirmed' => 0,
        ]);

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

        $fresh = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($fresh);
        $scope = $fresh->getScopeArray();
        self::assertSame(1, (int)($scope['task_plan_confirmed'] ?? 0));
        self::assertSame([], $scope['virtual_theme_plan']['draft'] ?? []);
        self::assertSame('', \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? '')));
        self::assertNotSame([], $scope['virtual_theme_plan']['confirmed'] ?? []);
        self::assertSame($markdown, (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''));
        self::assertSame('Persisted hero title', (string)($scope['virtual_theme_plan']['confirmed']['page_tasks'][Page::TYPE_HOME][0]['task_script']['field_content_requirements'][0]['sample'] ?? ''));
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
        $initialTaskPlan = $startTaskPlanPayload['data']['task_plan'] ?? null;
        self::assertIsArray($initialTaskPlan);
        self::assertTrue(
            \trim((string)($initialTaskPlan['markdown'] ?? '')) !== ''
            || \is_array($initialTaskPlan['structured'] ?? null),
            \json_encode($startTaskPlanPayload, \JSON_UNESCAPED_UNICODE)
        );
        self::assertTrue((bool)($startTaskPlanPayload['data']['has_virtual_theme_plan'] ?? false));
        self::assertSame(0, (int)($startTaskPlanPayload['data']['task_plan_confirmed'] ?? 0));
        $draftSession = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($draftSession);
        $draftScope = $draftSession->getScopeArray();
        self::assertNotSame([], $draftScope['virtual_theme_plan']['draft'] ?? [], 'Generated second-stage draft must be persisted before the next render.');
        self::assertNotSame('', \trim((string)($draftScope['virtual_theme_plan']['draft_markdown'] ?? '')));
        self::assertNotSame([], $draftScope['task_plan_structured'] ?? []);

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
        self::assertArrayNotHasKey('scope', $confirmTaskPlanPayload['data'] ?? []);
        self::assertArrayNotHasKey('events', $confirmTaskPlanPayload['data'] ?? []);
        self::assertArrayNotHasKey('task_plan_structured', $confirmTaskPlanPayload['data'] ?? []);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        self::assertSame([], $scope['virtual_theme_plan']['draft'] ?? [], 'Confirmed task plan may compact the generated draft after persisting confirmed data.');
        self::assertNotSame([], $scope['virtual_theme_plan']['confirmed'] ?? [], 'Confirmed task plan must persist the confirmed structured plan before flipping the confirmed flag.');
        self::assertNotSame('', \trim((string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? '')));
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
