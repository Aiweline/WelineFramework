<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Image;

use Weline\Ai\Api\Image\TextToImageScenarioBindingInterface;
use Weline\Ai\Api\Image\TextToImageScenarioBindingRequest;
use Weline\Ai\Api\Image\TextToImageScenarioBindingResult;
use Weline\Ai\Model\AiDefaultModel;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Service\ConfigResolver;
use Weline\Ai\Service\DefaultModelManager;

/**
 * Ai-owned implementation for selecting and binding a usable text-to-image model.
 * ORM models and provider configuration never cross the public Api boundary.
 */
final class TextToImageScenarioBindingManager implements TextToImageScenarioBindingInterface
{
    private const OPENAI_SUPPLIER = 'openai';

    public function __construct(
        private readonly DefaultModelManager $defaultModelManager,
        private readonly AiModel $aiModel,
        private readonly AiScenarioAdapter $scenarioAdapter,
        private readonly ConfigResolver $configResolver,
    ) {
    }

    public function resolveModelCode(TextToImageScenarioBindingRequest $request): ?string
    {
        $candidates = [];

        foreach ($request->getReferenceScenarioCodes() as $scenarioCode) {
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
        if ($fallbackItems && method_exists($fallbackItems, 'getItems')) {
            foreach ($fallbackItems->getItems() as $fallbackModel) {
                if ($fallbackModel instanceof AiModel) {
                    $candidates[] = $fallbackModel->getModelCode();
                }
            }
        }

        $seen = [];
        $usableCodes = [];
        foreach ($candidates as $candidate) {
            $modelCode = trim((string)$candidate);
            if ($modelCode === '' || isset($seen[$modelCode])) {
                continue;
            }
            $seen[$modelCode] = true;
            $this->ensureModelProviderAccount($modelCode, $request);
            if ($this->loadUsableText2ImageModel($modelCode) !== null) {
                $usableCodes[] = $modelCode;
            }
        }

        return $this->pickPreferredModelCode($usableCodes, $request);
    }

    public function bindIfNeeded(TextToImageScenarioBindingRequest $request): TextToImageScenarioBindingResult
    {
        $targetModelCode = $this->resolveModelCode($request);
        if ($targetModelCode === null) {
            return new TextToImageScenarioBindingResult(
                false,
                null,
                TextToImageScenarioBindingResult::REASON_NO_ACTIVE_MODEL,
            );
        }

        $adapter = $this->fetchActiveScenarioAdapter($request->getScenarioCode());
        if (!$adapter || !$adapter->getId()) {
            return new TextToImageScenarioBindingResult(
                false,
                $targetModelCode,
                TextToImageScenarioBindingResult::REASON_SCENARIO_NOT_FOUND,
            );
        }

        $bindings = $adapter->getModelBindings();
        $currentCode = trim((string)($bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE] ?? ''));
        if (!$this->shouldRebind($currentCode, $targetModelCode, $request)) {
            $this->ensureModelProviderAccount($targetModelCode, $request);
            return new TextToImageScenarioBindingResult(
                false,
                $currentCode !== '' ? $currentCode : $targetModelCode,
                TextToImageScenarioBindingResult::REASON_ALREADY_BOUND,
            );
        }

        $this->ensureModelProviderAccount($targetModelCode, $request);
        $adapter = $this->fetchActiveScenarioAdapter($request->getScenarioCode());
        if (!$adapter || !$adapter->getId()) {
            return new TextToImageScenarioBindingResult(
                false,
                $targetModelCode,
                TextToImageScenarioBindingResult::REASON_SCENARIO_NOT_FOUND,
            );
        }

        $bindings = $adapter->getModelBindings();
        $bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE] = $targetModelCode;
        $adapter->setModelBindings($bindings);
        $adapter->save();

