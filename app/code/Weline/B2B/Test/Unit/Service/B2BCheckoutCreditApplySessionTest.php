<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BCheckoutCreditApplySession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class B2BCheckoutCreditApplySessionTest extends TestCase
{
    public function testSaveAndGetSurviveLikeCouponSession(): void
    {
        $session = $this->newSession();
        $saved = $session->save([
            'cart_type' => 'tob',
            'apply_minor' => 2000,
            'currency' => 'USD',
            'enabled' => true,
        ]);
        self::assertTrue($saved['success']);
        self::assertTrue($saved['ok']);
        self::assertTrue($saved['saved_apply']['enabled']);
        self::assertSame(2000, $saved['saved_apply']['apply_minor']);
        self::assertSame('USD', $saved['saved_apply']['currency']);

        $got = $session->get('tob');
        self::assertNotNull($got);
        self::assertTrue($got['enabled']);
        self::assertSame(2000, $got['apply_minor']);
        self::assertSame('USD', $got['currency']);
    }

    public function testClearRemovesSavedApply(): void
    {
        $session = $this->newSession();
        $session->save([
            'cart_type' => 'tob',
            'apply_minor' => 1500,
            'currency' => 'CNY',
        ]);
        $cleared = $session->save([
            'cart_type' => 'tob',
            'apply_minor' => 0,
            'enabled' => false,
            'currency' => 'CNY',
        ]);
        self::assertTrue($cleared['success']);
        self::assertFalse($cleared['saved_apply']['enabled']);
        self::assertSame(0, $cleared['saved_apply']['apply_minor']);
        self::assertNull($session->get('tob'));
    }

    public function testTocCartTypeRejected(): void
    {
        $session = $this->newSession();
        $result = $session->save([
            'cart_type' => 'toc',
            'apply_minor' => 1000,
            'currency' => 'USD',
        ]);
        self::assertFalse($result['success']);
        self::assertFalse($result['ok']);
        self::assertSame('toc', $result['saved_apply']['cart_type']);
    }

    private function newSession(): B2BCheckoutCreditApplySession
    {
        /** @var array<string, mixed> $bag */
        $bag = [];
        $auth = $this->createMock(AuthenticatedSessionInterface::class);
        $auth->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $auth->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = $value;
        });
        $auth->method('isLoggedIn')->willReturn(false);
        $auth->method('getUserId')->willReturn(null);

        $factory = $this->createStub(SessionFactory::class);
        $factory->method('createFrontendSession')->willReturn($auth);

        return new B2BCheckoutCreditApplySession($factory);
    }
}
