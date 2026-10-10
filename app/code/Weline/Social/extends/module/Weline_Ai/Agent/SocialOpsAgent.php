<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsQueueObservationTools;
use Weline\Social\Service\SocialCreativeService;

/**
 * 社媒运营执行智能体：仅写创意草稿，批准前禁止入 SocialPublishQueue。
 */
class SocialOpsAgent implements AgentInterface
{
    public function getCode(): string
    {
        return 'social_ops';
    }

    public function getName(): string
    {
        return (string)__('社媒运营智能体');
    }

    public function getDescription(): string
    {
        return (string)__('根据 Ops 会话创建社媒创意草稿；公开外发须 Ops 审批。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['ops_dispatch', 'social_ops'];
    }

    public function getTools(): array
    {
        return class_exists(OpsQueueObservationTools::class)
            ? OpsQueueObservationTools::all()
            : [];
    }

    public function getSystemPrompt(array $context = []): string
    {
        return 'Create social creative drafts only. Never call createPublishBatch or enqueue publish.';
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $session = is_array($params['session'] ?? null) ? $params['session'] : [];
        $task = is_array($params['task'] ?? null) ? $params['task'] : [];
        $config = is_array($session['config'] ?? null) ? $session['config'] : [];
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];

        $topic = trim((string)($payload['topic'] ?? $config['topic'] ?? __('运营社媒草稿')));
        $note = trim((string)($config['note'] ?? ''));
        $creativePrompt = $topic . ($note !== '' ? "\n" . $note : '') . "\n" . $prompt;

        try {
            /** @var SocialCreativeService $creative */
            $creative = ObjectManager::getInstance(SocialCreativeService::class);
            $result = $creative->generateCreative([
                'title' => $topic,
                'prompt' => $creativePrompt,
                'platforms' => ['fake_browser'],
                'fake_mode' => true,
                'use_ai' => false,
            ]);
        } catch (\Throwable $e) {
            return AgentResult::failure($e->getMessage(), $this->getCode());
        }

        $draft = is_array($result['draft'] ?? null) ? $result['draft'] : [];
        $draftId = (int)($draft['id'] ?? $draft['draft_id'] ?? 0);
        $opsResult = [
            'draft_id' => $draftId,
            'title' => $topic,
            'fake_mode' => true,
            'status' => (string)($draft['status'] ?? 'ready'),
        ];

        return new AgentResult(
            content: (string)__('社媒草稿已创建') . ' #' . $draftId,
            success: $draftId > 0,
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
