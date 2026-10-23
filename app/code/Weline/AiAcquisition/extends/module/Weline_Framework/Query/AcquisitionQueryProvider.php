<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Service\AgentScanner;
use Weline\AiAcquisition\Model\PromptProfile;
use Weline\AiAcquisition\Service\Lead\LeadService;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Run\RunService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * AI 获客后台统一查询入口。
 *
 * 页面只负责开页；线索/提示词/运行的增删改全部经 query-bin 到这里。
 */
final class AcquisitionQueryProvider implements QueryProviderInterface
{
    private const ACL_LEADS = 'Weline_AiAcquisition::lead_list';
    private const ACL_RUNS = 'Weline_AiAcquisition::run_list';

    public function __construct(
        private readonly LeadService $leadService,
        private readonly RunService $runService,
        private readonly ModelCatalogService $modelCatalog,
        private readonly PromptProfile $promptModel,
    ) {
    }

    public function getProviderName(): string
    {
        return 'ai_acquisition';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'leadList' => $this->leadService->listing($this->filters($params), $this->page($params), $this->pageSize($params)),
                'leadGet' => $this->leadGet($params),
                'leadSave' => $this->leadSave($params),
                'leadDelete' => $this->leadDelete($params),
                'leadBulkStatus' => $this->leadBulkStatus($params),
                'leadBulkDelete' => $this->leadBulkDelete($params),
                'leadStats' => $this->leadService->stats(),
                'promptList' => $this->promptList(),
                'promptSave' => $this->promptSave($params),
                'promptDelete' => $this->promptDelete($params),
                'modelList' => ['items' => $this->modelCatalog->listTextModels()],
                'modelQuote' => $this->modelQuote($params),
                'runCreate' => $this->runCreate($params),
                'runList' => $this->runService->listing($this->filters($params), $this->page($params), $this->pageSize($params)),
                'runStatus' => $this->runStatus($params),
                'runCancel' => $this->runCancel($params),
                'agentInfo' => $this->agentInfo(),
                default => throw new \InvalidArgumentException((string)__('AI 获客查询器不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable $throwable) {
            w_log_error('[AiAcquisition] query failed: ' . $operation . ' ' . $throwable->getMessage());
            return ['success' => false, 'message' => (string)__('AI 获客服务暂时不可用，请稍后重试。')];
        }
    }

    private function leadGet(array $params): array
    {
        $id = $this->positiveInt($params, 'id');
        $lead = $this->leadService->find($id);
        if ($lead === null) {
            throw new \InvalidArgumentException((string)__('线索不存在。'));
        }

        return ['success' => true, 'lead' => $lead];
    }

    private function leadSave(array $params): array
    {
        $payload = $params['payload'] ?? $params;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException((string)__('线索数据格式不正确。'));
        }
        $payload['run_id'] = (int)($params['run_id'] ?? 0);
        $result = $this->leadService->save($payload);

        return ['success' => true] + $result;
    }

    private function leadDelete(array $params): array
    {
        $id = $this->positiveInt($params, 'id');
        if (!$this->leadService->delete($id)) {
            throw new \InvalidArgumentException((string)__('线索不存在或删除失败。'));
        }

        return ['success' => true];
    }

    private function leadBulkStatus(array $params): array
    {
        $ids = $this->ids($params);
        $status = trim((string)($params['status'] ?? ''));
        $changed = $this->leadService->bulkStatus($ids, $status);

        return ['success' => true, 'changed' => $changed];
    }

    private function leadBulkDelete(array $params): array
    {
        $ids = $this->ids($params);
        $changed = $this->leadService->bulkDelete($ids);

        return ['success' => true, 'changed' => $changed];
    }

    private function promptList(): array
    {
        $items = [];
        try {
            $rows = $this->promptModel->reset()->clearQuery()
                ->order(PromptProfile::schema_fields_IS_DEFAULT, 'DESC')
                ->order(PromptProfile::schema_fields_ID, 'DESC')
                ->select()
                ->fetchArray();
            $items = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $items = [];
        }

        $builtin = [
            'id' => 0,
            'code' => 'default_b2b_acquisition',
            'name' => (string)__('内置通用 B2B 获客提示词'),
            'body' => '',
            'is_default' => 0,
            'builtin' => true,
        ];
        try {
            $agent = ObjectManager::getInstance(AgentScanner::class)->getAgent('ai_acquisition');
            if ($agent !== null) {
                $builtin['body'] = $agent->getSystemPrompt([]);
            }
        } catch (\Throwable) {
            $builtin['body'] = '';
        }

        return ['success' => true, 'items' => array_merge([$builtin], $items)];
    }

