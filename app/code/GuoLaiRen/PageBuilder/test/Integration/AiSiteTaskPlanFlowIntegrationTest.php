<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionArtifactService;
use Weline\Framework\Manager\ObjectManager;

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
        $scope = ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class)
            ->hydrateScopeForStage((int)$session->getId(), $session->getScopeArray(), AiSiteAgentSession::STAGE_VISUAL_EDIT);

        self::assertSame(
            'Edited hero H1 from inline field editor',
            (string)($scope['virtual_theme_plan']['draft']['page_tasks'][Page::TYPE_HOME][0]['task_script']['field_content_requirements'][0]['sample'] ?? '')
        );
        if (\is_array($scope['task_plan_summary'] ?? null)) {
            self::assertSame(0, (int)($scope['task_plan_summary']['shared_task_count'] ?? 0));
            self::assertSame(1, (int)($scope['task_plan_summary']['page_task_count'] ?? 0));
            self::assertSame('manual_structured_edit', (string)($scope['task_plan_summary']['generation_source'] ?? ''));
        }
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
        $scope = ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class)
            ->hydrateScopeForStage((int)$fresh->getId(), $fresh->getScopeArray(), AiSiteAgentSession::STAGE_PLAN);
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
        $this->sessionService->setStage($session->getId(), 1, AiSiteAgentSession::STAGE_VISUAL_EDIT);

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
        $scope = ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class)
            ->hydrateScopeForStage((int)$fresh->getId(), $fresh->getScopeArray(), AiSiteAgentSession::STAGE_VISUAL_EDIT);
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

        $scopePatch = [
            'site_title' => 'Confirm task plan persist test',
            'site_tagline' => 'Stage-one confirmed before task-plan confirm',
            'target_domain' => 'confirm-task-plan-persist.local.test',
            'brief_description' => 'Verify confirming task plan persists confirmed artifacts.',
            'user_description' => 'Verify confirming task plan persists confirmed artifacts.',
            'page_types' => [Page::TYPE_HOME],
        ];
        $phaseOne = $this->generateAndConfirmPlan($publicId, $scopePatch);
        self::assertSame(1, (int)($phaseOne['confirm_plan']['data']['plan_confirmed'] ?? 0));

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
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $this->sessionService->setStage($session->getId(), 1, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->mergeScope($session->getId(), 1, [
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
        $scope = ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class)
            ->hydrateScopeForStage((int)$fresh->getId(), $fresh->getScopeArray(), AiSiteAgentSession::STAGE_VISUAL_EDIT);
        self::assertSame(1, (int)($scope['task_plan_confirmed'] ?? 0));
        self::assertSame([], $scope['virtual_theme_plan']['draft'] ?? []);
        self::assertSame('', \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? '')));
        self::assertNotSame([], $scope['virtual_theme_plan']['confirmed'] ?? []);
        self::assertSame($markdown, (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''));
        self::assertSame(1, (int)($scope['virtual_theme_plan']['confirmed']['_storage_compacted'] ?? 0));
        self::assertSame(
            'confirm-persist-signature',
            (string)($scope['virtual_theme_plan']['confirmed']['execution_blueprint_ref']['task_plan_signature'] ?? '')
        );
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

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $this->sessionService->setStage($session->getId(), 1, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->mergeScope($session->getId(), 1, [
            'execution_blueprint' => [
                'signature' => 'task-plan-gate-blueprint',
                'tasks' => [['task_key' => 'page:home_page:hero']],
            ],
            'execution_blueprint_confirmed_signature' => 'task-plan-gate-blueprint',
            'execution_blueprint_confirmed_at' => \date('Y-m-d H:i:s'),
            'virtual_theme_plan' => [
                'draft' => [
                    'signature' => 'task-plan-draft-gate',
                    'shared_tasks' => [],
                    'page_tasks' => [
                        Page::TYPE_HOME => [[
                            'task_key' => 'page:home_page:hero',
                            'task_type' => 'page_section',
                            'task_script' => ['field_content_requirements' => []],
                        ]],
                    ],
                ],
                'draft_markdown' => '# Draft task plan before confirm',
                'draft_generated_at' => \date('Y-m-d H:i:s'),
                'confirmed' => [],
                'confirmed_markdown' => '',
            ],
            'task_plan_structured' => [
                'signature' => 'task-plan-draft-gate',
                'shared_tasks' => [],
                'page_tasks' => [
                    Page::TYPE_HOME => [[
                        'task_key' => 'page:home_page:hero',
                        'task_type' => 'page_section',
                        'task_script' => ['field_content_requirements' => []],
                    ]],
                ],
            ],
            'task_plan_markdown' => '# Draft task plan before confirm',
            'task_plan_confirmed' => 0,
        ]);

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
