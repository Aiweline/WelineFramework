<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Service\PhpExtensionIniEnabler;

final class PhpExtensionIniEnablerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/weline-ini-en-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = scandir($this->root);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $this->root . '/' . $item;
                if (is_dir($path)) {
                    foreach (scandir($path) ?: [] as $child) {
                        if ($child === '.' || $child === '..') {
                            continue;
                        }
                        unlink($path . '/' . $child);
                    }
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($this->root);
    }

    public function testUncommentsExtensionAndSyncsSiblingIni(): void
    {
        $cli = $this->root . '/php-cli.ini';
        $fpm = $this->root . '/php.ini';
        file_put_contents($cli, ";extension=brotli\n");
        file_put_contents($fpm, "; keep\n");

        $result = (new PhpExtensionIniEnabler())->enable(
            'brotli',
            static fn (): bool => true,
            [],
            ['ini_files' => [$cli, $fpm], 'scan_dir' => null]
        );

        self::assertTrue($result['success']);
        self::assertMatchesRegularExpression('/^\s*extension\s*=\s*brotli\s*$/m', (string) file_get_contents($cli));
        self::assertStringContainsString('extension=brotli', (string) file_get_contents($fpm));
        self::assertFileDoesNotExist($this->root . '/conf.d');
    }

    public function testInactiveScanDirDoesNotCreateDropIn(): void
    {
        $ini = $this->root . '/php.ini';
        file_put_contents($ini, "extension=event\n");
        $guess = $this->root . '/conf.d';
        mkdir($guess, 0777, true);

        $probed = false;
        $result = (new PhpExtensionIniEnabler())->enable(
            'event',
            static function () use (&$probed): bool {
                $probed = true;

                return false;
            },
            [],
            ['ini_files' => [$ini], 'scan_dir' => null]
        );

        self::assertTrue($probed);
        self::assertFalse($result['success']);
        self::assertFileDoesNotExist($guess . '/weline-event.ini');
        self::assertStringContainsString('新进程仍未加载', $result['message']);
    }

    public function testActiveScanDirWritesDropInOnlyWhenProvided(): void
    {
        $scan = $this->root . '/scan';
        mkdir($scan, 0777, true);

        $result = (new PhpExtensionIniEnabler())->enable(
            'ffi',
            static fn (): bool => true,
            ['ffi.enable=true'],
            ['ini_files' => [], 'scan_dir' => $scan]
        );

        $drop = $scan . '/weline-ffi.ini';
        self::assertTrue($result['success']);
        self::assertFileExists($drop);
        $body = (string) file_get_contents($drop);
        self::assertStringContainsString('extension=ffi', $body);
        self::assertStringContainsString('ffi.enable=true', $body);
    }

    public function testOpcacheUsesZendExtensionAndForcesDirectives(): void
    {
        $cli = $this->root . '/php-cli.ini';
        $fpm = $this->root . '/php.ini';
        file_put_contents($cli, ";zend_extension=opcache\nopcache.enable=0\nopcache.jit=tracing\n");
        file_put_contents($fpm, "memory_limit=128M\n");

        $result = (new PhpExtensionIniEnabler())->enable(
            'opcache',
            static fn (): bool => true,
            ['opcache.enable=1', 'opcache.jit=disable'],
            [
                'ini_files' => [$cli, $fpm],
                'scan_dir' => null,
                'load' => 'zend_extension',
                'force_directives' => true,
            ]
        );

        self::assertTrue($result['success']);
        $cliBody = (string) file_get_contents($cli);
        self::assertMatchesRegularExpression('/^\s*zend_extension\s*=\s*opcache\s*$/m', $cliBody);
        self::assertStringContainsString('opcache.enable=1', $cliBody);
        self::assertStringContainsString('opcache.jit=disable', $cliBody);
        self::assertDoesNotMatchRegularExpression('/^\s*extension\s*=\s*opcache\s*$/m', $cliBody);
        $fpmBody = (string) file_get_contents($fpm);
        self::assertStringContainsString('zend_extension=opcache', $fpmBody);
        self::assertStringContainsString('opcache.enable=1', $fpmBody);
        self::assertFileDoesNotExist($this->root . '/weline-opcache.ini');
    }
}
