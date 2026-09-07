<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Service\CaptchaConfig;
use Weline\SystemConfig\Api\ConfigReader;

final class CaptchaConfigConstructorContractTest extends TestCase
{
    public function testConfigReaderDependencyRemainsResolvableByObjectManager(): void
    {
        $constructor = new \ReflectionMethod(CaptchaConfig::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertCount(1, $parameters);
        $type = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(ConfigReader::class, $type->getName());
    }
}
