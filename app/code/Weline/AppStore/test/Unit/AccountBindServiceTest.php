<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\AppStore\Service\AccountBindService;
use Weline\Framework\App\Exception;

class AccountBindServiceTest extends TestCase
{
    public function testDecryptTokenThrowsExceptionWhenPayloadInvalid(): void
    {
        $service = (new ReflectionClass(AccountBindService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountBindService::class, 'encryptionKey');
        $property->setAccessible(true);
        $property->setValue($service, 'unit-test-key');

        $decryptMethod = new ReflectionMethod(AccountBindService::class, 'decryptToken');
        $decryptMethod->setAccessible(true);

        $this->expectException(Exception::class);
        $decryptMethod->invoke($service, 'not-base64');
    }

    public function testDecryptTokenReturnsOriginalValueAfterEncrypt(): void
    {
        $service = (new ReflectionClass(AccountBindService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountBindService::class, 'encryptionKey');
        $property->setAccessible(true);
        $property->setValue($service, 'unit-test-key');

        $encryptMethod = new ReflectionMethod(AccountBindService::class, 'encryptToken');
        $encryptMethod->setAccessible(true);
        $decryptMethod = new ReflectionMethod(AccountBindService::class, 'decryptToken');
        $decryptMethod->setAccessible(true);

        $rawToken = 'token-123';
        $encrypted = $encryptMethod->invoke($service, $rawToken);
        $decrypted = $decryptMethod->invoke($service, $encrypted);

        $this->assertSame($rawToken, $decrypted);
    }

    public function testPlatformApiBaseUrlRemovesCurrencyLocaleSuffix(): void
    {
        $method = new ReflectionMethod(AccountBindService::class, 'normalizePlatformApiBaseUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'http://127.0.0.1:9502',
            $method->invoke(null, 'http://127.0.0.1:9502/CNY/zh_Hans_CN')
        );
        $this->assertSame(
            'https://apps.example.test',
            $method->invoke(null, 'https://apps.example.test/usd/en_US/')
        );
        $this->assertSame(
            'https://apps.example.test/store',
            $method->invoke(null, 'https://apps.example.test/store')
        );
    }

    public function testGetPlatformApiUrlAppendsPathToNormalizedBase(): void
    {
        $service = (new ReflectionClass(AccountBindService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountBindService::class, 'platformApiUrl');
        $property->setAccessible(true);
        $property->setValue($service, 'http://127.0.0.1:9502');

        $this->assertSame(
            'http://127.0.0.1:9502/api/v1/platform/module/check-update',
            $service->getPlatformApiUrl('/api/v1/platform/module/check-update')
        );
    }
}
