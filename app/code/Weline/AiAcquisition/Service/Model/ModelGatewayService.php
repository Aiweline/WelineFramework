<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Model;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 获客模型网关：解析模型供应商与可用账户，注入 api_key/base_url 后调用 provider。
 *
 * 与 AiService 的账户注入口径保持一致；任何一步取不到账户或供应商都抛 RuntimeException，
 * 不返回伪造的成功结果。智能体循环与浏览器 step 接口共用本网关。
 */
class ModelGatewayService
{
    public const CURRENCY = ModelCatalogService::CURRENCY;

    private ?ModelCatalogService $catalogService = null;

    public function __construct(?ModelCatalogService $catalogService = null)
    {
        $this->catalogService = $catalogService;
    }

    /**
     * 发起一次对话请求，返回统一的 assistant/usage/cost 结构。
     *
     * @param array<string,mixed>|AiModel $model 模型目录行或模型实体
     * @param list<array<string,mixed>> $messages OpenAI 风格消息列表
     * @param list<array<string,mixed>> $tools function calling 工具定义
     * @param array<string,mixed> $params 额外 provider 参数（temperature/max_tokens 等）
     * @return array<string,mixed>
     */
    public function chat(
        array|AiModel $model,
        array $messages,
        array $tools = [],
        array $params = [],
    ): array {
        $entity = $this->hydrateModel($model);
        $modelCode = $entity->getModelCode();
        $accountService = ObjectManager::getInstance(AccountService::class);

        $providerCode = $accountService->getProviderByModel($entity);
        if ($providerCode === null || trim($providerCode) === '') {
            throw new \RuntimeException(
                (string)__('无法确定模型 %{1} 的供应商，请检查 AI 模型配置。', [$modelCode])
            );
        }

        $account = $accountService->getAvailableAccount(
            $providerCode,
            (bool)($params['allow_zero_balance_provider'] ?? false)
        );
        if (!$account || !$account->getId()) {
            throw new \RuntimeException(
                (string)__('供应商 %{1} 没有可用账户（需激活且连通成功），请先在 AI 模块添加账户。', [$providerCode])
            );
        }

        $this->injectAccountConfig($entity, $account);

        try {
            $provider = ObjectManager::getInstance(ProviderFactory::class)->getProvider($entity);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                (string)__('模型 %{1} 无法匹配供应商 %{2}：%{3}', [
                    $modelCode,
                    $providerCode,
                    $failure->getMessage(),
                ]),
                0,
                $failure
            );
        }

        $requestParams = $params;
        $requestParams['messages'] = $messages;
        $requestParams['tools'] = $tools;
        unset($requestParams['allow_zero_balance_provider']);

        $result = $provider->generate($entity, '', $requestParams);

        $content = (string)($result['content'] ?? '');
        $toolCalls = is_array($result['tool_calls'] ?? null)
            ? array_values($result['tool_calls'])
            : [];
        $usage = $this->normalizeUsage(is_array($result['usage'] ?? null) ? $result['usage'] : []);

        $assistantMessage = $result['assistant_message'] ?? null;
        if (!is_array($assistantMessage)) {
            $assistantMessage = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }
        if (!isset($assistantMessage['role'])) {
            $assistantMessage['role'] = 'assistant';
        }
        if (!array_key_exists('content', $assistantMessage)) {
            $assistantMessage['content'] = $content;
        }
        if ($toolCalls !== [] && empty($assistantMessage['tool_calls'])) {
            $assistantMessage['tool_calls'] = $toolCalls;
        }

        $quote = $this->catalog()->quoteFromPrices(
            $entity->getTokenPriceInput(),
            $entity->getTokenPriceOutput(),
            $usage['prompt_tokens'],
            $usage['completion_tokens'],
            1,
            $modelCode,
        );

