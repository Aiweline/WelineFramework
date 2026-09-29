<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\SolidifiedControllerTemplateResolver;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;

final class SolidifiedControllerTemplateContractTest extends TestCase
{
    public function testSourceSelectionDoesNotExecutePhpAndUsesExactOptionAndRevision(): void
    {
        self::assertTrue(method_exists(SolidifiedControllerTemplateResolver::class, 'resolveForIdentity'), 'Selection must use the complete version and resource identity.');
        $root = sys_get_temp_dir() . '/weline-resolve-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
        $paths = new ThemeLayoutEntityPaths($root);
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        $resolver = new SolidifiedControllerTemplateResolver($paths, \Weline\Framework\Manager\ObjectManager::getInstance(ThemeScopeVersionService::class), $this->createMock(ScopeHierarchyInterface::class));
        $path = $paths->pageLayoutPhtml($identity, 'account/login', 'compact', 'customer', 12);
        try {
            mkdir(dirname($path), 0770, true);
            $metadata = ['origin' => __FILE__, 'identity' => $identity->toArray(), 'layout_type'=>'account/login','layout_option'=>'compact','target_type'=>'customer','target_id'=>12];
            file_put_contents($path, '<?php /* weline-source:' . base64_encode(json_encode($metadata)) . ' */ ?><?php throw new \\RuntimeException("must only select"); ?>');
            self::assertSame($path, $resolver->resolveForIdentity($identity, 'account/login', 'compact', 'customer', 12));
            self::assertNull($resolver->resolveForIdentity($identity, 'account/login', 'default', 'customer', 12));
            self::assertNull($resolver->resolveForIdentity($identity->withVersion(10, 'formal', 3), 'account/login', 'compact', 'customer', 12));
        } finally {
            if (is_dir($root)) { $paths->purgeAllEntities(); @rmdir(dirname($root)); }
        }
    }
}
