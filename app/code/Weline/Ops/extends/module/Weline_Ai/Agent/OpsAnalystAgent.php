<?php

declare(strict_types=1);

namespace Weline\Ops\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Service\OpsAnalysisService;
use Weline\Ops\Service\OpsQueueObservationTools;

/**
 * 日更分析智能体：聚合 Visitor/GSC/社媒连接信号，写 RunLog，更新 Session。
 */
class OpsAnalystAgent implements AgentInterface
{
    public function getCode(): string
    {
        return OpsTask::AGENT_OPS_ANALYST;
    }

    public function getName(): string
    {
        return (string)__('运营分析智能体');
    }

    public function getDescription(): string
    {
        return (string)__('汇总站内事件与 GSC/社媒连接健康，写入日更分析日志供分发任务使用。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['ops_dispatch', 'ops_analysis'];
    }

    public function getTools(): array
    {
        return OpsQueueObservationTools::all();
    }

    public function getSystemPrompt(array $context = []): string
    {
        return 'You are the Weline ops analyst. Summarize Visitor/SEO/Social connection health into a daily analysis. Do not publish content.';
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $websiteId = (int)($params['website_id'] ?? 0);
        $sessionId = trim((string)($params['session_id'] ?? ''));
        $taskId = (int)($params['task_id'] ?? 0);

        /** @var OpsAnalysisService $analysis */
        $analysis = ObjectManager::getInstance(OpsAnalysisService::class);
        $result = $analysis->analyze($websiteId, $sessionId, $taskId);
        if (!($result['ok'] ?? false)) {
            return AgentResult::failure((string)($result['message'] ?? 'analysis failed'), $this->getCode());
        }

        return new AgentResult(
            content: (string)($result['summary'] ?? __('日更分析已完成')),
            success: true,
            agentCode: $this->getCode(),
            messages: [['ops_result' => $result['ops_result'] ?? []]],
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