    private function promptSave(array $params): array
    {
        $payload = $params['payload'] ?? $params;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException((string)__('提示词数据格式不正确。'));
        }
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $body = trim((string)($payload['body'] ?? ''));
        if ($code === '' || $name === '' || $body === '') {
            throw new \InvalidArgumentException((string)__('提示词代码、名称和正文不能为空。'));
        }
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/i', $code)) {
            throw new \InvalidArgumentException((string)__('提示词代码只能包含字母、数字、下划线和短横线。'));
        }
        if ($code === 'default_b2b_acquisition') {
            throw new \InvalidArgumentException((string)__('内置提示词代码为保留代码，请换一个代码。'));
        }

        $id = max(0, (int)($payload['id'] ?? 0));
        $model = $id > 0
            ? $this->promptModel->reset()->clearQuery()->load($id)
            : ObjectManager::getInstance(PromptProfile::class);
        if ($id > 0 && !$model->getId()) {
            throw new \InvalidArgumentException((string)__('提示词档案不存在。'));
        }
        if ($id === 0) {
            $exists = ObjectManager::getInstance(PromptProfile::class)
                ->reset()->clearQuery()
                ->where(PromptProfile::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($exists && $exists->getId()) {
                throw new \InvalidArgumentException((string)__('提示词代码已存在。'));
            }
        }

        $isDefault = (int)($payload['is_default'] ?? 0) === 1 ? 1 : 0;
        if ($isDefault === 1) {
            $this->promptModel->reset()->clearQuery()->update([PromptProfile::schema_fields_IS_DEFAULT => 0]);
        }
        $model->setData([
            PromptProfile::schema_fields_CODE => $code,
            PromptProfile::schema_fields_NAME => $name,
            PromptProfile::schema_fields_BODY => $body,
            PromptProfile::schema_fields_IS_DEFAULT => $isDefault,
        ])->save();

        return [
            'success' => true,
            'item' => [
                'id' => (int)$model->getId(),
                'code' => $code,
                'name' => $name,
                'body' => $body,
                'is_default' => $isDefault,
            ],
        ];
    }

    private function promptDelete(array $params): array
    {
        $id = $this->positiveInt($params, 'id');
        $model = $this->promptModel->reset()->clearQuery()->load($id);
        if (!$model->getId()) {
            throw new \InvalidArgumentException((string)__('提示词档案不存在。'));
        }
        $model->delete();

        return ['success' => true];
    }

    private function modelQuote(array $params): array
    {
        $modelCode = trim((string)($params['model_code'] ?? ''));
        if ($modelCode === '') {
            throw new \InvalidArgumentException((string)__('请选择模型。'));
        }
        $input = max(0, (int)($params['input_tokens'] ?? 4000));
        $output = max(0, (int)($params['output_tokens'] ?? 1200));
        $iterations = max(1, (int)($params['iterations'] ?? 8));

        $quote = $this->modelCatalog->quote($modelCode, $input, $output, $iterations);
        $estimate = $this->modelCatalog->estimateRun($modelCode, $iterations, $input, $output);

        return ['success' => true, 'quote' => $quote, 'estimate' => $estimate];
    }

    private function runCreate(array $params): array
    {
        $payload = $params['payload'] ?? $params;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException((string)__('运行参数格式不正确。'));
        }
        $payload['created_by'] = (int)($params['created_by'] ?? 0);

        $run = $this->runService->create($payload);

        return ['success' => true] + $run;
    }

    private function runStatus(array $params): array
    {
        $id = $this->positiveInt($params, 'id');
        $run = $this->runService->status($id);
        if ($run === null) {
            throw new \InvalidArgumentException((string)__('运行记录不存在。'));
        }

        return ['success' => true, 'run' => $run];
    }

    private function runCancel(array $params): array
    {
        $id = $this->positiveInt($params, 'id');
        $this->runService->cancel($id);

        return ['success' => true, 'run' => $this->runService->status($id)];
    }

    private function agentInfo(): array
    {
        $agent = ObjectManager::getInstance(AgentScanner::class)->getAgent('ai_acquisition');
        if ($agent === null) {
            return ['success' => true, 'agent' => null, 'message' => (string)__('智能体未注册，请先执行 ai:agent:scan。')];
        }

        $tools = [];
        foreach ($agent->getTools() as $tool) {
            $tools[] = ['name' => $tool->getName(), 'description' => $tool->getDescription()];
        }

        return [
            'success' => true,
            'agent' => [
                'code' => $agent->getCode(),
                'name' => $agent->getName(),
                'description' => $agent->getDescription(),
                'version' => $agent->getVersion(),
                'scenarios' => $agent->getScenarios(),
                'tools' => $tools,
                'max_iterations' => $agent->getMaxIterations(),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        $leadAcl = ['kind' => 'source', 'source_id' => self::ACL_LEADS];
        $runAcl = ['kind' => 'source', 'source_id' => self::ACL_RUNS];

        return [
            'provider' => 'ai_acquisition',
            'name' => (string)__('AI 获客'),
            'description' => (string)__('客户线索、获客提示词、AI 模型预估与智能体运行管理。'),
            'module' => 'Weline_AiAcquisition',
            'operations' => [
                $this->op('leadList', 'inspection', 'read', $leadAcl, ['keyword', 'continent', 'country', 'status', 'source_platform', 'min_score', 'website_id', 'page', 'page_size']),
                $this->op('leadGet', 'inspection', 'read', $leadAcl, ['id']),
                $this->op('leadSave', 'inspection', 'write', $leadAcl, ['payload']),
                $this->op('leadDelete', 'inspection', 'write', $leadAcl, ['id']),
                $this->op('leadBulkStatus', 'inspection', 'write', $leadAcl, ['ids', 'status']),
                $this->op('leadBulkDelete', 'inspection', 'write', $leadAcl, ['ids']),
                $this->op('leadStats', 'inspection', 'read', $leadAcl, []),
                $this->op('promptList', 'inspection', 'read', $leadAcl, []),
                $this->op('promptSave', 'inspection', 'write', $leadAcl, ['payload']),
                $this->op('promptDelete', 'inspection', 'write', $leadAcl, ['id']),
                $this->op('modelList', 'inspection', 'read', $runAcl, []),
                $this->op('modelQuote', 'inspection', 'read', $runAcl, ['model_code', 'input_tokens', 'output_tokens', 'iterations']),
                $this->op('runCreate', 'inspection', 'write', $runAcl, ['payload']),
                $this->op('runList', 'inspection', 'read', $runAcl, ['status', 'model_code', 'website_id', 'keyword', 'page', 'page_size']),
                $this->op('runStatus', 'inspection', 'read', $runAcl, ['id']),
                $this->op('runCancel', 'inspection', 'write', $runAcl, ['id']),
                $this->op('agentInfo', 'inspection', 'read', $runAcl, []),
            ],
        ];
    }

    /**
     * @param list<string> $paramNames
     * @return array<string,mixed>
     */
    private function op(string $name, string $summary, string $mode, array $acl, array $paramNames): array
    {
        $params = [];
        foreach ($paramNames as $param) {
            $params[] = ['name' => $param, 'type' => 'mixed', 'required' => false];
        }

        return [
            'name' => $name,
            'summary' => $name . ' · ' . $summary,
            'frontend' => true,
            'backend' => true,
            'mode' => $mode,
            'auth' => 'backend',
            'backend_acl' => $acl,
            'params' => $params,
        ];
    }

    /** @return array<string,mixed> */
    private function filters(array $params): array
    {
        $keys = ['keyword', 'continent', 'country', 'status', 'source_platform', 'min_score', 'website_id', 'model_code'];
        $filters = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== '' && $params[$key] !== null) {
                $filters[$key] = $params[$key];
            }
        }

        return $filters;
    }

    private function page(array $params): int
    {
        return max(1, (int)($params['page'] ?? 1));
    }

    private function pageSize(array $params): int
    {
        return min(100, max(1, (int)($params['page_size'] ?? 20)));
    }

    private function positiveInt(array $params, string $key): int
    {
        $value = (int)($params[$key] ?? 0);
        if ($value <= 0) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须是正整数。', [$key]));
        }

        return $value;
    }

    /** @return list<int> */
    private function ids(array $params): array
    {
        $raw = $params['ids'] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException((string)__('ids 必须是数组。'));
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException((string)__('请选择至少一条记录。'));
        }

        return array_values($ids);
    }
}
