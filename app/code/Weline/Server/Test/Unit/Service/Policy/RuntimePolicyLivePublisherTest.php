<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Policy;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Policy\RuntimePolicyLivePublisher;

final class RuntimePolicyLivePublisherTest extends TestCase
{
    public function testPublisherExposesPublishAfterSecurityRulesChange(): void
    {
        $ref = new \ReflectionClass(RuntimePolicyLivePublisher::class);
        self::assertTrue($ref->hasMethod('publishAfterSecurityRulesChange'));
        $method = $ref->getMethod('publishAfterSecurityRulesChange');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());
    }
}
