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
}
