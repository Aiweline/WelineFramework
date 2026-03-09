<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

class ConnectorOptionsBuilder
{
    public function build(
        string $rootPath,
        string $rootUrl,
        array $mimes,
        ?string $startPath,
        string $local = ''
    ): array {
        $startPath = $this->normalizeStartPath($startPath);
        $this->ensureVolumeDirs($rootPath);
        $this->ensureStartPathDir($rootPath, $startPath);

        $accessControl = static function ($attr, $path, $data, $volume, $isDir, $relpath) {
            $base = \is_string($path) && $path !== '' ? \basename($path) : '';
            $firstChar = $base !== '' ? $base[0] : '';
            $relpathLen = \is_string($relpath) ? \strlen($relpath) : -1;
            // .trash 与 .tmb 为 elFinder 回收站与缩略图目录，必须可读写
            if ($base === '.trash' || $base === '.tmb') {
                return null;
            }
            return $firstChar === '.' && $relpathLen !== 1
                ? !($attr === 'read' || $attr === 'write')
                : null;
        };

        return [
            'debug' => \defined('DEBUG') && DEBUG,
            'local' => $local,
            'roots' => [
                [
                    'driver'        => 'LocalFileSystem',
                    'path'          => \rtrim($rootPath, '/\\') . '/',
                    'startPath'     => $startPath,
                    'URL'           => \rtrim($rootUrl, '/') . '/',
                    'trashHash'     => 't1_Lw',
                    'uploadDeny'    => ['all'],
                    'uploadAllow'   => $mimes,
                    'uploadOrder'   => ['deny', 'allow'],
                    'accessControl' => $accessControl,
                ],
                [
                    'id'            => '1',
                    'driver'        => 'Trash',
                    'path'          => \rtrim($rootPath, '/\\') . '/.trash/',
                    'tmbURL'        => \rtrim($rootUrl, '/') . '/.trash/.tmb/',
                    'uploadDeny'    => ['all'],
                    'uploadAllow'   => $mimes,
                    'uploadOrder'   => ['deny', 'allow'],
                    'accessControl' => $accessControl,
                ],
            ],
            'optionsNetVolumes' => [
                '*' => [
                    'tmbURL'    => \rtrim($rootUrl, '/') . '/.tmb',
                    'tmbPath'   => \rtrim($rootPath, '/\\') . '/.tmb',
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
        $root = \rtrim($rootPath, '/\\') . \DIRECTORY_SEPARATOR;
        $dirs = [
            $root . '.trash' . \DIRECTORY_SEPARATOR . '.tmb',
            $root . '.tmb',
        ];
        foreach ($dirs as $dir) {
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * 若 startPath 为 pagebuilder 下目录（如 pagebuilder/pages/{handle}），则确保该目录存在
     */
    public function ensureStartPathDir(string $rootPath, ?string $startPath): void
    {
        if ($startPath === null || $startPath === '') {
            return;
        }
        $startPath = \trim(\str_replace('\\', '/', $startPath), '/');
        if ($startPath === '' || \strpos($startPath, '..') !== false) {
            return;
        }
        if (\strpos($startPath, 'pagebuilder/') !== 0) {
            return;
        }
        $full = \rtrim($rootPath, '/\\') . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $startPath);
        if (!\is_dir($full)) {
            @\mkdir($full, 0755, true);
        }
    }
}
