<?php
declare(strict_types=1);

namespace Weline\Meta\Service;

/**
 * 将历史词典 @meta:: 键 / 主题 metaKey 解析为 MetaLocal 身份。
 * 读写、迁移临时脚本、远程 type=meta 共用。
 */
final class MetaLocalWordParser
{
    public const META_PREFIX = '@meta::';

    /**
     * @return array{meta_identify:string,config_key:string,scope:string,path:string}|null
     */
    public static function parseDictionaryWord(string $word): ?array
    {
        $word = trim($word);
        if ($word === '' || !str_starts_with($word, self::META_PREFIX)) {
            return null;
        }
        $rest = substr($word, strlen(self::META_PREFIX));
        $scope = 'default';
        if (str_contains($rest, '|scope:')) {
            [$path, $scopePart] = explode('|scope:', $rest, 2);
            $scope = trim($scopePart) !== '' ? trim($scopePart) : 'default';
            $path = trim($path);
        } else {
            $path = trim($rest);
        }
        if ($path === '') {
            return null;
        }
        $parsed = self::parsePath($path);
        if ($parsed === null) {
            return null;
        }
        $parsed['scope'] = $scope;
        $parsed['path'] = $path;
        return $parsed;
    }

    /**
     * 主题侧 metaKey（无 @meta:: 前缀），如 theme.frontend.layouts.default.name
     *
     * @return array{meta_identify:string,config_key:string}|null
     */
    public static function parsePath(string $path): ?array
    {
        $path = trim($path);
        if (str_starts_with($path, self::META_PREFIX)) {
            $path = substr($path, strlen(self::META_PREFIX));
        }
        $path = trim($path);
        if ($path === '' || !str_contains($path, '.')) {
            return null;
        }

        // ….param.{name}.value → config_key=param.{name}
        if (preg_match('/^(?P<identify>.+)\.param\.(?P<param>[^.]+)\.value$/u', $path, $m) === 1) {
            return [
                'meta_identify' => $m['identify'],
                'config_key' => 'param.' . $m['param'],
            ];
        }

        // ….name / ….description
        if (preg_match('/^(?P<identify>.+)\.(?P<field>name|description)$/u', $path, $m) === 1) {
            return [
                'meta_identify' => $m['identify'],
                'config_key' => $m['field'],
            ];
        }

        // theme.{area}.appearance.token_group.{id}.name
        if (preg_match('/^(?P<identify>theme\.(?:frontend|backend)\.appearance\.token_group\.[^.]+)\.name$/u', $path, $m) === 1) {
            return [
                'meta_identify' => $m['identify'],
                'config_key' => 'name',
            ];
        }

        // Meta 五段式 namespace.type.identify.group.field（identify 可能含点，取最后两段为 group.field）
        $parts = explode('.', $path);
        if (count($parts) >= 5) {
            $field = array_pop($parts);
            $group = array_pop($parts);
            if ($field !== '' && $group !== '') {
                return [
                    'meta_identify' => implode('.', $parts),
                    'config_key' => $group . '.' . $field,
                ];
            }
        }

        // 兜底：最后一段为 config_key
        $configKey = array_pop($parts);
        $identify = implode('.', $parts);
        if ($identify === '' || $configKey === null || $configKey === '') {
            return null;
        }
        return [
            'meta_identify' => $identify,
            'config_key' => $configKey,
        ];
    }

    public static function isPreviewScope(string $scope): bool
    {
        return str_contains($scope, '__preview___');
    }

    /**
     * 将 MetaLocal 行还原为历史词典 word（含可选 |scope:）。
     */
    public static function toDictionaryWord(string $metaIdentify, string $configKey, string $scope = 'default'): string
    {
        $metaIdentify = trim($metaIdentify);
        $configKey = trim($configKey);
        $path = $metaIdentify;
        if ($configKey !== '') {
            $path .= '.' . $configKey;
            if (str_starts_with($configKey, 'param.') || str_starts_with($configKey, 'path.')) {
                $path .= '.value';
            }
        }
        $word = self::META_PREFIX . $path;
        $scope = trim($scope);
        if ($scope !== '' && $scope !== 'default') {
            $word .= '|scope:' . $scope;
        }

        return $word;
    }
}
