<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Ai\Model\AiDefaultModel;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Service\ConfigResolver;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 将 media_manager_ai_draw 场景绑定到当前环境可用的文生图模型。
 */
class AiDrawModelBinder
{
    public const SCENARIO_CODE = 'media_manager_ai_draw';

    /** @var list<string> 参考其他已配置场景的绑定顺序 */
    private const REFERENCE_SCENARIO_CODES = [
        'pagebuilder_ai_site_assets',
    ];

    /** 适配器扫描时的占位默认模型，应在升级时替换为环境真实模型（勿与真实 model_code 重合） */
    private const PLACEHOLDER_MODEL_CODE = '__media_manager_ai_draw_unbound__';

    private const OPENAI_SUPPLIER = 'openai';

    private DefaultModelManager $defaultModelManager;
    private AiModel $aiModel;
    private AiScenarioAdapter $scenarioAdapter;

    public function __construct(
        DefaultModelManager $defaultModelManager,
        AiModel $aiModel,
        AiScenarioAdapter $scenarioAdapter
    ) {
        $this->defaultModelManager = $defaultModelManager;
        $this->aiModel = $aiModel;
        $this->scenarioAdapter = $scenarioAdapter;
    }

    /**
     * 解析当前环境应使用的文生图模型代码。
     */
    public function resolveCurrentText2ImageModelCode(): ?string
    {
        $candidates = [];

        foreach (self::REFERENCE_SCENARIO_CODES as $scenarioCode) {
            $referenceCode = $this->resolveScenarioBinding($scenarioCode);
            if ($referenceCode !== null) {
                $candidates[] = $referenceCode;
            }
        }

        $imageDefault = $this->defaultModelManager->getDefaultModel(AiDefaultModel::SERVICE_TYPE_IMAGE_GENERATION);
        if ($imageDefault && $imageDefault->getId() && $this->isActiveText2ImageModel($imageDefault)) {
            $candidates[] = $imageDefault->getModelCode();
        }

        $markedDefault = $this->aiModel->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE)
            ->where(AiModel::schema_fields_IS_DEFAULT, 1)
            ->find()
            ->fetch();
        if ($markedDefault && $markedDefault->getId()) {
            $candidates[] = $markedDefault->getModelCode();
        }

