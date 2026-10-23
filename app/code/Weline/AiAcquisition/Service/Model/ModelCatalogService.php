<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Model;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 获客模型目录：列出可用的文本模型、解析模型与按千 token 估算成本。
 *
 * 价格口径与 AI 模块一致：`token_price_input` / `token_price_output` 为每 1000 token 的人民币价格。
 * 纯计算方法（quoteFromPrices / estimateRunFromPrices）不查库，便于单测与浏览器估算复用。
 */
class ModelCatalogService
{
    public const CURRENCY = 'CNY';

    private ?AiModel $modelPrototype = null;

    private ?DefaultModelManager $defaultModelManager = null;

    public function __construct(?AiModel $modelPrototype = null, ?DefaultModelManager $defaultModelManager = null)
    {
        $this->modelPrototype = $modelPrototype;
        $this->defaultModelManager = $defaultModelManager;
    }

    /**
     * 列出可用于文本对话的模型（启用且主要模态为 text2text）。
     *
     * @return list<array<string,mixed>>
     */
    public function listTextModels(): array
    {
        $model = clone $this->model();
        $rows = $model->clearData()->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)
            ->order(AiModel::schema_fields_SUPPLIER, 'ASC')
            ->order(AiModel::schema_fields_MODEL_CODE, 'ASC')
            ->select()
            ->fetchArray();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->normalizeModelRow($row);
        }

        return $items;
    }

    /**
     * 解析模型：空代码取 AI 模块 chat 默认模型，否则按 model_code 查启用模型。
     *
     * @return array<string,mixed>|null
     */
    public function resolve(?string $modelCode): ?array
    {
        $modelCode = trim((string)$modelCode);
        if ($modelCode === '') {
            $model = $this->defaultManager()->getDefaultModel('chat');
            if (!$model || !$model->getId() || !$model->isActive()) {
                return null;
            }

            return $this->normalizeModelRow($model->getData());
        }

        $model = clone $this->model();
        $record = $model->clearData()->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$record || !$record->getId()) {
            return null;
        }

        return $this->normalizeModelRow($record->getData());
    }

    /**
     * 估算单次调用成本；模型不存在时抛 InvalidArgumentException。
     *
     * @return array<string,mixed>
     */
    public function quote(string $modelCode, int $inputTokens, int $outputTokens, int $iterations = 1): array
    {
        $model = $this->resolve($modelCode);
        if ($model === null) {
            throw new \InvalidArgumentException(
                (string)__('模型不存在或不可用：%{1}', [$modelCode])
            );
        }

        return $this->quoteFromPrices(
            (float)$model['input_price'],
            (float)$model['output_price'],
            $inputTokens,
            $outputTokens,
            $iterations,
            (string)$model['model_code'],
        );
    }

    /**
     * 纯计算：按每 1000 token 单价估算成本（不查库）。
     *
     * @return array{
     *     model_code:string,input_cost:float,output_cost:float,total_cost:float,
     *     currency:string,priced:bool,input_tokens:int,output_tokens:int,iterations:int
     * }
     */
    public function quoteFromPrices(
        float $inputPrice,
        float $outputPrice,
        int $inputTokens,
        int $outputTokens,
        int $iterations = 1,
        string $modelCode = '',
    ): array {
        $inputPrice = max(0.0, $inputPrice);
        $outputPrice = max(0.0, $outputPrice);
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);
        $iterations = max(1, $iterations);

        $inputCost = ($inputTokens / 1000) * $inputPrice * $iterations;
        $outputCost = ($outputTokens / 1000) * $outputPrice * $iterations;

        return [
            'model_code' => $modelCode,
            'input_cost' => round($inputCost, 6),
            'output_cost' => round($outputCost, 6),
            'total_cost' => round($inputCost + $outputCost, 6),
            'currency' => self::CURRENCY,
            'priced' => $inputPrice > 0.0 && $outputPrice > 0.0,
            'input_price' => $inputPrice,
            'output_price' => $outputPrice,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'iterations' => $iterations,
        ];
    }

    /**
     * 按默认轮次估算整次运行成本；模型不存在时抛 InvalidArgumentException。
     *
     * @return array<string,mixed>
     */
    public function estimateRun(
        string $modelCode,
        int $iterations = 8,
        int $avgInput = 4000,
        int $avgOutput = 1200,
    ): array {
        $model = $this->resolve($modelCode);
        if ($model === null) {
            throw new \InvalidArgumentException(
                (string)__('模型不存在或不可用：%{1}', [$modelCode])
            );
        }

        return $this->estimateRunFromPrices(
            (float)$model['input_price'],
            (float)$model['output_price'],
            $iterations,
            $avgInput,
            $avgOutput,
            (string)$model['model_code'],
        );
    }

    /**
     * 纯计算：整次运行的最低/典型/最高成本（不查库）。
     *
     * min 按平均 token 的一半、max 按两倍估算；typical 使用给定平均值。
     *
     * @return array<string,mixed>
     */
    public function estimateRunFromPrices(
        float $inputPrice,
        float $outputPrice,
        int $iterations = 8,
        int $avgInput = 4000,
        int $avgOutput = 1200,
        string $modelCode = '',
    ): array {
        $iterations = max(1, $iterations);
        $avgInput = max(0, $avgInput);
        $avgOutput = max(0, $avgOutput);

        $perIteration = $this->quoteFromPrices(
            $inputPrice,
            $outputPrice,
            $avgInput,
            $avgOutput,
            1,
            $modelCode,
        );
        $typical = $this->quoteFromPrices(
            $inputPrice,
            $outputPrice,
            $avgInput,
            $avgOutput,
            $iterations,
            $modelCode,
        );
        $min = $this->quoteFromPrices(
            $inputPrice,
            $outputPrice,
            (int)floor($avgInput / 2),
            (int)floor($avgOutput / 2),
            $iterations,
            $modelCode,
        );
        $max = $this->quoteFromPrices(
            $inputPrice,
            $outputPrice,
            $avgInput * 2,
            $avgOutput * 2,
            $iterations,
            $modelCode,
        );

        return [
            'model_code' => $modelCode,
            'iterations' => $iterations,
            'avg_input_tokens' => $avgInput,
            'avg_output_tokens' => $avgOutput,
            'per_iteration' => $perIteration,
            'min' => $min,
            'max' => $max,
            'typical' => $typical,
            'min_cost' => $min['total_cost'],
            'max_cost' => $max['total_cost'],
            'typical_cost' => $typical['total_cost'],
            'currency' => self::CURRENCY,
            'priced' => $perIteration['priced'],
        ];
    }

    /**
     * 统一模型输出结构，供后端接口与浏览器桥共用。
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeModelRow(array $row): array
    {
        $inputPrice = (float)($row[AiModel::schema_fields_TOKEN_PRICE_INPUT] ?? 0);
        $outputPrice = (float)($row[AiModel::schema_fields_TOKEN_PRICE_OUTPUT] ?? 0);

        return [
            'id' => (int)($row[AiModel::schema_fields_ID] ?? 0),
            'model_code' => (string)($row[AiModel::schema_fields_MODEL_CODE] ?? ''),
            'name' => (string)($row[AiModel::schema_fields_NAME] ?? ''),
            'supplier' => (string)($row[AiModel::schema_fields_SUPPLIER] ?? ''),
            'input_price' => $inputPrice,
            'output_price' => $outputPrice,
            'currency' => self::CURRENCY,
            'priced' => $inputPrice > 0.0 && $outputPrice > 0.0,
            'primary_modality' => (string)($row[AiModel::schema_fields_PRIMARY_MODALITY] ?? AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT),
            'max_tokens' => (int)($row[AiModel::schema_fields_MAX_TOKENS] ?? 0),
        ];
    }

    private function model(): AiModel
    {
        return $this->modelPrototype ??= ObjectManager::getInstance(AiModel::class);
    }

    private function defaultManager(): DefaultModelManager
    {
        return $this->defaultModelManager ??= ObjectManager::getInstance(DefaultModelManager::class);
    }
}
