<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Weline\DataTable\Service\WritePlanService;
use Weline\Framework\Test\TestCore;

class WritePlanServiceTest extends TestCore
{
    public function testPayloadDigestIsOrderIndependentButValueSensitive(): void
    {
        $service = new WritePlanService();

        $this->assertSame(
            $service->payloadDigest(['data' => ['name' => 'Ada', 'age' => 32], 'operation' => 'create']),
            $service->payloadDigest(['operation' => 'create', 'data' => ['age' => 32, 'name' => 'Ada']])
        );
        $this->assertNotSame(
            $service->payloadDigest(['data' => ['name' => 'Ada']]),
            $service->payloadDigest(['data' => ['name' => 'Grace']])
        );
    }

    public function testIssuedPlanCanBeConsumedOnlyOnceWithExactPayload(): void
    {
        $service = new WritePlanService();
        $payload = ['operation' => 'create', 'data' => ['name' => 'Ada']];
        $plan = ['schema_version' => 'datatable-write-plan.v1', 'targets' => [['resource' => 'demo.users']]];
        $issued = $service->issue($payload, $plan);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued['plan_token']);
        $this->assertSame($plan, $service->consume($issued['plan_token'], $payload));

        $this->expectException(\Throwable::class);
        $service->consume($issued['plan_token'], $payload);
    }

    public function testTamperedPayloadConsumesAndInvalidatesToken(): void
    {
        $service = new WritePlanService();
        $payload = ['operation' => 'update', 'id' => 7, 'data' => ['name' => 'Ada']];
        $issued = $service->issue($payload, ['schema_version' => 'datatable-write-plan.v1']);

        $mismatchRejected = false;
        try {
            $service->consume($issued['plan_token'], array_replace($payload, ['id' => 8]));
        } catch (\Throwable) {
            $mismatchRejected = true;
        }
        $this->assertTrue($mismatchRejected, 'Tampered payload must fail.');

        $this->expectException(\Throwable::class);
        $service->consume($issued['plan_token'], $payload);
    }
}