        $fallbackItems = $this->aiModel->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE)
            ->order(AiModel::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        if ($fallbackItems && \method_exists($fallbackItems, 'getItems')) {
            foreach ($fallbackItems->getItems() as $fallbackModel) {
                if ($fallbackModel instanceof AiModel) {
                    $candidates[] = $fallbackModel->getModelCode();
                }
            }
        }

        $seen = [];
        $usableCodes = [];
        foreach ($candidates as $candidate) {
            $modelCode = \trim((string)$candidate);
            if ($modelCode === '' || isset($seen[$modelCode])) {
                continue;
            }
            $seen[$modelCode] = true;
            $this->ensureModelProviderAccount($modelCode);
            if ($this->loadUsableText2ImageModel($modelCode) !== null) {
                $usableCodes[] = $modelCode;
            }
        }

        return $this->pickPreferredModelCode($usableCodes);
    }

    /**
     * @param list<string> $usableCodes
     */
    private function pickPreferredModelCode(array $usableCodes): ?string
    {
        if ($usableCodes === []) {
            return null;
        }

        $referenceCodes = [];
        foreach (self::REFERENCE_SCENARIO_CODES as $scenarioCode) {
            $referenceCode = $this->resolveScenarioBinding($scenarioCode);
            if ($referenceCode !== null) {
                $referenceCodes[$referenceCode] = true;
            }
        }

        foreach ($usableCodes as $modelCode) {
            if ($modelCode === self::PLACEHOLDER_MODEL_CODE) {
                continue;
            }
            if (isset($referenceCodes[$modelCode])) {
                return $modelCode;
            }
        }

        foreach ($usableCodes as $modelCode) {
            if ($modelCode === self::PLACEHOLDER_MODEL_CODE) {
                continue;
            }
            if (!$this->isOpenAiSupplier($modelCode)) {
                return $modelCode;
            }
        }

        foreach ($usableCodes as $modelCode) {
            if ($modelCode !== self::PLACEHOLDER_MODEL_CODE) {
                return $modelCode;
            }
        }

        return $usableCodes[0] ?? null;
    }

    /**
     * 在需要时把 media_manager_ai_draw 绑定到当前文生图模型。
     *
     * @return array{bound:bool,model_code:?string,reason:string}
     */
    public function bindIfNeeded(): array
    {
        $targetModelCode = $this->resolveCurrentText2ImageModelCode();
        if ($targetModelCode === null) {
            return [
                'bound' => false,
                'model_code' => null,
                'reason' => 'no_active_text2image_model',
            ];
        }

        $adapter = $this->fetchActiveScenarioAdapter(self::SCENARIO_CODE);

        if (!$adapter || !$adapter->getId()) {
            return [
                'bound' => false,
                'model_code' => $targetModelCode,
                'reason' => 'scenario_adapter_not_found',
            ];
        }

        $bindings = $adapter->getModelBindings();
        $currentCode = \trim((string)($bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE] ?? ''));
        if (!$this->shouldRebind($currentCode, $targetModelCode)) {
            $this->ensureModelProviderAccount($targetModelCode);
            return [
                'bound' => false,
                'model_code' => $currentCode !== '' ? $currentCode : $targetModelCode,
                'reason' => 'already_bound',
            ];
        }

        $this->ensureModelProviderAccount($targetModelCode);
        $adapter = $this->fetchActiveScenarioAdapter(self::SCENARIO_CODE);
        if (!$adapter || !$adapter->getId()) {
            return [
                'bound' => false,
                'model_code' => $targetModelCode,
                'reason' => 'scenario_adapter_not_found',
            ];
        }

        $bindings = $adapter->getModelBindings();
        $bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE] = $targetModelCode;
        $adapter->setModelBindings($bindings);
        $adapter->save();

        return [
            'bound' => true,
            'model_code' => $targetModelCode,
            'reason' => 'updated',
        ];
    }

    private function shouldRebind(string $currentCode, string $targetModelCode): bool
    {
        $currentCode = \trim($currentCode);
        if ($currentCode === '') {
            return true;
        }

        if ($this->loadUsableText2ImageModel($currentCode) === null) {
            return true;
        }

        if (!$this->isModelBackendReady($currentCode)) {
            return true;
        }

        if ($currentCode === self::PLACEHOLDER_MODEL_CODE && $currentCode !== $targetModelCode) {
            return true;
        }

        if ($currentCode !== $targetModelCode && $this->isReferenceScenarioModel($targetModelCode)) {
            return true;
        }

        if ($this->isOpenAiSupplier($currentCode) && !$this->isOpenAiSupplier($targetModelCode)) {
            return true;
        }

        return false;
    }

    private function isOpenAiSupplier(string $modelCode): bool
    {
        $model = $this->loadActiveText2ImageModel($modelCode);
        if ($model === null) {
            return false;
        }

        return \strtolower((string)$model->getData(AiModel::schema_fields_SUPPLIER)) === self::OPENAI_SUPPLIER;
    }

    private function isReferenceScenarioModel(string $modelCode): bool
    {
        $modelCode = \trim($modelCode);
        if ($modelCode === '') {
            return false;
        }

        foreach (self::REFERENCE_SCENARIO_CODES as $scenarioCode) {
            if ($this->resolveScenarioBinding($scenarioCode) === $modelCode) {
                return true;
            }
        }

        return false;
    }

    private function resolveScenarioBinding(string $scenarioCode): ?string
    {
        $adapter = $this->fetchActiveScenarioAdapter($scenarioCode);

        if (!$adapter || !$adapter->getId()) {
            return null;
        }

        $modelCode = \trim((string)$adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE));
        if ($modelCode === '' || $this->loadUsableText2ImageModel($modelCode) === null) {
            return null;
        }

        return $modelCode;
    }

    private function isActiveText2ImageModel(AiModel $model): bool
    {
        return (bool)$model->getData(AiModel::schema_fields_IS_ACTIVE)
            && $model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE);
    }

    private function loadUsableText2ImageModel(string $modelCode): ?AiModel
    {
        $model = $this->loadActiveText2ImageModel($modelCode);
        if ($model === null || !$this->isModelBackendReady($modelCode)) {
            return null;
        }

        return $model;
    }

    private function ensureModelProviderAccount(string $modelCode): void
    {
        $modelCode = \trim($modelCode);
        if ($modelCode === '' || $this->isModelBackendReady($modelCode)) {
            return;
        }

        $model = $this->loadActiveText2ImageModel($modelCode);
        if ($model === null) {
            return;
        }

        $providerConfig = $model->getProviderConfig();
        if ((int)($providerConfig['account_id'] ?? 0) > 0) {
            return;
        }

        $accountId = $this->resolveReferenceAccountId((string)$model->getData(AiModel::schema_fields_SUPPLIER));
        if ($accountId <= 0) {
            return;
        }

        $providerConfig['account_id'] = $accountId;
        $model->setData(
            AiModel::schema_fields_PROVIDER_CONFIG,
            \json_encode($providerConfig, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        );
        $model->save();
    }

    private function resolveReferenceAccountId(string $supplier): int
    {
        $supplier = \strtolower(\trim($supplier));
        if ($supplier === '') {
            return 0;
        }

        foreach (self::REFERENCE_SCENARIO_CODES as $scenarioCode) {
            $adapter = $this->fetchActiveScenarioAdapter($scenarioCode);
            if (!$adapter || !$adapter->getId()) {
                continue;
            }

            $referenceCode = \trim((string)$adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE));
            $referenceModel = $this->loadActiveText2ImageModel($referenceCode);
            if ($referenceModel === null) {
                continue;
            }
            if (\strtolower((string)$referenceModel->getData(AiModel::schema_fields_SUPPLIER)) !== $supplier) {
                continue;
            }

            $accountId = (int)($referenceModel->getProviderConfig()['account_id'] ?? 0);
            if ($accountId > 0) {
                return $accountId;
            }
        }

        return 0;
    }

    private function fetchActiveScenarioAdapter(string $scenarioCode): ?AiScenarioAdapter
    {
        $scenarioCode = \trim($scenarioCode);
        if ($scenarioCode === '') {
            return null;
        }

        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
        if (\method_exists($adapter, 'clearData')) {
            $adapter->clearData();
        }

        $adapter = $adapter->reset()
            ->where(AiScenarioAdapter::schema_fields_CODE, $scenarioCode)
            ->where(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return ($adapter && $adapter->getId()) ? $adapter : null;
    }

    private function isModelBackendReady(string $modelCode): bool
    {
        $modelCode = \trim($modelCode);
        if ($modelCode === '') {
            return false;
        }

        try {
            $config = ObjectManager::getInstance(ConfigResolver::class)
                ->resolveConfig($modelCode, [], null, true);
        } catch (\Throwable) {
            return false;
        }

        return \trim((string)($config['api_key'] ?? '')) !== ''
            && \trim((string)($config['base_url'] ?? '')) !== '';
    }

    private function loadActiveText2ImageModel(string $modelCode): ?AiModel
    {
        $modelCode = \trim($modelCode);
        if ($modelCode === '') {
            return null;
        }

        $model = $this->aiModel->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$model || !$model->getId() || !$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE)) {
            return null;
        }

        return $model;
    }
}