        $cost = round((float)$quote['total_cost'], 6);
        $this->recordUsageLog($modelCode, $usage, $cost);

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'assistant_message' => $assistantMessage,
            'usage' => $usage,
            'cost' => $cost,
            'currency' => self::CURRENCY,
            'model_code' => $modelCode,
            'priced' => (bool)$quote['priced'],
        ];
    }

    /**
     * 将目录行或模型实体还原为可调用 provider 的 AiModel。
     */
    private function hydrateModel(array|AiModel $model): AiModel
    {
        if ($model instanceof AiModel) {
            if ($model->getModelCode() === '') {
                throw new \RuntimeException((string)__('模型代码不能为空。'));
            }

            return $model;
        }

        $modelCode = trim((string)($model['model_code'] ?? ''));
        if ($modelCode === '') {
            throw new \RuntimeException((string)__('模型代码不能为空。'));
        }

        $record = ObjectManager::getInstance(AiModel::class);
        $record = $record->clearData()->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();

        if (!$record || !$record->getId()) {
            throw new \RuntimeException(
                (string)__('模型不存在：%{1}', [$modelCode])
            );
        }

        return $record;
    }

    /**
     * 注入账户密钥、地址与代理配置（对齐 AiService::injectAccountConfig 的逻辑）。
     */
    private function injectAccountConfig(AiModel $model, Account $account): void
    {
        $modelConfig = $model->getConfig();
        $modelConfig['api_key'] = $account->getDecryptedApiKey();

        if ($account->getData(Account::schema_fields_API_SECRET)) {
            $modelConfig['api_secret'] = $account->getData(Account::schema_fields_API_SECRET);
        }
        if ($account->getData(Account::schema_fields_BASE_URL)) {
            $modelConfig['base_url'] = $account->getData(Account::schema_fields_BASE_URL);
            $modelConfig['api_url'] = $account->getData(Account::schema_fields_BASE_URL);
        }

        $proxyConfig = $account->getProxyConfig();
        if (!empty($proxyConfig['enabled'])) {
            $model->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxyConfig));
        }

        $model->setData(AiModel::schema_fields_CONFIG, json_encode($modelConfig));

        // 与 AiService::mergeConfigToProviderConfig 对齐：账户注入后的 config 必须覆盖
        // provider_config 里的旧 base_url/api_key，否则账户切换后仍打到旧地址。
        $providerConfig = $model->getProviderConfig();
        $model->setData(
            AiModel::schema_fields_PROVIDER_CONFIG,
            json_encode(array_merge($providerConfig, $modelConfig), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $model->setData('_provider_account', $account);
    }

    /**
     * @param array<string,mixed> $usage
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    private function normalizeUsage(array $usage): array
    {
        $promptTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $totalTokens = max(
            $promptTokens + $completionTokens,
            (int)($usage['total_tokens'] ?? 0)
        );

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    private function catalog(): ModelCatalogService
    {
        return $this->catalogService ??= ObjectManager::getInstance(ModelCatalogService::class);
    }

    /**
     * 把浏览器/智能体的模型调用写入 AI 模块用量台账，便于后台统一看花销。
     *
     * @param array{prompt_tokens:int,completion_tokens:int,total_tokens:int} $usage
     */
    private function recordUsageLog(string $modelCode, array $usage, float $cost): void
    {
        try {
            /** @var AiUsageLog $log */
            $log = ObjectManager::getInstance(AiUsageLog::class);
            $log->reset()->clearData()->setData([
                AiUsageLog::schema_fields_MODEL_CODE => $modelCode,
                AiUsageLog::schema_fields_REQUEST_DATA => json_encode([
                    'source' => 'ai_acquisition',
                    'prompt_tokens' => $usage['prompt_tokens'],
                    'completion_tokens' => $usage['completion_tokens'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                AiUsageLog::schema_fields_TOTAL_TOKENS => $usage['total_tokens'],
                AiUsageLog::schema_fields_TOTAL_COST => $cost,
                AiUsageLog::schema_fields_STATUS => AiUsageLog::STATUS_SUCCESS,
                AiUsageLog::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } catch (\Throwable $failure) {
            w_log_warning('AI 获客用量台账写入失败', ['error' => $failure->getMessage()]);
        }
    }
}
