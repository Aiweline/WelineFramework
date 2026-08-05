<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\Runtime\RequestResetter;
use Weline\Ai\Middleware\TenantContext;
use Weline\Ai\Middleware\TenantIsolation;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResetException;

final class RequestResetterFailureAggregationTest extends TestCase
{
    private ?object $originalTenantContext = null;

    private ?object $originalTenantIsolation = null;

    protected function setUp(): void
    {
        Context::leave();
        $this->originalTenantContext = ObjectManager::_getInstance(TenantContext::class);
        $this->originalTenantIsolation = ObjectManager::_getInstance(TenantIsolation::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(TenantContext::class);
        ObjectManager::removeInstance(TenantIsolation::class);
        if ($this->originalTenantContext !== null) {
            ObjectManager::setInstance(TenantContext::class, $this->originalTenantContext);
        }
        if ($this->originalTenantIsolation !== null) {
            ObjectManager::setInstance(TenantIsolation::class, $this->originalTenantIsolation);
        }
        Context::leave();
        parent::tearDown();
    }

    public function testStateFailureStillRemovesBothRequestScopedInstances(): void
    {
        $tenantContext = new \stdClass();
        $tenantIsolation = new \stdClass();
        ObjectManager::setInstance(TenantContext::class, $tenantContext);
        ObjectManager::setInstance(TenantIsolation::class, $tenantIsolation);

        Context::enter(new AiRequestResetFaultContext(
            [
                'runtime' => [
                    'request_context' => [
                        'storage' => [
                            TenantContext::REQUEST_CONTEXT_KEY => ['tenant_code' => 'tenant-a'],
                        ],
                    ],
                ],
            ],
            TenantContext::REQUEST_CONTEXT_KEY,
        ));

        try {
            (new RequestResetter())->resetRequest();
            self::fail('Expected the tenant context reset failure to be aggregated.');
        } catch (RequestResetException $exception) {
            self::assertSame('ai_request_resetter', $exception->boundary());
            self::assertSame(['tenant_context_state'], $exception->stages());
        }

        self::assertNull(ObjectManager::_getInstance(TenantContext::class));
        self::assertNull(ObjectManager::_getInstance(TenantIsolation::class));
        self::assertSame('tenant-a', RequestContext::get(TenantContext::REQUEST_CONTEXT_KEY)['tenant_code'] ?? null);
    }
}

final class AiRequestResetFaultContext extends Context
{
    private bool $failureRaised = false;

    public function __construct(array $data, private readonly string $failWhenRemovedKey)
    {
        parent::__construct($data);
    }

    public function set(string $path, mixed $value): void
    {
        if (
            !$this->failureRaised
            && $path === 'runtime.request_context.storage'
            && is_array($value)
            && !array_key_exists($this->failWhenRemovedKey, $value)
        ) {
            $this->failureRaised = true;
            throw new \RuntimeException('ai-request-state-reset-failure');
        }

        parent::set($path, $value);
    }
}
