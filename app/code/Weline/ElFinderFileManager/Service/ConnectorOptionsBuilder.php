<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Service;

/**
 * 构建 elFinder connector 的配置选项。
 * 使用局部变量与闭包，可在同一进程内多次调用而不会产生全局副作用。
 */
class ConnectorOptionsBuilder
{
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
        $this->ensureVolumeDirs($rootPath);

        $accessControl = function ($attr, $path, $data, $volume, $isDir, $relpath) {
            $base = is_string($path) && $path !== '' ? basename($path) : '';
            $firstChar = $base !== '' ? $base[0] : '';
            $relpathLen = is_string($relpath) ? strlen($relpath) : -1;
            return $firstChar === '.' && $relpathLen !== 1
                ? !($attr === 'read' || $attr === 'write')
                : null;
        };

        return [
            'debug' => DEBUG,
            'local' => $local,
            'roots' => [
                [
                    'driver' => 'LocalFileSystem',
                    'path' => rtrim($rootPath, '/') . '/',
                    'startPath' => $startPath,
                    'URL' => rtrim($rootUrl, '/') . '/',
                    'trashHash' => 't1_Lw',
                    'uploadDeny' => ['all'],
                    'uploadAllow' => $mimes,
                    'uploadOrder' => ['deny', 'allow'],
                    'accessControl' => $accessControl,
                ],
                [
                    'id' => '1',
                    'driver' => 'Trash',
                    'path' => rtrim($rootPath, '/') . '/.trash/',
                    'tmbURL' => rtrim($rootUrl, '/') . '/.trash/.tmb/',
                    'uploadDeny' => ['all'],
                    'uploadAllow' => $mimes,
                    'uploadOrder' => ['deny', 'allow'],
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
        return $startPath;
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
