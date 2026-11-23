<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\HealthPolicy;
use Weline\Seo\Model\SitemapAction;
use Weline\Seo\Service\Health\HealthPolicyEngine;

final class HealthPolicyEngineTest extends TestCase
{
    private HealthPolicyEngine $engine;

    protected function setUp(): void
    {
        $this->engine = (new \ReflectionClass(HealthPolicyEngine::class))->newInstanceWithoutConstructor();
    }

    public function testNotifyModeDoesNotAct(): void
    {
        $decision = $this->engine->decideIssue(
            'not_found',
            $this->policy(HealthPolicy::AUTO_NOTIFY, HealthPolicy::ACTION_REMOVE, 2),
            10,
            false
        );

        self::assertFalse($decision['act']);
    }

    public function testBelowGraceDoesNotAct(): void
    {
        $decision = $this->engine->decideIssue(
            'not_found',
            $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REMOVE, 2),
            1,
            false
        );

        self::assertFalse($decision['act']);
        self::assertStringContainsString('grace', $decision['reason']);
    }

    public function testAtGraceRemoves(): void
    {
        $decision = $this->engine->decideIssue(
            'not_found',
            $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REMOVE, 2),
            2,
            false
        );

        self::assertTrue($decision['act']);
        self::assertSame(SitemapAction::TYPE_REMOVE, $decision['action']);
    }

    public function testDryRunStillPlansAction(): void
    {
        $decision = $this->engine->decideIssue(
            'soft_404',
            $this->policy(HealthPolicy::AUTO_DRY_RUN, HealthPolicy::ACTION_REMOVE, 1),
            1,
            false
        );

        self::assertTrue($decision['act']);
        self::assertSame(SitemapAction::TYPE_REMOVE, $decision['action']);
    }

    public function testActiveActionBlocksDuplicate(): void
    {
        $decision = $this->engine->decideIssue(
            'not_found',
            $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REMOVE, 1),
            5,
            true
        );

        self::assertFalse($decision['act']);
        self::assertStringContainsString('already active', $decision['reason']);
    }

    public function testReplaceAction(): void
    {
        $decision = $this->engine->decideIssue(
            'redirect_ok',
            $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REPLACE, 1),
            1,
            false
        );

        self::assertTrue($decision['act']);
        self::assertSame(SitemapAction::TYPE_REPLACE, $decision['action']);
    }

    public function testRedirectActionMapsToReplaceWithoutWritingRules(): void
    {
        $decision = $this->engine->decideIssue(
            'redirect_ok',
            $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REDIRECT, 1),
            1,
            false
        );

        self::assertTrue($decision['act']);
        self::assertSame(SitemapAction::TYPE_REPLACE, $decision['action']);
    }

    public function testDisabledPolicyDoesNotAct(): void
    {
        $policy = $this->policy(HealthPolicy::AUTO_AUTO, HealthPolicy::ACTION_REMOVE, 1);
        $policy['is_enabled'] = false;

        self::assertFalse($this->engine->decideIssue('not_found', $policy, 9, false)['act']);
    }

    /** @return array<string, mixed> */
    private function policy(string $mode, string $action, int $grace): array
    {
        return [
            'auto_mode' => $mode,
            'action' => $action,
            'grace_runs' => $grace,
            'restore_enabled' => true,
            'restore_grace_runs' => 3,
            'is_enabled' => true,
        ];
    }
}
