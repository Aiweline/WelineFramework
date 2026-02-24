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

        $accessControl = static function ($attr, $path, $data, $volume, $isDir, $relpath) {
            $base = \is_string($path) && $path !== '' ? \basename($path) : '';
            $firstChar = $base !== '' ? $base[0] : '';
            $relpathLen = \is_string($relpath) ? \strlen($relpath) : -1;
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
        $dirs = [
            \rtrim($rootPath, '/\\') . '/.trash/.tmb',
            \rtrim($rootPath, '/\\') . '/.tmb',
        ];
        foreach ($dirs as $dir) {
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0755, true);
            }
        }
    }
}
