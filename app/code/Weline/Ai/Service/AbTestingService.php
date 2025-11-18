<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiAbTest;
use Weline\Framework\Manager\ObjectManager;

/**
 * A/B Testing Service
 * 
 * Manages A/B testing experiments for comparing AI models.
 * 
 * @package Weline_Ai
 */
class AbTestingService
{
    private AiAbTest $abTest;

    public function __construct(AiAbTest $abTest)
    {
        $this->abTest = $abTest;
    }

    /**
     * Create a new A/B test
     *
     * @param string $testName
     * @param int $modelAId
     * @param int $modelBId
     * @param array|null $testCriteria
     * @return AiAbTest
     */
    public function createTest(
        string $testName,
        int $modelAId,
        int $modelBId,
        ?array $testCriteria = null
    ): AiAbTest {
        $test = clone $this->abTest;
        $test->setData([
            AiAbTest::fields_TEST_NAME => $testName,
            AiAbTest::fields_MODEL_A_ID => $modelAId,
            AiAbTest::fields_MODEL_B_ID => $modelBId,
            AiAbTest::fields_TEST_CRITERIA => $testCriteria ? json_encode($testCriteria) : null,
            AiAbTest::fields_STATUS => AiAbTest::STATUS_PENDING,
        ]);
        $test->save();

        return $test;
    }

    /**
     * Start an A/B test
     *
     * @param int $testId
     * @return bool
     */
    public function startTest(int $testId): bool
    {
        $test = clone $this->abTest;
        $test->load($testId);
        
        if (!$test->getId()) {
            return false;
        }

        $test->setData([
            AiAbTest::fields_STATUS => AiAbTest::STATUS_RUNNING,
            AiAbTest::fields_STARTED_AT => date('Y-m-d H:i:s'),
        ]);
        
        return $test->save();
    }

    /**
     * Complete an A/B test with results
     *
     * @param int $testId
     * @param array $testResult
     * @param string|null $winnerModel 'A', 'B', or 'TIE'
     * @return bool
     */
    public function completeTest(int $testId, array $testResult, ?string $winnerModel = null): bool
    {
        $test = clone $this->abTest;
        $test->load($testId);
        
        if (!$test->getId()) {
            return false;
        }

        // Determine winner if not provided
        if ($winnerModel === null) {
            $winnerModel = $this->determineWinner($testResult);
        }

        $test->setData([
            AiAbTest::fields_STATUS => AiAbTest::STATUS_COMPLETED,
            AiAbTest::fields_TEST_RESULT => json_encode($testResult),
            AiAbTest::fields_WINNER_MODEL => $winnerModel,
            AiAbTest::fields_COMPLETED_AT => date('Y-m-d H:i:s'),
        ]);
        
        return $test->save();
    }

    /**
     * Determine winner from test results
     *
     * @param array $testResult
     * @return string
     */
    private function determineWinner(array $testResult): string
    {
        // Simple comparison logic - can be enhanced based on specific criteria
        $scoreA = $testResult['model_a_score'] ?? 0;
        $scoreB = $testResult['model_b_score'] ?? 0;
        
        $threshold = 0.05; // 5% difference threshold for tie
        $diff = abs($scoreA - $scoreB) / max($scoreA, $scoreB, 1);
        
        if ($diff < $threshold) {
            return AiAbTest::WINNER_TIE;
        }
        
        return $scoreA > $scoreB ? AiAbTest::WINNER_MODEL_A : AiAbTest::WINNER_MODEL_B;
    }

    /**
     * Cancel an A/B test
     *
     * @param int $testId
     * @return bool
     */
    public function cancelTest(int $testId): bool
    {
        $test = clone $this->abTest;
        $test->load($testId);
        
        if (!$test->getId()) {
            return false;
        }

        $test->setData(AiAbTest::fields_STATUS, AiAbTest::STATUS_CANCELLED);
        return $test->save();
    }

    /**
     * Get tests by model ID
     *
     * @param int $modelId
     * @return array
     */
    public function getTestsByModelId(int $modelId): array
    {
        $results = [];
        $collection = clone $this->abTest;
        $items = $collection->whereRaw(
            "({$collection->getTable()}.{AiAbTest::fields_MODEL_A_ID} = ? OR {$collection->getTable()}.{AiAbTest::fields_MODEL_B_ID} = ?)",
            [$modelId, $modelId]
        )
            ->order(AiAbTest::fields_CREATED_AT, 'DESC')
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
     * Get running tests
     *
     * @return array
     */
    public function getRunningTests(): array
    {
        $results = [];
        $collection = clone $this->abTest;
        $items = $collection->where(AiAbTest::fields_STATUS, AiAbTest::STATUS_RUNNING)
            ->order(AiAbTest::fields_STARTED_AT, 'ASC')
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
     * Get completed tests with winner
     *
     * @param string|null $winnerModel
     * @return array
     */
    public function getCompletedTests(?string $winnerModel = null): array
    {
        $results = [];
        $collection = clone $this->abTest;
        $collection->where(AiAbTest::fields_STATUS, AiAbTest::STATUS_COMPLETED);
        
        if ($winnerModel) {
            $collection->where(AiAbTest::fields_WINNER_MODEL, $winnerModel);
        }
        
        $items = $collection->order(AiAbTest::fields_COMPLETED_AT, 'DESC')
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
     * Get test statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $all = $this->abTest->select()->fetch();
        
        $stats = [
            'total' => 0,
            'by_status' => [],
            'by_winner' => [],
        ];

        if ($all) {
            foreach ($all as $test) {
                $stats['total']++;
                $status = $test->getData(AiAbTest::fields_STATUS);
                $winner = $test->getData(AiAbTest::fields_WINNER_MODEL);
                
                $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
                
                if ($winner) {
                    $stats['by_winner'][$winner] = ($stats['by_winner'][$winner] ?? 0) + 1;
                }
            }
        }

        return $stats;
    }

    /**
     * Delete test
     *
     * @param int $testId
     * @return bool
     */
    public function deleteTest(int $testId): bool
    {
        $test = clone $this->abTest;
        $test->load($testId);
        
        if (!$test->getId()) {
            return false;
        }

        // Prevent deleting running tests
        if ($test->getData(AiAbTest::fields_STATUS) === AiAbTest::STATUS_RUNNING) {
            throw new \RuntimeException('Cannot delete running test. Cancel it first.');
        }

        return $test->delete();
    }
}
