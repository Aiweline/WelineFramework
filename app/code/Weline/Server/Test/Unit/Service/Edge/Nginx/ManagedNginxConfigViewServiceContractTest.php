<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Server\Service\Edge\Nginx\FpcPierceNginxMapWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigViewService;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxConfigViewServiceContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls-nginx-view-'
            . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        FpcPierceStamp::clearMemo();
    }

    public function testSnapshotIsReadOnlyAndMasksPierceSecret(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18092,
            'listen_https' => 18492,
        ]);
        $paths->ensureRuntimeDirectories();
        $conf = $paths->confFile();
        self::assertNotFalse(\file_put_contents($conf, "worker_processes 1;\nevents {}\n"));

        FpcPierceStamp::clearMemo();
        $secret = \bin2hex(\random_bytes(16));
        // Persist via map writer body path without touching global secret file:
        $mapPath = $paths->confDir() . \DIRECTORY_SEPARATOR . FpcPierceNginxMapWriter::MAP_BASENAME;
        $mapBody = "map \$arg_w_fpc_pierce \$wls_fpc_pierce_arg_ok {\n    default 0;\n    \"{$secret}\" 1;\n}\n";
        self::assertNotFalse(\file_put_contents($mapPath, $mapBody));

        $view = new ManagedNginxConfigViewService($paths);
        // Force mask path by memoizing the same secret used in the map.
        $ref = new \ReflectionClass(FpcPierceStamp::class);
        $memo = $ref->getProperty('memoSecret');
        $memo->setAccessible(true);
        $memo->setValue(null, $secret);

        $snap = $view->snapshot();
        self::assertSame($paths->confDir(), $snap['conf_dir']);
        self::assertNotEmpty($snap['files']);

        $byId = [];
        foreach ($snap['files'] as $file) {
            $byId[$file['id']] = $file;
        }
        self::assertArrayHasKey('nginx.conf', $byId);
        self::assertTrue($byId['nginx.conf']['exists']);
        self::assertStringContainsString('worker_processes 1;', $byId['nginx.conf']['content']);

        self::assertArrayHasKey('fpc-pierce.map.conf', $byId);
        $pierce = $byId['fpc-pierce.map.conf'];
        self::assertTrue($pierce['exists']);
        self::assertTrue($pierce['redacted']);
        self::assertStringNotContainsString($secret, $pierce['content']);
        self::assertStringContainsString(\substr($secret, 0, 6), $pierce['content']);

        // Source files must remain unmasked on disk.
        self::assertStringContainsString($secret, (string)\file_get_contents($mapPath));
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
