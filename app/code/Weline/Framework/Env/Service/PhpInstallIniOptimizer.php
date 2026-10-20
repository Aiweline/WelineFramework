<?php

declare(strict_types=1);

namespace Weline\Framework\Env\Service;

/**
 * 安装 PHP 时，把环境依赖一次性写进当前二进制真正会读的 ini。
 *
 * opcache 用 zend_extension，不再由检查脚本单独改 ini。
 * 库文件还不在 extension_dir 时跳过，避免在编译扩展之前写入无效的 extension=。
 */
final class PhpInstallIniOptimizer
{
    public function __construct(private ?PhpExtensionIniEnabler $enabler = null)
    {
        $this->enabler ??= new PhpExtensionIniEnabler();
    }

    /**
     * @return array{ok:bool,items:list<array{name:string,success:bool,skipped:bool,message:string}>}
     */
    public function apply(): array
    {
        $items = [
            $this->enableShared('brotli', [], 'exit(extension_loaded("brotli") && function_exists("brotli_compress") && function_exists("brotli_uncompress") ? 0 : 1);'),
            $this->enableShared('event', [], 'exit(extension_loaded("event") ? 0 : 1);'),
            $this->enableShared('ffi', ['ffi.enable=true'], 'exit(extension_loaded("ffi") && class_exists("FFI", false) ? 0 : 1);'),
            $this->enableOpcache(),
        ];
        $ok = true;
        foreach ($items as $item) {
            if ($item['skipped'] || $item['success']) {
                continue;
            }
            if ($item['name'] !== 'opcache') {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'items' => $items];
    }

    /**
     * @return array{name:string,success:bool,skipped:bool,message:string}
     */
    public function enableOpcache(): array
    {
        return $this->enableOne(
            'opcache',
            'zend_extension',
            [
                'opcache.enable=1',
                'opcache.enable_cli=1',
                'opcache.jit=disable',
                'opcache.memory_consumption=256',
                'opcache.validate_timestamps=1',
                'opcache.revalidate_freq=0',
            ],
            'exit(extension_loaded("Zend OPcache") ? 0 : 1);',
            false
        );
    }

    /**
     * @param list<string> $extraLines
     * @return array{name:string,success:bool,skipped:bool,message:string}
     */
    private function enableShared(string $name, array $extraLines, string $probeCode): array
    {
        return $this->enableOne($name, 'extension', $extraLines, $probeCode, true);
    }

    /**
     * @param list<string> $extraLines
     * @return array{name:string,success:bool,skipped:bool,message:string}
     */
    private function enableOne(
        string $name,
        string $load,
        array $extraLines,
        string $probeCode,
        bool $disableOpcacheInProbe
    ): array {
        $already = $name === 'opcache' ? extension_loaded('Zend OPcache') : extension_loaded($name);
        if (!$already && !$this->libraryPresent($name)) {
            return [
                'name' => $name,
                'success' => true,
                'skipped' => true,
                'message' => $name . ' 尚未出现在 extension_dir，安装期跳过 ini',
            ];
        }

        $result = $this->enabler->enable(
            $name,
            fn (): bool => $this->runProbe($probeCode, $disableOpcacheInProbe),
            $extraLines,
            ['load' => $load, 'force_directives' => true]
        );

        return [
            'name' => $name,
            'success' => $result['success'],
            'skipped' => false,
            'message' => $result['message'],
        ];
    }

    private function libraryPresent(string $name): bool
    {
        $dir = (string) ini_get('extension_dir');
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $dir = rtrim($dir, '/\\');
        foreach ([$name . '.so', 'php_' . $name . '.dll', $name . '.dll'] as $file) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                return true;
            }
        }

        return false;
    }

    private function runProbe(string $code, bool $disableOpcache): bool
    {
        $prefix = $disableOpcache ? ' -d opcache.enable=0 -d opcache.enable_cli=0' : '';
        $cmd = escapeshellarg(PHP_BINARY) . $prefix . ' -r ' . escapeshellarg($code);
        $exit = 1;
        exec($cmd, $output, $exit);

        return $exit === 0;
    }
}
