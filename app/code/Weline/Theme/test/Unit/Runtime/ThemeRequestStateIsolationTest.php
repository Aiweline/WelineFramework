<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Runtime;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Context;
use Weline\Theme\Api\Runtime\RequestResetter;
use Weline\Theme\Helper\LayoutDependencyTracker;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Taglib\Slot;

final class ThemeRequestStateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        PreviewTokenService::resetRequestState();
        Slot::clearRegisteredSlots();
    }

    protected function tearDown(): void
    {
        PreviewTokenService::resetRequestState();
        Slot::clearRegisteredSlots();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearPreviewOrSlotState(): void
    {
        $observed = [];
        $serviceA = new PreviewTokenServiceFiberStub('token-a');
        $serviceB = new PreviewTokenServiceFiberStub('token-b');

        $fiberA = new Fiber(function () use (&$observed, $serviceA): void {
            Context::enter(new Context());
            try {
                $observed['a_first_token'] = $serviceA->getCurrentToken();
                self::registerSlot('slot-a', 'fiber-a.phtml');
                Fiber::suspend('a-ready');

                $state = self::previewState();
                $observed['a_before_reset'] = [
                    'token' => $state['preview_data']['token'] ?? null,
                    'validations' => $serviceA->validationCount,
                    'slots' => Slot::getRegisteredSlots(),
                ];
                (new RequestResetter())->resetRequest();
                $state = self::previewState();
                $observed['a_after_reset'] = [
                    'token' => $state['preview_data']['token'] ?? null,
                    'detected' => $state['detected'],
                    'validations' => $serviceA->validationCount,
                    'slots' => Slot::getRegisteredSlots(),
                ];
                Fiber::suspend('a-reset');
            } finally {
                (new RequestResetter())->resetRequest();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed, $serviceB): void {
            Context::enter(new Context());
            try {
                $observed['b_first_token'] = $serviceB->getCurrentToken();
                self::registerSlot('slot-b', 'fiber-b.phtml');
                Fiber::suspend('b-ready');

                $state = self::previewState();
                $observed['b_after_a_reset'] = [
                    'token' => $state['preview_data']['token'] ?? null,
                    'validations' => $serviceB->validationCount,
                    'slots' => Slot::getRegisteredSlots(),
                ];
                Fiber::suspend('b-verified');
            } finally {
                (new RequestResetter())->resetRequest();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame('token-a', $observed['a_first_token']);
        self::assertSame('token-b', $observed['b_first_token']);
        self::assertSame([
            'token' => 'token-a',
            'validations' => 1,
            'slots' => ['slot-a' => 'fiber-a.phtml:1'],
        ], $observed['a_before_reset']);
        self::assertSame([
            'token' => null,
            'detected' => false,
            'validations' => 1,
            'slots' => [],
        ], $observed['a_after_reset']);
        self::assertSame([
            'token' => 'token-b',
            'validations' => 1,
            'slots' => ['slot-b' => 'fiber-b.phtml:1'],
        ], $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    public function testRequestResetPreservesProcessLayoutDependencyCache(): void
    {
        $property = new ReflectionProperty(LayoutDependencyTracker::class, 'dependencyCache');
        $property->setAccessible(true);
        $original = $property->getValue();
        $sentinel = [
            'layout-sentinel' => [
                'dependencies' => ['/tmp/partial.phtml'],
                'lastCheck' => 123,
            ],
        ];

        try {
            $property->setValue(null, $sentinel);
            (new RequestResetter())->resetRequest();
            self::assertSame($sentinel, $property->getValue());
        } finally {
            $property->setValue(null, $original);
        }
    }

    private static function registerSlot(string $id, string $file): void
    {
        $callback = Slot::callback();
        $callback('tag', ['file' => $file, 'line' => 1], ['', '', ''], ['id' => $id]);
    }

    /** @return array{detected: bool, preview_data: array|null} */
    private static function previewState(): array
    {
        $method = new ReflectionMethod(PreviewTokenService::class, 'requestState');
        $method->setAccessible(true);
        return $method->invoke(null);
    }
}

final class PreviewTokenServiceFiberStub extends PreviewTokenService
{
    public int $validationCount = 0;

    public function __construct(private readonly string $token)
    {
    }

    public function getTokenFromRequest(bool $allowCookie = true): ?string
    {
        return $this->token;
    }

    public function validateToken(string $token): ?array
    {
        $this->validationCount++;
        return [
            'token' => $token,
            'theme_id' => $token === 'token-a' ? 1 : 2,
        ];
    }
}
