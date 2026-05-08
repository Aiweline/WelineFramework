<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Acl\Acl;

class SupplierBackendAclMetadataTest extends TestCase
{
    /**
     * @dataProvider backendControllerClassProvider
     */
    public function testSupplierAdjacentBackendControllersDeclareClassAcl(string $className, string $module): void
    {
        self::assertTrue(class_exists($className), $className . ' should be autoloadable.');

        $reflection = new \ReflectionClass($className);
        $attributes = $reflection->getAttributes(Acl::class);

        self::assertNotEmpty($attributes, $className . ' must declare class-level ACL metadata.');

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $sourceId = (string) ($arguments[0] ?? '');
            $parentSource = (string) ($arguments[4] ?? '');

            self::assertNotSame('', $sourceId, $className . ' ACL source id must not be empty.');
            self::assertStringStartsWith('WeShop_' . $module . '::', $sourceId, $className . ' ACL source id should stay inside its module.');
            self::assertNotSame($sourceId, $parentSource, $className . ' ACL source id must not equal parent source.');
        }
    }

    public function testMethodAclMetadataUsesDistinctSources(): void
    {
        foreach ($this->backendControllerClassProvider() as [$className]) {
            $reflection = new \ReflectionClass($className);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Acl::class) as $attribute) {
                    $arguments = $attribute->getArguments();
                    $sourceId = (string) ($arguments[0] ?? '');
                    $parentSource = (string) ($arguments[4] ?? '');

                    self::assertNotSame('', $sourceId, $className . '::' . $method->getName() . ' ACL source id must not be empty.');
                    self::assertNotSame($sourceId, $parentSource, $className . '::' . $method->getName() . ' ACL source id must not equal parent source.');
                }
            }
        }
    }

    public static function backendControllerClassProvider(): iterable
    {
        foreach (['B2B', 'Product', 'Order', 'Inventory', 'Invoice', 'Payment', 'Affiliate'] as $module) {
            $directory = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
                . 'WeShop' . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR
                . 'Controller' . DIRECTORY_SEPARATOR . 'Backend';

            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getSize() === 0) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                if (!str_contains($contents, 'class ')) {
                    continue;
                }

                $relativePath = str_replace(
                    [BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR, '.php'],
                    ['', ''],
                    $file->getPathname()
                );
                $className = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

                yield $className => [$className, $module];
            }
        }
    }
}
