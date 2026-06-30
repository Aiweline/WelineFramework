<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 4) . DS);
}

if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', BP . 'vendor' . DS);
}

if (!function_exists(__NAMESPACE__ . '\\syncElFinderDirectory')) {
    function syncElFinderDirectory(string $source, string $destination): void
    {
        $source = rtrim($source, DS);
        $destination = rtrim($destination, DS);
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $allowedTopLevelPaths = [
            'css',
            'i18n',
            'img',
            'js',
            'sounds',
            'themes',
            'main.default.js',
        ];

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $normalizedRelativePath = str_replace(['\\', '/'], DS, $relativePath);
            $topLevelPath = explode(DS, $normalizedRelativePath, 2)[0] ?? '';
            if (!in_array($topLevelPath, $allowedTopLevelPaths, true)) {
                continue;
            }

            $targetPath = $destination . DS . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    @mkdir($targetPath, 0755, true);
                }
                continue;
            }

            @copy($item->getPathname(), $targetPath);
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\\sanitizeElFinderMainScript')) {
    function sanitizeElFinderMainScript(
        string $content,
        string $jqueryUiCss,
        string $jquery,
        string $jqueryUi,
        string $encodingJapanese
    ): string {
        return str_replace(
            [
                "elFinder.prototype.loadCss('//code.jquery.com/ui/'+uiver+'/themes/smoothness/jquery-ui.css');",
                "elFinder.prototype.loadCss('//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/themes/smoothness/jquery-ui.css');",
                "'jquery'   : '//code.jquery.com/jquery-'+jqver+'.min'",
                "'jquery'   : '//cdnjs.cloudflare.com/ajax/libs/jquery/'+(old? '1.12.4' : jqver)+'/jquery.min'",
                "'jquery'   : '//cdnjs.cloudflare.com/ajax/libs/jquery/' + (old ? '1.12.4' : jqver) + '/jquery.min'",
                "'jquery-ui': '//code.jquery.com/ui/'+uiver+'/jquery-ui.min'",
                "'jquery-ui': '//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/jquery-ui.min'",
                "'jquery-ui': '//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/jquery-ui.min'",
                "'encoding-japanese': '//cdn.jsdelivr.net/npm/encoding-japanese@2.2.0/encoding.min'",
            ],
            [
                "elFinder.prototype.loadCss('{$jqueryUiCss}');",
                "elFinder.prototype.loadCss('{$jqueryUiCss}');",
                "'jquery'   : '{$jquery}'",
                "'jquery'   : '{$jquery}'",
                "'jquery'   : '{$jquery}'",
                "'jquery-ui': '{$jqueryUi}'",
                "'jquery-ui': '{$jqueryUi}'",
                "'jquery-ui': '{$jqueryUi}'",
                "'encoding-japanese': '{$encodingJapanese}'",
            ],
            $content
        );
    }
}

if (!function_exists(__NAMESPACE__ . '\\sanitizeElFinderClientStatics')) {
    function sanitizeElFinderClientStatics(string $targetStaticPath): void
    {
        $targetStaticPath = rtrim($targetStaticPath, DS);
        $mainScript = $targetStaticPath . DS . 'main.default.js';
        if (is_file($mainScript)) {
            $content = @file_get_contents($mainScript);
            if ($content !== false) {
                @file_put_contents(
                    $mainScript,
                    sanitizeElFinderMainScript($content, 'jquery-ui.min.css', '../jquery.min', '../jquery-ui.min', 'encoding-japanese')
                );
            }
        }

        $encodingStub = $targetStaticPath . DS . 'js' . DS . 'encoding-japanese.js';
        if (!is_file($encodingStub)) {
            @file_put_contents($encodingStub, "define(function () { return null; });\n");
        }

        foreach (['elfinder.full.js', 'elfinder.min.js'] as $file) {
            $path = $targetStaticPath . DS . 'js' . DS . $file;
            if (!is_file($path)) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $content = preg_replace('/cdns\\s*:\\s*\\{.*?\\}\\s*,\\s*url\\s*:/s', 'cdns:{},url:', $content, 1) ?? $content;
            $content = preg_replace('/cdns\\s*:\\s*\\{.*?\\}\\s*,\\s*(\\/\\*\\*\\s*\\n\\s*\\* Connector url)/s', "cdns : {},\n\t\n\t$1", $content, 1) ?? $content;
            @file_put_contents($path, $content);
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\\removeLegacyElFinderServerStaticPath')) {
    function removeLegacyElFinderServerStaticPath(string $targetStaticPath): void
    {
        $legacyPhpStaticPath = rtrim($targetStaticPath, DS) . DS . 'php';
        if (!is_dir($legacyPhpStaticPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($legacyPhpStaticPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }

        @rmdir($legacyPhpStaticPath);
    }
}

$targetStaticPath = __DIR__ . DS . 'view' . DS . 'statics';
if (!is_dir($targetStaticPath)) {
    @mkdir($targetStaticPath, 0755, true);
}
removeLegacyElFinderServerStaticPath($targetStaticPath);

$vendorPath = VENDOR_PATH . 'studio-42' . DS . 'elfinder';
if (is_dir($vendorPath)) {
    syncElFinderDirectory($vendorPath, $targetStaticPath);
}
sanitizeElFinderClientStatics($targetStaticPath);

return is_dir($targetStaticPath);
