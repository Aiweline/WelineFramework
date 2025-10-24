<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiContentSafety;
use Weline\Framework\Manager\ObjectManager;

/**
 * Content Safety Service
 * 
 * Manages content safety detection and filtering.
 * 
 * @package Weline_Ai
 */
class ContentSafetyService
{
    private AiContentSafety $contentSafety;

    public function __construct(AiContentSafety $contentSafety)
    {
        $this->contentSafety = $contentSafety;
    }

    /**
     * Check content safety
     *
     * @param string $content
     * @param string $contentType
     * @return array ['is_safe' => bool, 'safety_score' => float, 'risk_level' => string, 'details' => array]
     */
    public function checkContent(string $content, string $contentType): array
    {
        // Perform safety check (placeholder for actual implementation)
        $safetyScore = $this->calculateSafetyScore($content);
        $riskLevel = $this->determineRiskLevel($safetyScore);
        $detectionResult = $this->performDetection($content);

        // Save detection result
        $record = clone $this->contentSafety;
        $record->setData([
            AiContentSafety::fields_CONTENT_TYPE => $contentType,
            AiContentSafety::fields_CONTENT_TEXT => $content,
            AiContentSafety::fields_SAFETY_SCORE => $safetyScore,
            AiContentSafety::fields_RISK_LEVEL => $riskLevel,
            AiContentSafety::fields_DETECTION_RESULT => json_encode($detectionResult),
        ]);
        $record->save();

        return [
            'is_safe' => $riskLevel === AiContentSafety::RISK_LEVEL_LOW,
            'safety_score' => $safetyScore,
            'risk_level' => $riskLevel,
            'details' => $detectionResult,
            'record_id' => $record->getId(),
        ];
    }

    /**
     * Calculate safety score (0-1, higher is safer)
     *
     * @param string $content
     * @return float
     */
    private function calculateSafetyScore(string $content): float
    {
        // Placeholder implementation - integrate with actual safety API
        // Example: check for harmful keywords, offensive language, etc.
        $score = 1.0;
        
        // Simple keyword check (this should be replaced with actual AI-based detection)
        $dangerousKeywords = ['violent', 'harmful', 'illegal', 'offensive'];
        foreach ($dangerousKeywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $score -= 0.2;
            }
        }
        
        return max(0.0, min(1.0, $score));
    }

    /**
     * Determine risk level based on safety score
     *
     * @param float $safetyScore
     * @return string
     */
    private function determineRiskLevel(float $safetyScore): string
    {
        if ($safetyScore >= 0.8) {
            return AiContentSafety::RISK_LEVEL_LOW;
        } elseif ($safetyScore >= 0.5) {
            return AiContentSafety::RISK_LEVEL_MEDIUM;
        } else {
            return AiContentSafety::RISK_LEVEL_HIGH;
        }
    }

    /**
     * Perform detailed detection
     *
     * @param string $content
     * @return array
     */
    private function performDetection(string $content): array
    {
        // Placeholder for detailed detection logic
        return [
            'checked_categories' => ['violence', 'hate_speech', 'adult_content', 'spam'],
            'violations' => [],
            'confidence' => 0.95,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get safety history by content type
     *
     * @param string $contentType
     * @param int $limit
     * @return array
     */
    public function getHistoryByType(string $contentType, int $limit = 100): array
    {
        $results = [];
        $collection = clone $this->contentSafety;
        $items = $collection->where(AiContentSafety::fields_CONTENT_TYPE, $contentType)
            ->order(AiContentSafety::fields_CREATED_AT, 'DESC')
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
     * Get high-risk content records
     *
     * @param int $limit
     * @return array
     */
    public function getHighRiskContent(int $limit = 50): array
    {
        $results = [];
        $collection = clone $this->contentSafety;
        $items = $collection->where(AiContentSafety::fields_RISK_LEVEL, AiContentSafety::RISK_LEVEL_HIGH)
            ->order(AiContentSafety::fields_CREATED_AT, 'DESC')
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
}
