<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsQueueObservationTools;

/**
 * 博客发布执行智能体：仅写草稿，公开发布由 Ops 审批后触发。
 */
class BlogPublishAgent implements AgentInterface
{
    public function getCode(): string
    {
        return 'blog_publish';
    }

    public function getName(): string
    {
        return (string)__('博客发布智能体');
    }

    public function getDescription(): string
    {
        return (string)__('根据 Ops 会话上下文创建博客草稿，禁止直接公开发布。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['ops_dispatch', 'blog_publish'];
    }

    public function getTools(): array
    {
        return class_exists(OpsQueueObservationTools::class)
            ? OpsQueueObservationTools::all()
            : [];
    }

    public function getSystemPrompt(array $context = []): string
    {
        return 'Create blog drafts only. Never set status=published without Ops approval.';
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $websiteId = (int)($params['website_id'] ?? 0);
        $session = is_array($params['session'] ?? null) ? $params['session'] : [];
        $task = is_array($params['task'] ?? null) ? $params['task'] : [];
        $config = is_array($session['config'] ?? null) ? $session['config'] : [];
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];

        $topic = trim((string)($payload['topic'] ?? $config['topic'] ?? __('运营博客草稿')));
        $slugBase = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($topic)) ?: 'ops-draft';
        $slug = trim($slugBase, '-') . '-' . substr(md5((string)($params['session_id'] ?? '') . $topic), 0, 8);
        $excerpt = (string)__('由运营调度智能体生成的草稿，待审批后发布。');
        $content = '<p>' . htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') . '</p><p>'
            . htmlspecialchars((string)($config['note'] ?? $prompt), ENT_QUOTES, 'UTF-8') . '</p>';

        try {
            /** @var BlogPostAdminService $admin */
            $admin = ObjectManager::getInstance(BlogPostAdminService::class);
            $saved = $admin->save([
                'website_id' => $websiteId,
                'slug' => $slug,
                'title' => $topic,
                'excerpt' => $excerpt,
                'content' => $content,
                'locale' => 'zh_Hans_CN',
                'status' => Post::STATUS_DRAFT,
            ]);
        } catch (\Throwable $e) {
            return AgentResult::failure($e->getMessage(), $this->getCode());
        }

        $postId = (int)($saved['post_id'] ?? $saved[Post::schema_fields_ID] ?? 0);
        $opsResult = [
            'post_id' => $postId,
            'slug' => $slug,
            'title' => $topic,
            'excerpt' => $excerpt,
            'content' => $content,
            'locale' => 'zh_Hans_CN',
            'status' => Post::STATUS_DRAFT,
        ];

        return new AgentResult(
            content: (string)__('博客草稿已创建') . ' #' . $postId,
            success: $postId > 0,
            agentCode: $this->getCode(),
            messages: [['ops_result' => $opsResult]],
        );
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return 2;
    }
}
