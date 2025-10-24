<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModelVersion;
use Weline\Framework\Manager\ObjectManager;

/**
 * Model Versioning Service
 * 
 * Manages AI model version history and transitions.
 * 
 * @package Weline_Ai
 */
class ModelVersioningService
{
    private AiModelVersion $modelVersion;

    public function __construct(AiModelVersion $modelVersion)
    {
        $this->modelVersion = $modelVersion;
    }

    /**
     * Create a new model version
     *
     * @param int $modelId
     * @param string $version
     * @param string|null $versionName
     * @param string|null $modelFile
     * @param bool $isStable
     * @param bool $isCurrent
     * @return AiModelVersion
     */
    public function createVersion(
        int $modelId,
        string $version,
        ?string $versionName = null,
        ?string $modelFile = null,
        bool $isStable = false,
        bool $isCurrent = false
    ): AiModelVersion {
        // If setting as current, unset other current versions
        if ($isCurrent) {
            $this->unsetCurrentVersion($modelId);
        }

        $modelVersion = clone $this->modelVersion;
        $modelVersion->setData([
            AiModelVersion::fields_MODEL_ID => $modelId,
            AiModelVersion::fields_VERSION => $version,
            AiModelVersion::fields_VERSION_NAME => $versionName,
            AiModelVersion::fields_MODEL_FILE => $modelFile,
            AiModelVersion::fields_IS_STABLE => $isStable ? 1 : 0,
            AiModelVersion::fields_IS_CURRENT => $isCurrent ? 1 : 0,
        ]);
        $modelVersion->save();

        return $modelVersion;
    }

    /**
     * Get versions by model ID
     *
     * @param int $modelId
     * @return array
     */
    public function getVersionsByModelId(int $modelId): array
    {
        $results = [];
        $collection = clone $this->modelVersion;
        $items = $collection->where(AiModelVersion::fields_MODEL_ID, $modelId)
            ->order(AiModelVersion::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();

        if ($items) {
            foreach ($items as $item) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Get current version for a model
     *
     * @param int $modelId
     * @return AiModelVersion|null
     */
    public function getCurrentVersion(int $modelId): ?AiModelVersion
    {
        $version = clone $this->modelVersion;
        $result = $version->where(AiModelVersion::fields_MODEL_ID, $modelId)
            ->where(AiModelVersion::fields_IS_CURRENT, 1)
            ->find()
            ->fetch();

        return $result && $result->getId() ? $result : null;
    }

    /**
     * Set a version as current
     *
     * @param int $versionId
     * @return bool
     */
    public function setAsCurrentVersion(int $versionId): bool
    {
        $version = clone $this->modelVersion;
        $version->load($versionId);
        
        if (!$version->getId()) {
            return false;
        }

        $modelId = (int)$version->getData(AiModelVersion::fields_MODEL_ID);
        
        // Unset all current versions for this model
        $this->unsetCurrentVersion($modelId);
        
        // Set this version as current
        $version->setData(AiModelVersion::fields_IS_CURRENT, 1);
        return $version->save();
    }

    /**
     * Unset current version for a model
     *
     * @param int $modelId
     * @return void
     */
    private function unsetCurrentVersion(int $modelId): void
    {
        $version = clone $this->modelVersion;
        $version->where(AiModelVersion::fields_MODEL_ID, $modelId)
            ->update([AiModelVersion::fields_IS_CURRENT => 0]);
    }

    /**
     * Get stable versions
     *
     * @param int $modelId
     * @return array
     */
    public function getStableVersions(int $modelId): array
    {
        $results = [];
        $collection = clone $this->modelVersion;
        $items = $collection->where(AiModelVersion::fields_MODEL_ID, $modelId)
            ->where(AiModelVersion::fields_IS_STABLE, 1)
            ->order(AiModelVersion::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();

        if ($items) {
            foreach ($items as $item) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Delete version
     *
     * @param int $versionId
     * @return bool
     */
    public function deleteVersion(int $versionId): bool
    {
        $version = clone $this->modelVersion;
        $version->load($versionId);
        
        if (!$version->getId()) {
            return false;
        }

        // Prevent deleting current version
        if ((bool)$version->getData(AiModelVersion::fields_IS_CURRENT)) {
            throw new \RuntimeException('Cannot delete current version. Set another version as current first.');
        }

        return $version->delete();
    }
}
