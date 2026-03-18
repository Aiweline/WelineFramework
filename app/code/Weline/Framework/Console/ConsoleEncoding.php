<?php
declare(strict_types=1);

/**
 * CLI 输出编码：在 Windows 下切到 UTF-8 代码页，减少中文与 Unicode 框线乱码。
 * 通过 env console.utf8_output = false 可关闭。
 */

namespace Weline\Framework\Console;

use Weline\Framework\App\Env;

final class ConsoleEncoding
{
    private static bool $initialized = false;

    public static function initForCli(): void
    {
        if (self::$initialized || \PHP_SAPI !== 'cli') {
            return;
        }
        self::$initialized = true;

        try {
            $enabled = Env::get('console.utf8_output', true);
        } catch (\Throwable) {
            $enabled = true;
        }
        if ($enabled === false || $enabled === '0' || $enabled === 0) {
            return;
        }

        if (\function_exists('mb_internal_encoding')) {
            \mb_internal_encoding('UTF-8');
        }
        if (\function_exists('mb_http_output')) {
            \mb_http_output('UTF-8');
        }
        @\ini_set('default_charset', 'UTF-8');

        if (\PHP_OS_FAMILY === 'Windows') {
            if (\function_exists('sapi_windows_cp_set')) {
                @\sapi_windows_cp_set(65001);
            }
            if (\defined('STDOUT') && \function_exists('sapi_windows_vt100_support')) {
                @\sapi_windows_vt100_support(\STDOUT, true);
            }
            if (\defined('STDERR') && \function_exists('sapi_windows_vt100_support')) {
                @\sapi_windows_vt100_support(\STDERR, true);
            }
        } else {
            foreach (['C.UTF-8', 'en_US.UTF-8', 'zh_CN.UTF-8', 'zh_TW.UTF-8', 'UTF-8'] as $locale) {
                if (@\setlocale(\LC_CTYPE, $locale) !== false) {
                    break;
                }
            }
        }
    }
}
