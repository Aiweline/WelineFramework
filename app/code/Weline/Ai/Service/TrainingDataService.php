<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiTrainingData;
use Weline\Framework\Manager\ObjectManager;

/**
 * Training Data Service
 * 
 * Manages AI model training data and fine-tuning datasets.
 * 
 * @package Weline_Ai
 */
class TrainingDataService
{
    private AiTrainingData $trainingData;

    public function __construct(AiTrainingData $trainingData)
    {
        $this->trainingData = $trainingData;
    }

    /**
     * Create training data record
     *
     * @param int $modelId
     * @param string $dataType
     * @param string|null $dataContent
     * @param string|null $dataUrl
     * @param array|null $metadata
     * @return AiTrainingData
     */
    public function createTrainingData(
        int $modelId,
        string $dataType,
        ?string $dataContent = null,
        ?string $dataUrl = null,
        ?array $metadata = null
    ): AiTrainingData {
        $data = clone $this->trainingData;
        $data->setData([
            AiTrainingData::fields_MODEL_ID => $modelId,
            AiTrainingData::fields_DATA_TYPE => $dataType,
            AiTrainingData::fields_DATA_CONTENT => $dataContent,
            AiTrainingData::fields_DATA_URL => $dataUrl,
            AiTrainingData::fields_METADATA => $metadata ? json_encode($metadata) : null,
            AiTrainingData::fields_STATUS => AiTrainingData::STATUS_PENDING,
        ]);
        $data->save();

        return $data;
    }

    /**
     * Update training data status
     *
     * @param int $dataId
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $dataId, string $status): bool
    {
        $data = clone $this->trainingData;
        $data->load($dataId);
        
        if (!$data->getId()) {
            return false;
        }

        $data->setData(AiTrainingData::fields_STATUS, $status);
        
        // Set processed_at timestamp if status is processed
        if ($status === AiTrainingData::STATUS_PROCESSED) {
            $data->setData(AiTrainingData::fields_PROCESSED_AT, date('Y-m-d H:i:s'));
        }
        
        return $data->save();
    }

    /**
     * Get training data by model ID
     *
     * @param int $modelId
     * @param string|null $dataType
     * @return array
     */
    public function getByModelId(int $modelId, ?string $dataType = null): array
    {
        $results = [];
        $collection = clone $this->trainingData;
        $collection->where(AiTrainingData::fields_MODEL_ID, $modelId);
        
        if ($dataType) {
            $collection->where(AiTrainingData::fields_DATA_TYPE, $dataType);
        }
        
        $items = $collection->order(AiTrainingData::fields_CREATED_AT, 'DESC')
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
     * Get pending training data
     *
     * @param int $limit
     * @return array
     */
    public function getPendingData(int $limit = 100): array
    {
        $results = [];
        $collection = clone $this->trainingData;
        $items = $collection->where(AiTrainingData::fields_STATUS, AiTrainingData::STATUS_PENDING)
            ->order(AiTrainingData::fields_CREATED_AT, 'ASC')
            ->limit($limit)
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
     * Process training data batch
     *
     * @param array $dataIds
     * @return array ['success' => int, 'failed' => int, 'errors' => array]
     */
    public function processBatch(array $dataIds): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($dataIds as $dataId) {
            try {
                // Simulate processing (replace with actual training logic)
                $this->processTrainingData($dataId);
                $this->updateStatus($dataId, AiTrainingData::STATUS_PROCESSED);
                $success++;
            } catch (\Exception $e) {
                $this->updateStatus($dataId, AiTrainingData::STATUS_FAILED);
                $failed++;
                $errors[$dataId] = $e->getMessage();
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Process individual training data
     *
     * @param int $dataId
     * @return void
     */
    private function processTrainingData(int $dataId): void
    {
        // Placeholder for actual training data processing logic
        // This would typically involve:
        // 1. Validating data format
        // 2. Transforming data for training
        // 3. Uploading to training platform
        // 4. Initiating fine-tuning job
    }

    /**
     * Delete training data
     *
     * @param int $dataId
     * @return bool
     */
    public function deleteTrainingData(int $dataId): bool
    {
        $data = clone $this->trainingData;
        $data->load($dataId);
        
        if (!$data->getId()) {
            return false;
        }

        return $data->delete();
    }

    /**
     * Get training data statistics
     *
     * @param int $modelId
     * @return array
     */
    public function getStatistics(int $modelId): array
    {
        $allData = $this->getByModelId($modelId);
        
        $stats = [
            'total' => count($allData),
            'by_status' => [],
            'by_type' => [],
        ];

        foreach ($allData as $data) {
            $status = $data->getData(AiTrainingData::fields_STATUS);
            $type = $data->getData(AiTrainingData::fields_DATA_TYPE);
            
            $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        }

        return $stats;
    }
}
