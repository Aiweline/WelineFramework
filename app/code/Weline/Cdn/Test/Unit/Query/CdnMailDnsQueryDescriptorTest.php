<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Cdn\Extends\Module\Weline_Framework\Query\CdnQueryProvider;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CdnMailDnsQueryDescriptorTest extends TestCase
{
    public function testMailDnsCommandIsBackendWriteWithCdnAcl(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        self::assertArrayHasKey('reconcileMailDns', $operations);
        self::assertFalse($operations['reconcileMailDns']['frontend']);
        self::assertTrue($operations['reconcileMailDns']['backend']);
        self::assertSame('backend', $operations['reconcileMailDns']['auth']);
        self::assertSame('write', $operations['reconcileMailDns']['mode']);
        self::assertSame(
            ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_manager'],
            $operations['reconcileMailDns']['backend_acl'],
        );
    }
}
