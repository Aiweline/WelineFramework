<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Extra\ExtraTypeProviderInterface;
use Weline\Framework\Controller\Extra\ExtraTypeRegistry;
use Weline\Framework\Extends\Module\Weline_Framework\Extra\Type\FpcExtraType;

final class ExtraTypeRegistryContractTest extends TestCase
{
    public function testFpcExtraTypeNormalizesDeclaration(): void
    {
        $type = new FpcExtraType();
        self::assertSame('fpc', $type->type());
        $normalized = $type->normalize([
            'type' => 'fpc',
            'enabled' => 'true',
            'ttl' => '600',
            'namespaces' => ['website/default/catalog'],
            'public_path_patterns' => ['/catalog/product/*'],
        ]);
        self::assertTrue($normalized['enabled']);
        self::assertSame(600, $normalized['ttl']);
        self::assertSame(['website/default/catalog'], $normalized['namespaces']);
        self::assertSame(['/catalog/product/*'], $normalized['public_path_patterns']);
    }

    public function testExtraTypeProviderInterfacePrefix(): void
    {
        self::assertStringContainsString('extra/type/', ExtraTypeProviderInterface::EXTENDS_RELATIVE_PREFIX);
    }

    public function testRegistryClassExists(): void
    {
        self::assertTrue(class_exists(ExtraTypeRegistry::class));
    }
}
