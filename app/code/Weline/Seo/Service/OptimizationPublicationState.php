<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;

/** Normalizes queue receipts so observation cannot start before publication. */
final class OptimizationPublicationState
{
    public function normalize(string $status): string
    {
        return match (\strtolower(\trim($status))) {
            'published' => 'published',
            'publishing' => 'publishing',
            'publish_pending' => 'publish_pending',
            'publish_failed' => 'publish_failed',
            default => 'publish_pending',
        };
    }

    public function initialExperimentStatus(string $mode, string $publishStatus): string
    {
        if ($mode !== SeoOptimizationPolicy::MODE_AUTO_PUBLISH || $this->normalize($publishStatus) === 'published') {
            return SeoOptimizationExperiment::STATUS_EVALUATING;
        }

        return SeoOptimizationExperiment::STATUS_PUBLISH_PENDING;
    }

    public function runStatus(string $publishStatus): string
    {
        $publishStatus = $this->normalize($publishStatus);

        return $publishStatus === 'published'
            ? SeoOptimizationExperiment::STATUS_EVALUATING
            : $publishStatus;
    }

    public function isPending(string $experimentStatus): bool
    {
        return $this->pendingAction($experimentStatus) !== null;
    }

    public function pendingAction(string $experimentStatus): ?string
    {
        return match ($experimentStatus) {
            SeoOptimizationExperiment::STATUS_PUBLISH_PENDING => 'candidate',
            SeoOptimizationExperiment::STATUS_FINALIZE_PENDING => 'finalize',
            SeoOptimizationExperiment::STATUS_ROLLBACK_PENDING => 'rollback',
            default => null,
        };
    }

    public function pendingStatusForAction(string $action): string
    {
        return match ($action) {
            'candidate' => SeoOptimizationExperiment::STATUS_PUBLISH_PENDING,
            'finalize' => SeoOptimizationExperiment::STATUS_FINALIZE_PENDING,
            'rollback' => SeoOptimizationExperiment::STATUS_ROLLBACK_PENDING,
            default => throw new \InvalidArgumentException('Unsupported publication action.'),
        };
    }

    public function isPublished(string $publishStatus): bool
    {
        return $this->normalize($publishStatus) === 'published';
    }

    public function isFailure(string $publishStatus): bool
    {
        return $this->normalize($publishStatus) === 'publish_failed';
    }
}
