<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Configuration;

use Weline\Ai\Api\AiModel as AiModelSnapshot;
use Weline\Ai\Api\Configuration\ProviderAvailability;
use Weline\Ai\Api\Configuration\ScenarioConfigurationInterface;
use Weline\Ai\Api\Configuration\ScenarioRecord;
use Weline\Ai\Api\Configuration\UsageSummary;
use Weline\Ai\Api\ScenarioAdapterInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\Provider\AccountService;

final class ScenarioConfiguration implements ScenarioConfigurationInterface
{
    public function __construct(
        private readonly AiScenarioAdapter $scenarioModel,
        private readonly AiModel $modelRecord,
        private readonly AdapterScanner $adapterScanner,
        private readonly AccountService $accountService,
        private readonly UsageRecord $usageRecord,
    ) {
    }

    public function scenario(string $code, bool $scan = false): ?ScenarioRecord
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if ($scan) {
            try {
                $this->adapterScanner->scanAllAdapters();
            } catch (\Throwable) {
            }
        }

        $record = $this->scenarioModel->clear()
            ->where(AiScenarioAdapter::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        if (!$record || !$record->getId()) {
            return null;
        }

        return new ScenarioRecord(
            id: (int)$record->getId(),
            code: (string)$record->getData(AiScenarioAdapter::schema_fields_CODE),
            name: (string)$record->getData(AiScenarioAdapter::schema_fields_NAME),
            version: (string)$record->getData(AiScenarioAdapter::schema_fields_VERSION),
            active: (bool)$record->getData(AiScenarioAdapter::schema_fields_IS_ACTIVE),
            defaultModel: (string)$record->getData(AiScenarioAdapter::schema_fields_DEFAULT_MODEL),
            modelBindings: $record->getModelBindings(),
        );
    }

    public function activeModels(string $primaryModality): array
    {
        $primaryModality = AiModelSnapshot::normalizePrimaryModality($primaryModality);
        $rows = $this->modelRecord->clear()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, $primaryModality)
            ->order(AiModel::schema_fields_NAME, 'ASC')
            ->select()
            ->fetchArray();

        $models = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $models[] = AiModelSnapshot::fromArray($row);
            }
        }

        return $models;
    }

    public function model(
        string $modelCode,
        bool $activeOnly = false,
        ?string $primaryModality = null,
    ): ?AiModelSnapshot {
        $modelCode = trim($modelCode);
        if ($modelCode === '') {
            return null;
        }

        $query = $this->modelRecord->clear()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode);
        if ($activeOnly) {
            $query->where(AiModel::schema_fields_IS_ACTIVE, 1);
        }
        if ($primaryModality !== null) {
            $query->where(
                AiModel::schema_fields_PRIMARY_MODALITY,
                AiModelSnapshot::normalizePrimaryModality($primaryModality),
            );
        }
        $record = $query->find()->fetch();
        if (!$record || !$record->getId()) {
            return null;
        }

        return AiModelSnapshot::fromArray((array)$record->getData());
    }

    public function bindModel(string $scenarioCode, string $primaryModality, ?string $modelCode): bool
    {
        $scenarioCode = trim($scenarioCode);
        if ($scenarioCode === '') {
            return false;
        }
        $record = $this->scenarioModel->clear()
            ->where(AiScenarioAdapter::schema_fields_CODE, $scenarioCode)
            ->find()
            ->fetch();
        if (!$record || !$record->getId()) {
            return false;
        }

        $primaryModality = AiModelSnapshot::normalizePrimaryModality($primaryModality);
        $modelCode = trim((string)$modelCode);
        $bindings = $record->getModelBindings();
        if ($modelCode === '') {
            unset($bindings[$primaryModality]);
        } else {
            $bindings[$primaryModality] = $modelCode;
        }
        if ($primaryModality === AiModelSnapshot::PRIMARY_MODALITY_TEXT_TO_TEXT) {
            $record->setData(AiScenarioAdapter::schema_fields_DEFAULT_MODEL, $modelCode);
        }

        $record->setModelBindings($bindings)->save();

        return true;
    }

    public function scanAdapters(): int
    {
        return count($this->adapterScanner->scanAllAdapters());
    }

    public function adapter(string $code, bool $scan = false): ?ScenarioAdapterInterface
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if ($scan) {
            try {
                $this->adapterScanner->scanAllAdapters();
            } catch (\Throwable) {
            }
        }

        try {
            return $this->adapterScanner->getAdapter($code);
        } catch (\Throwable) {
            return null;
        }
    }

    public function providerAvailability(string $modelCode): ProviderAvailability
    {
        $modelCode = trim($modelCode);
        $record = $modelCode !== ''
            ? $this->modelRecord->clear()
                ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                ->find()
                ->fetch()
            : null;
        if (!$record || !$record->getId()) {
            return new ProviderAvailability('', false);
        }

        try {
            $providerCode = trim((string)$this->accountService->getProviderByModel($record));
            $available = $providerCode !== '' && (bool)$this->accountService->getAvailableAccount($providerCode);
        } catch (\Throwable) {
            $providerCode = '';
            $available = false;
        }

        return new ProviderAvailability($providerCode, $available);
    }

    public function usageByRequestPrefix(string $requestPrefix): ?UsageSummary
    {
        $requestPrefix = trim($requestPrefix);
        if ($requestPrefix === '') {
            return null;
        }
        $rows = $this->usageRecord->clear()
            ->where(UsageRecord::schema_fields_REQUEST_ID, $requestPrefix . '%', 'LIKE')
            ->select()
            ->fetchArray();
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $actualCost = 0.0;
        foreach ($rows as $row) {
            $promptTokens += (int)($row[UsageRecord::schema_fields_PROMPT_TOKENS] ?? 0);
            $completionTokens += (int)($row[UsageRecord::schema_fields_COMPLETION_TOKENS] ?? 0);
            $totalTokens += (int)($row[UsageRecord::schema_fields_TOTAL_TOKENS] ?? 0);
            $actualCost += (float)($row[UsageRecord::schema_fields_TOTAL_COST] ?? 0);
        }

        return new UsageSummary($promptTokens, $completionTokens, $totalTokens, $actualCost);
    }
}
