<?php

declare(strict_types=1);

namespace Weline\Framework\Router\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\UrlGuard\BoundedUrlGuard;
use Weline\Framework\Router\UrlGuard\GuardDecision;
use Weline\Framework\Router\UrlGuard\UrlGuardEvaluator;
use Weline\Framework\Router\UrlGuard\UrlGuardInterface;
use Weline\Framework\Router\UrlGuard\UrlGuardRegistry;

class UrlGuardEvaluatorTest extends TestCase
{
    public function testBoundedGuardRejectsValueOverMax(): void
    {
        $guard = new BoundedUrlGuard('product_id_max', [
            'pattern' => '#^/product/(?<id>\d+)#',
            'param_source' => 'pattern',
            'param_name' => 'id',
            'max' => 1000,
            'reject_status' => 410,
            'reject_reason' => 'product_id_overflow',
        ]);

        $registry = new UrlGuardRegistry([$guard]);
        $evaluator = new UrlGuardEvaluator($registry);

        $decision = $evaluator->evaluate('/product/99999', []);
        $this->assertTrue($decision->isReject());
        $this->assertSame('product_id_max', $decision->guardName);
        $this->assertSame(410, $decision->rejectStatusCode);
        $this->assertSame('product_id_overflow', $decision->reason);
        $this->assertSame(99999, $decision->details['value'] ?? null);
        $this->assertSame(1000, $decision->details['max'] ?? null);
    }

    public function testBoundedGuardPassesWhenWithinRange(): void
    {
        $guard = new BoundedUrlGuard('product_id_max', [
            'pattern' => '#^/product/(?<id>\d+)#',
            'param_source' => 'pattern',
            'param_name' => 'id',
            'min' => 1,
            'max' => 1000,
        ]);

        $registry = new UrlGuardRegistry([$guard]);
        $evaluator = new UrlGuardEvaluator($registry);

        $decision = $evaluator->evaluate('/product/500', []);
        $this->assertTrue($decision->isPass());
    }

    public function testGuardSkipsRequestWhenPatternDoesNotMatch(): void
    {
        $guard = new BoundedUrlGuard('product_id_max', [
            'pattern' => '#^/product/(?<id>\d+)#',
            'param_source' => 'pattern',
            'param_name' => 'id',
            'max' => 1000,
        ]);

        $registry = new UrlGuardRegistry([$guard]);
        $evaluator = new UrlGuardEvaluator($registry);

        $decision = $evaluator->evaluate('/category/all', []);
        $this->assertTrue($decision->isPass(), 'Non-matching URL should pass without rejection');
    }

    public function testWhitelistAllowEnforcesValues(): void
    {
        $guard = new BoundedUrlGuard('sort_whitelist', [
            'pattern' => '#^/category/#',
            'param_source' => 'get',
            'param_name' => 'sort',
            'allow' => ['price_asc', 'price_desc'],
        ]);

        $registry = new UrlGuardRegistry([$guard]);
        $evaluator = new UrlGuardEvaluator($registry);

        $passing = $evaluator->evaluate('/category/electronics', ['sort' => 'price_asc']);
        $this->assertTrue($passing->isPass());

        $rejecting = $evaluator->evaluate('/category/electronics', ['sort' => 'random_value']);
        $this->assertTrue($rejecting->isReject());
        $this->assertSame('sort_whitelist', $rejecting->guardName);
    }

    public function testRegistryLoadFromArrayCreatesBoundedGuards(): void
    {
        $registry = new UrlGuardRegistry();
        $registry->loadFromArray([
            ['name' => 'g1', 'config' => ['pattern' => '#/x#', 'param_name' => 'id', 'max' => 10]],
            ['name' => 'g2', 'config' => ['pattern' => '#/y#', 'param_name' => 'id', 'max' => 5]],
        ]);
        $this->assertTrue($registry->has('g1'));
        $this->assertTrue($registry->has('g2'));
        $this->assertCount(2, $registry->all());
    }

    public function testFirstRejectingGuardWinsAndStopsEvaluation(): void
    {
        $orderTrace = [];

        $guardA = new UrlGuardTraceGuard('g_a', GuardDecision::reject('g_a', 'first_reject', 410), $orderTrace);
        $guardB = new UrlGuardTraceGuard('g_b', GuardDecision::reject('g_b', 'second_reject', 410), $orderTrace);

        $registry = new UrlGuardRegistry([$guardA, $guardB]);
        $evaluator = new UrlGuardEvaluator($registry);

        $decision = $evaluator->evaluate('/anywhere', []);
        $this->assertSame('g_a', $decision->guardName);
        $this->assertSame(['g_a'], $orderTrace, 'Evaluation should stop at first rejecting guard');
    }
}

final class UrlGuardTraceGuard implements UrlGuardInterface
{
    /**
     * @param array<int, string> $orderTrace by-ref to record execution order
     */
    public function __construct(
        private string $name,
        private GuardDecision $decision,
        private array &$orderTrace
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function matches(string $uri, array $params, array $headers = []): bool
    {
        return true;
    }

    public function evaluate(string $uri, array $params, array $headers = []): GuardDecision
    {
        $this->orderTrace[] = $this->name;
        return $this->decision;
    }
}
