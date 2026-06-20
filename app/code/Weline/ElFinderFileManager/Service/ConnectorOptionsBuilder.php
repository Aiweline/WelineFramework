<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Service;

/**
 * 构建 elFinder connector 的配置选项。
 * 使用局部变量与闭包，可在同一进程内多次调用而不会产生全局副作用。
 */
class ConnectorOptionsBuilder
{
    private const SAFE_UPLOAD_MIMES = [
        'image/bmp',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/x-icon',
        'text/plain',
    ];

    private const DENIED_EXTENSIONS = [
        'asp',
        'aspx',
        'bat',
        'cgi',
        'cmd',
        'com',
        'css',
        'exe',
        'hta',
        'htm',
        'html',
        'js',
        'jsp',
        'phtml',
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'pl',
        'ps1',
        'py',
        'shtml',
        'sh',
        'svg',
        'svgz',
        'xht',
        'xhtml',
        'xml',
    ];

    private const DISABLED_COMMANDS = [
        'archive',
        'chmod',
        'edit',
        'editor',
        'extract',
        'mkfile',
        'netmount',
        'put',
        'resize',
        'url',
        'zipdl',
    ];

    /**
     * 构建 elFinder 的 $opts 数组。
     *
     * @param string $rootPath 媒体根目录绝对路径（如 PUB . 'media'）
     * @param string $rootUrl 媒体根 URL（如 '/pub/media'）
     * @param array $mimes 允许的 MIME 类型列表
     * @param string|null $startPath 起始子路径，空或 'undefined' 会被规范为 null
     * @param string $local 本地化标识，用于 elFinder 的 local 选项
     * @return array elFinder 的 opts 配置
     */
    public function build(
        string $rootPath,
        string $rootUrl,
        array $mimes,
        ?string $startPath,
        string $local = ''
    ): array {
        $startPath = $this->normalizeStartPath($startPath);
        $mimes = $this->filterAllowedMimes($mimes);
        $this->ensureVolumeDirs($rootPath);

        $accessControl = function ($attr, $path, $data, $volume, $isDir, $relpath) {
            $base = is_string($path) && $path !== '' ? basename($path) : '';
            $firstChar = $base !== '' ? $base[0] : '';
            $relpathLen = is_string($relpath) ? strlen($relpath) : -1;
            return $firstChar === '.' && $relpathLen !== 1
                ? !($attr === 'read' || $attr === 'write')
                : null;
        };

        // Windows 下 hash 与 Linux 不同，禁用 Trash 避免 hash 不匹配错误
        // 若需启用 Trash，需动态计算 trashHash 或在首次挂载后获取
        return [
            'debug' => DEBUG,
            'local' => $local,
            'roots' => [
                [
                    'driver' => 'LocalFileSystem',
                    'path' => rtrim($rootPath, '/') . '/',
                    'startPath' => $startPath,
                    'URL' => rtrim($rootUrl, '/') . '/',
                    'uploadDeny' => ['all'],
                    'uploadAllow' => $mimes,
                    'uploadOrder' => ['deny', 'allow'],
                    'disabled' => self::DISABLED_COMMANDS,
                    'copyOverwrite' => false,
                    'acceptedName' => [$this, 'isAcceptedName'],
                    'accessControl' => $accessControl,
                ],
            ],
            'optionsNetVolumes' => [
                '*' => [
                    'tmbURL' => rtrim($rootUrl, '/') . '/.tmb',
                    'tmbPath' => rtrim($rootPath, '/') . '/.tmb',
                    'syncMinMs' => 30000,
                ],
            ],
        ];
    }

    private function normalizeStartPath(?string $startPath): ?string
    {
        if ($startPath === '' || $startPath === null || $startPath === 'undefined') {
            return null;
        }

        $startPath = str_replace('\\', '/', trim($startPath));
        if ($startPath === '' || str_contains($startPath, "\0") || str_starts_with($startPath, '/')) {
            return null;
        }

        foreach (explode('/', $startPath) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return null;
            }
        }

        return $startPath;
    }

    public function isAcceptedName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        if ($name[0] === '.' || !preg_match('/^[^\\/:*?"<>|]+$/', $name)) {
            return false;
        }

        $segments = explode('.', strtolower($name));
        array_shift($segments);
        foreach ($segments as $extension) {
            if (in_array($extension, self::DENIED_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    public function isDisabledCommand(string $command): bool
    {
        return in_array($command, self::DISABLED_COMMANDS, true);
    }

    private function filterAllowedMimes(array $mimes): array
    {
        $allowed = [];
        foreach ($mimes as $mime) {
            $mime = strtolower(trim((string)$mime));
            if ($mime === '' || $mime === 'image/svg+xml') {
                continue;
            }

            if ($mime === 'image') {
                foreach (self::SAFE_UPLOAD_MIMES as $safeMime) {
                    if (str_starts_with($safeMime, 'image/')) {
                        $allowed[] = $safeMime;
                    }
                }
                continue;
            }

            if (in_array($mime, self::SAFE_UPLOAD_MIMES, true)) {
                $allowed[] = $mime;
            }
        }

        return $allowed ? array_values(array_unique($allowed)) : self::SAFE_UPLOAD_MIMES;
    }

    private function ensureVolumeDirs(string $rootPath): void
    {
        $rootPath = rtrim($rootPath, '/');
        $dirs = [$rootPath . '/.trash/.tmb/', $rootPath . '/.tmb'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}
