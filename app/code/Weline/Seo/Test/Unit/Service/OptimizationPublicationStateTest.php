<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Service\OptimizationPublicationState;

final class OptimizationPublicationStateTest extends TestCase
{
    public function testPendingCandidateDoesNotBecomeEvaluatingUntilPublished(): void
    {
        $state = new OptimizationPublicationState();

        self::assertSame(
            SeoOptimizationExperiment::STATUS_PUBLISH_PENDING,
            $state->initialExperimentStatus(SeoOptimizationPolicy::MODE_AUTO_PUBLISH, 'publish_pending')
        );
        self::assertSame('candidate', $state->pendingAction(SeoOptimizationExperiment::STATUS_PUBLISH_PENDING));
        self::assertTrue($state->isPending(SeoOptimizationExperiment::STATUS_PUBLISH_PENDING));
        self::assertFalse($state->isPublished('publishing'));

        self::assertSame(
            SeoOptimizationExperiment::STATUS_EVALUATING,
            $state->initialExperimentStatus(SeoOptimizationPolicy::MODE_AUTO_PUBLISH, 'published')
        );
        self::assertFalse($state->isPending(SeoOptimizationExperiment::STATUS_EVALUATING));
        self::assertSame(
            SeoOptimizationExperiment::STATUS_EVALUATING,
            $state->runStatus('published')
        );
    }
}