        return new TextToImageScenarioBindingResult(
            true,
            $targetModelCode,
            TextToImageScenarioBindingResult::REASON_UPDATED,
        );
    }

    /**
     * @param list<string> $usableCodes
     */
    private function pickPreferredModelCode(
        array $usableCodes,
        TextToImageScenarioBindingRequest $request,
    ): ?string {
        if ($usableCodes === []) {
            return null;
        }

        $referenceCodes = [];
        foreach ($request->getReferenceScenarioCodes() as $scenarioCode) {
            $referenceCode = $this->resolveScenarioBinding($scenarioCode);
            if ($referenceCode !== null) {
                $referenceCodes[$referenceCode] = true;
            }
        }

        $placeholderModelCode = $request->getPlaceholderModelCode();
        foreach ($usableCodes as $modelCode) {
            if ($modelCode === $placeholderModelCode) {
                continue;
            }
            if (isset($referenceCodes[$modelCode])) {
                return $modelCode;
            }
        }

        foreach ($usableCodes as $modelCode) {
            if ($modelCode === $placeholderModelCode) {
                continue;
            }
            if (!$this->isOpenAiSupplier($modelCode)) {
                return $modelCode;
            }
        }

        foreach ($usableCodes as $modelCode) {
            if ($modelCode !== $placeholderModelCode) {
                return $modelCode;
            }
        }

        return $usableCodes[0] ?? null;
    }

    private function shouldRebind(
        string $currentCode,
        string $targetModelCode,
        TextToImageScenarioBindingRequest $request,
    ): bool {
        $currentCode = trim($currentCode);
        if ($currentCode === '') {
            return true;
        }

        if ($this->loadUsableText2ImageModel($currentCode) === null) {
            return true;
        }

        if (!$this->isModelBackendReady($currentCode)) {
            return true;
        }

        if ($currentCode === $request->getPlaceholderModelCode() && $currentCode !== $targetModelCode) {
            return true;
        }

        if ($currentCode !== $targetModelCode && $this->isReferenceScenarioModel($targetModelCode, $request)) {
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

        return strtolower((string)$model->getData(AiModel::schema_fields_SUPPLIER)) === self::OPENAI_SUPPLIER;
    }

    private function isReferenceScenarioModel(
        string $modelCode,
        TextToImageScenarioBindingRequest $request,
    ): bool {
        $modelCode = trim($modelCode);
        if ($modelCode === '') {
            return false;
        }

        foreach ($request->getReferenceScenarioCodes() as $scenarioCode) {
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

        $modelCode = trim((string)$adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE));
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

    private function ensureModelProviderAccount(
        string $modelCode,
        TextToImageScenarioBindingRequest $request,
    ): void {
        $modelCode = trim($modelCode);
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

        $accountId = $this->resolveReferenceAccountId(
            (string)$model->getData(AiModel::schema_fields_SUPPLIER),
            $request,
        );
        if ($accountId <= 0) {
            return;
        }

        $providerConfig['account_id'] = $accountId;
        $model->setData(
            AiModel::schema_fields_PROVIDER_CONFIG,
            json_encode($providerConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $model->save();
    }

    private function resolveReferenceAccountId(
        string $supplier,
        TextToImageScenarioBindingRequest $request,
    ): int {
        $supplier = strtolower(trim($supplier));
        if ($supplier === '') {
            return 0;
        }

        foreach ($request->getReferenceScenarioCodes() as $scenarioCode) {
            $adapter = $this->fetchActiveScenarioAdapter($scenarioCode);
            if (!$adapter || !$adapter->getId()) {
                continue;
            }

            $referenceCode = trim((string)$adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE));
            $referenceModel = $this->loadActiveText2ImageModel($referenceCode);
            if ($referenceModel === null) {
                continue;
            }
            if (strtolower((string)$referenceModel->getData(AiModel::schema_fields_SUPPLIER)) !== $supplier) {
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
        $scenarioCode = trim($scenarioCode);
        if ($scenarioCode === '') {
            return null;
        }

        $adapter = $this->scenarioAdapter;
        if (method_exists($adapter, 'clearData')) {
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
        $modelCode = trim($modelCode);
        if ($modelCode === '') {
            return false;
        }

        try {
            $config = $this->configResolver->resolveConfig($modelCode, [], null, true);
        } catch (\Throwable) {
            return false;
        }

        return trim((string)($config['api_key'] ?? '')) !== ''
            && trim((string)($config['base_url'] ?? '')) !== '';
    }

    private function loadActiveText2ImageModel(string $modelCode): ?AiModel
    {
        $modelCode = trim($modelCode);
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
