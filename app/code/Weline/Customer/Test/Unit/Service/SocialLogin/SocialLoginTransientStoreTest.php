<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginTransientStore;

final class SocialLoginTransientStoreTest extends TestCase
{
    public function testPutGetTakeAndExpiry(): void
    {
        $store = new SocialLoginTransientStore();
        $token = 'unit_' . bin2hex(random_bytes(8));
        $payload = ['provider' => 'google', 'created_at' => time()];

        $store->put('state', $token, $payload, 60);
        self::assertSame($payload, $store->get('state', $token));

        $taken = $store->take('state', $token);
        self::assertSame($payload, $taken);
        self::assertNull($store->get('state', $token));

        $expired = 'unit_exp_' . bin2hex(random_bytes(4));
        $store->put('state', $expired, $payload, 1);
        sleep(2);
        self::assertNull($store->get('state', $expired));
    }

    public function testOAuthServiceSourceUsesTransientStore(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Service/SocialLogin/SocialLoginOAuthService.php'
        );
        self::assertStringContainsString('SocialLoginTransientStore', $src);
        self::assertStringContainsString('transientStore->put', $src);
        self::assertStringContainsString('transientStore->take', $src);
    }
}
