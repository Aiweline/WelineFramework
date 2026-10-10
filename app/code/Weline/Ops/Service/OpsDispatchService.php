<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Queue\OpsTaskDispatchQueue;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * 同步受理运营配置：写 Session + Task，并幂等入队。
 */
final class OpsDispatchService
{
    public function __construct(
        private readonly OpsProgramService $programService,
        private readonly OpsPersonaService $personaService,
        private readonly OpsWebsiteContextReader $contextReader,
        private readonly OpsSessionService $sessionService,
        private readonly OpsTaskService $taskService,
        private readonly OpsRunLogService $runLogService,
    ) {
    }

    /**
     * @param array{
     *   website_id?:int,
     *   lanes?:list<string>,
     *   topic?:string,
     *   note?:string,
     *   auto_draft?:bool
     * } $config
     * @return array{ok:bool,session_id?:string,tasks?:list<array<string,mixed>>,message?:string}
     */
    public function acceptConfig(array $config): array
    {
        $websiteId = max(0, (int)($config['website_id'] ?? 0));
        $program = $this->programService->getOrCreate($websiteId);
        if ((int)$program->getData(\Weline\Ops\Model\OpsWebsiteProgram::schema_fields_ENABLED) !== 1) {
            return ['ok' => false, 'message' => (string)__('请先开启该站 AI 运营')];
        }

        $lanes = $config['lanes'] ?? ['content', 'social'];
        if (!is_array($lanes) || $lanes === []) {
            $lanes = ['content', 'social'];
        }
        $lanes = array_values(array_unique(array_map('strval', $lanes)));

        $persona = $this->personaService->getView($websiteId);
        $siteContext = $this->contextReader->read($websiteId);
        $context = [
            'site' => $siteContext,
            'persona' => $persona,
            'require_approval' => (int)$program->getData(
                \Weline\Ops\Model\OpsWebsiteProgram::schema_fields_REQUIRE_APPROVAL
            ) === 1,
        ];

        $sessionResult = $this->sessionService->create(
            $websiteId,
            (int)$program->getId(),
            [
                'lanes' => $lanes,
                'topic' => trim((string)($config['topic'] ?? '')),
                'note' => trim((string)($config['note'] ?? '')),
                'auto_draft' => (bool)($config['auto_draft'] ?? true),
            ],
            $context,
        );
        if (!($sessionResult['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string)($sessionResult['message'] ?? __('会话创建失败'))];
        }
        $sessionId = (string)($sessionResult['session']['session_id'] ?? '');
        if ($sessionId === '') {
            return ['ok' => false, 'message' => (string)__('会话ID缺失')];
        }

        $topic = trim((string)($config['topic'] ?? ''));
        if ($topic === '') {
            $topic = (string)(($persona['title'] ?? '') ?: __('运营日更'));
        }

        $note = trim((string)($config['note'] ?? ''));
        $autoDraft = (bool)($config['auto_draft'] ?? true);
        $createdTasks = [];

        // 1) 必有日更分析任务
        $analysisTask = $this->createAndEnqueueTask([
            'website_id' => $websiteId,
            'session_id' => $sessionId,
            'scope' => 'insight',
            'task_type' => 'insight.daily_analysis',
            'assignee_agent' => OpsTask::AGENT_OPS_ANALYST,
            'owner_module' => 'Weline_Ops',
            'title' => (string)__('日更分析') . ': ' . $topic,
            'note' => $note,
            'payload' => [
                'topic' => $topic,
                'lane' => 'insight',
            ],
        ]);
        if ($analysisTask !== null) {
            $createdTasks[] = $analysisTask;
        }

        // 2) 分发任务（博客/社媒）
        foreach ($lanes as $lane) {
            $plan = $this->mapLaneToAssignee($lane);
            if ($plan === null) {
                continue;
            }
            $task = $this->createAndEnqueueTask([
                'website_id' => $websiteId,
                'session_id' => $sessionId,
                'scope' => $plan['scope'],
                'task_type' => $plan['task_type'],
                'assignee_agent' => $plan['assignee'],
                'owner_module' => $plan['owner_module'],
                'title' => $plan['title'] . ': ' . $topic,
                'note' => $note,
                'payload' => [
                    'topic' => $topic,
                    'lane' => $lane,
                    'auto_draft' => $autoDraft,
                ],
            ]);
            if ($task !== null) {
                $createdTasks[] = $task;
            }
        }

        if ($createdTasks === []) {
            return ['ok' => false, 'message' => (string)__('未生成可派发任务'), 'session_id' => $sessionId];
        }

        $this->runLogService->write(
            $websiteId,
            \Weline\Ops\Model\OpsRunLog::KIND_DRAFT,
            'ok',
            (string)__('已受理运营配置并入队（含分析任务）'),
            '',
            ['session_id' => $sessionId, 'task_count' => count($createdTasks)],
        );

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'tasks' => $createdTasks,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function createAndEnqueueTask(array $input): ?array
    {
        $websiteId = (int)($input['website_id'] ?? 0);
        $sessionId = (string)($input['session_id'] ?? '');
        $assignee = (string)($input['assignee_agent'] ?? '');
        $taskResult = $this->taskService->create($input);
        if (!($taskResult['ok'] ?? false)) {
            return null;
        }
        $task = $taskResult['task'] ?? [];
        $taskId = (int)($task['task_id'] ?? 0);
        if ($taskId < 1) {
            return null;
        }
        try {
            $this->enqueueTask($taskId, $sessionId, $assignee, $websiteId);
        } catch (\Throwable $e) {
            $this->taskService->setStatus($taskId, OpsTask::STATUS_FAILED);
            $this->runLogService->write(
                $websiteId,
                \Weline\Ops\Model\OpsRunLog::KIND_DRAFT,
                'error',
                'enqueue failed: ' . $e->getMessage(),
                '',
                ['task_id' => $taskId, 'session_id' => $sessionId],
            );

            return null;
        }

        return $this->taskService->get($taskId)['task'] ?? $task;
    }

    /**
     * 人工批准待审任务：社媒才允许 createPublishBatch；博客改为 published。
     * @return array{ok:bool,task?:array<string,mixed>,message?:string}
     */
    public function approveTask(int $taskId): array
    {
        $got = $this->taskService->get($taskId);
        if (!($got['ok'] ?? false)) {
            return $got;
        }
        $task = $got['task'] ?? [];
        if ((string)($task['status'] ?? '') !== OpsTask::STATUS_AWAITING_APPROVAL) {
            return ['ok' => false, 'message' => (string)__('仅待审任务可批准')];
        }

        $assignee = (string)($task['assignee_agent'] ?? '');
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $websiteId = (int)($task['website_id'] ?? 0);

        try {
            if ($assignee === OpsTask::AGENT_BLOG_PUBLISH) {
                $this->publishBlogDraft($payload, $websiteId);
            } elseif ($assignee === OpsTask::AGENT_SOCIAL_OPS) {
                $this->publishSocialDraft($payload);
            }
        } catch (\Throwable $e) {
            $this->taskService->setStatus($taskId, OpsTask::STATUS_FAILED);
            $this->runLogService->write(
                $websiteId,
                \Weline\Ops\Model\OpsRunLog::KIND_PUBLISH,
                'error',
                $e->getMessage(),
                '',
                ['task_id' => $taskId],
            );

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $done = $this->taskService->setStatus($taskId, OpsTask::STATUS_DONE);
        $this->runLogService->write(
            $websiteId,
            \Weline\Ops\Model\OpsRunLog::KIND_PUBLISH,
            'ok',
            (string)__('已批准并外发'),
            '',
            ['task_id' => $taskId, 'assignee' => $assignee],
        );

        return $done;
    }

    /** @return array{scope:string,task_type:string,assignee:string,owner_module:string,title:string}|null */
    private function mapLaneToAssignee(string $lane): ?array
    {
        return match ($lane) {
            'content', 'blog' => [
                'scope' => 'content',
                'task_type' => 'content.blog_draft',
                'assignee' => OpsTask::AGENT_BLOG_PUBLISH,
                'owner_module' => 'Weline_Blog',
                'title' => (string)__('博客草稿'),
            ],
            'social' => [
                'scope' => 'social',
                'task_type' => 'social.creative_draft',
                'assignee' => OpsTask::AGENT_SOCIAL_OPS,
                'owner_module' => 'Weline_Social',
                'title' => (string)__('社媒草稿'),
            ],
            default => null,
        };
    }

    private function enqueueTask(int $taskId, string $sessionId, string $assignee, int $websiteId): void
    {
        $key = 'ops_task:' . $taskId;
        /** @var IdempotentQueueAdmission $admission */
        $admission = ObjectManager::getInstance(IdempotentQueueAdmission::class);
        $admission->admit([
            'class' => OpsTaskDispatchQueue::class,
            'name' => 'Ops task #' . $taskId,
            'module' => 'Weline_Ops',
            'content' => [
                'task_id' => $taskId,
                'session_id' => $sessionId,
                'assignee_agent' => $assignee,
                'website_id' => $websiteId,
            ],
            'biz_key' => $key,
            'idempotency_scope' => 'ops_task_dispatch',
            'idempotency_key' => $key,
            'auto' => true,
            'dispatch' => true,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function publishBlogDraft(array $payload, int $websiteId): void
    {
        $postId = (int)($payload['post_id'] ?? 0);
        if ($postId < 1 || !class_exists(\Weline\Blog\Service\BlogPostAdminService::class)) {
            throw new \RuntimeException((string)__('缺少博客草稿 post_id'));
        }
        /** @var \Weline\Blog\Service\BlogPostAdminService $admin */
        $admin = ObjectManager::getInstance(\Weline\Blog\Service\BlogPostAdminService::class);
        $admin->save([
            'post_id' => $postId,
            'website_id' => $websiteId,
            'slug' => (string)($payload['slug'] ?? ''),
            'title' => (string)($payload['title'] ?? ''),
            'excerpt' => (string)($payload['excerpt'] ?? ''),
            'content' => (string)($payload['content'] ?? ''),
            'locale' => (string)($payload['locale'] ?? 'zh_Hans_CN'),
            'status' => \Weline\Blog\Model\Post::STATUS_PUBLISHED,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function publishSocialDraft(array $payload): void
    {
        $draftId = (int)($payload['draft_id'] ?? 0);
        if ($draftId < 1 || !class_exists(\Weline\Social\Service\SocialPublishService::class)) {
            throw new \RuntimeException((string)__('缺少社媒草稿 draft_id'));
        }
        /** @var \Weline\Social\Service\SocialPublishService $publish */
        $publish = ObjectManager::getInstance(\Weline\Social\Service\SocialPublishService::class);
        $publish->createPublishBatch([
            'draft_id' => $draftId,
            'title' => (string)($payload['title'] ?? ''),
            'fake_mode' => (bool)($payload['fake_mode'] ?? true),
        ]);
    }
}
