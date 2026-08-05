<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\OptimizationEvaluationDecision;

final class OptimizationEvaluationDecisionTest extends TestCase
{
    public function testEligibleUpliftBelowPolicyThresholdRollsBackImmediately(): void
    {
        self::assertTrue(class_exists(OptimizationEvaluationDecision::class));
        $decision = new OptimizationEvaluationDecision();

        self::assertTrue($decision->shouldRollback([
            'sample_ready' => true,
            'guardrail_breached' => false,
            'primary_worsened' => false,
            'primary_relative_uplift' => 0.049,
            'required_relative_uplift' => 0.05,
        ], false));
        self::assertFalse($decision->shouldRollback([
            'sample_ready' => true,
            'guardrail_breached' => false,
            'primary_worsened' => false,
            'primary_relative_uplift' => 0.05,
            'required_relative_uplift' => 0.05,
        ], false));
    }
}
