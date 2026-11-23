<?php

declare(strict_types=1);

namespace Weline\Agent\Service\Resumable;

use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Agent\Service\FlowRunner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;

/**
 * 可视化角色流程运行（detached, replayable）
 *
 * 输入：flow_id + input_text + request_id；执行期把 FlowRunner 事件
 * 透传为 SSE 事件（node_start/node_chunk/node_done/node_error/flow_done）。
 */
final class AgentFlowRunTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'agent.flow_run';
    private const MAX_INPUT_BYTES = 32_768;
    private const EFFECT_KEY = 'agent_flow_run';

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertOwner($owner);

        $flowId = (int)($input['flow_id'] ?? 0);
        $inputText = trim((string)($input['input_text'] ?? ''));
        $requestId = trim((string)($input['request_id'] ?? ''));

        if ($flowId <= 0 || $inputText === '' || strlen($inputText) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('Invalid flow run input');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid request_id');
        }

        return new TaskStartRequest(
            input: [
                'flow_id' => $flowId,
                'input_text' => $inputText,
                'project_id' => (int)($input['project_id'] ?? 0),
                'request_id' => $requestId,
            ],
            businessKey: 'agent.flow:' . hash('sha256', $owner->principal . "\0" . $requestId),
            policy: TaskPolicy::defaults(),
        );
    }

    private function assertOwner(TaskOwner $owner): void
    {
        $ok = ($owner->area === 'backend'
                && str_starts_with($owner->principal, 'backend:')
                && $owner->sessionId !== null && $owner->sessionId !== '')
            || ($owner->area === 'frontend'
                && (str_starts_with($owner->principal, 'frontend:')
                    || ($owner->sessionId !== null && $owner->sessionId !== ''
                        && hash_equals('session:' . $owner->sessionId, $owner->principal))));
        if (!$ok) {
            throw new ResumableTaskAccessDeniedException('Flow run requires a valid browser owner.');
        }
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $flowId = (int)($input['flow_id'] ?? 0);
        $inputText = (string)($input['input_text'] ?? '');
        $projectId = (int)($input['project_id'] ?? 0);

        $flow = clone ObjectManager::getInstance(AgentFlow::class);
        $flow->clearData()->load($flowId);
        if (!$flow->getId() || $flow->getData(AgentFlow::schema_fields_STATUS) === AgentFlow::STATUS_DISABLED) {
            return TaskResult::failed('flow_not_found', 'Flow not found or disabled');
        }
        $flowProjectId = (int)$flow->getData(AgentFlow::schema_fields_PROJECT_ID);
        if ($projectId > 0 && $flowProjectId > 0 && $flowProjectId !== $projectId) {
            return TaskResult::failed('project_mismatch', 'Flow does not belong to the requested project');
        }
        // 全局流程（project_id=0）可在任意项目下运行，运行记录继承请求项目
        $effectiveProjectId = $flowProjectId > 0 ? $flowProjectId : $projectId;

        // 创建运行记录（queued → running）
        // clone + clearData：任务重试/同进程多次执行时共享实例可能残留旧主键，
        // 否则会 UPDATE 已有 run 而不是新建
        $run = clone ObjectManager::getInstance(AgentFlowRun::class);
        $run->clearData();
        $run->setData(AgentFlowRun::schema_fields_FLOW_ID, $flowId);
        $run->setData(AgentFlowRun::schema_fields_FLOW_VERSION, (int)$flow->getData(AgentFlow::schema_fields_VERSION));
        $run->setData(AgentFlowRun::schema_fields_PROJECT_ID, $effectiveProjectId);
        $run->setData(AgentFlowRun::schema_fields_TASK_ID, (string)$context->taskId());
        $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_QUEUED);
        $run->setData(AgentFlowRun::schema_fields_INPUT_TEXT, $inputText);
        $run->save();
        $runId = (int)$run->getId();

        $effect = $context->reserveEffect(self::EFFECT_KEY . ':' . $runId);
        if ($effect->alreadyExisted && $effect->state === TaskEffectState::APPLIED) {
            $context->emit('flow_done', ['run_id' => $runId, 'recovered' => true]);
            return TaskResult::completed(['run_id' => $runId, 'recovered_from_effect_ledger' => true]);
        }

        $context->emit('flow_start', [
            'run_id' => $runId,
            'flow_id' => $flowId,
            'attempt' => $context->attempt(),
        ]);

        $runner = ObjectManager::getInstance(FlowRunner::class);
        $onEvent = static function (string $type, array $data = []) use ($context): void {
            $context->throwIfStopRequested();
            $context->heartbeat();
            $context->emit($type, ['attempt' => $context->attempt()] + $data);
        };

        try {
            $runner->run($run, $flow, $onEvent);
        } catch (\Throwable $e) {
            $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_FAILED);
            $run->setData(AgentFlowRun::schema_fields_FINISHED_TIME, time());
            $run->save();
            return TaskResult::failed('flow_run_failed', $e->getMessage(), ['run_id' => $runId]);
        }

        $context->throwIfStopRequested();
        $finalStatus = (string)$run->getData(AgentFlowRun::schema_fields_STATUS);
        $context->completeEffect(self::EFFECT_KEY . ':' . $runId, ['status' => $finalStatus]);

        if ($finalStatus !== AgentFlowRun::STATUS_DONE) {
            return TaskResult::failed('flow_run_' . $finalStatus, 'Flow run ended with status: ' . $finalStatus, [
                'run_id' => $runId,
            ]);
        }

        return TaskResult::completed(['run_id' => $runId, 'status' => $finalStatus]);
    }
}
