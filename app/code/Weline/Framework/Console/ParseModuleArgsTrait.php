<?php

declare(strict_types=1);

namespace Weline\Framework\Console;

/**
 * 解析 -m / --module 及位置参数为模块名列表
 * 供 event:rebuild、hook:rebuild、plugin:di:compile、command:upgrade、extends:rebuild、setup:upgrade 等命令复用
 */
trait ParseModuleArgsTrait
{
    /**
     * 解析模块参数（支持 -m、--module 及位置参数）
     *
     * @param array $args 命令参数
     * @return array 模块名数组
     */
    protected function parseModuleArgs(array $args): array
    {
        $argsModule = $args['module'] ?? $args['m'] ?? [];
        if (is_string($argsModule)) {
            $argsModule = array_filter(array_map('trim', explode(' ', $argsModule)));
        }
        if (empty($argsModule)) {
            $positionalArgs = [];
            foreach ($args as $key => $value) {
                if (is_numeric($key) && is_string($value) && !str_starts_with($value, '-') && $key > 0) {
                    $positionalArgs[] = $value;
                }
            }
            if (!empty($positionalArgs)) {
                $argsModule = $positionalArgs;
            }
        }
        return array_values(array_filter($argsModule));
    }
}
