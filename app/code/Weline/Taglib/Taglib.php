<?php
declare(strict_types=1);

namespace Weline\Taglib;

class Taglib
{
    /**
     * 单参解析：
     * - 对传入的 attributes 中除 json 外的每个键，解析为对应的 $Taglib__{键}
     * - 支持 变量名、点路径、var|默认（''/"" 空串）
     * - 若 attributes['json'] 为真，紧跟 echo json_encode(所有解析后的键)
     */
    public static function attributes(array &$attributes): string
    {
        if(empty($attributes['id'])) {
            $attributes['id'] = 'ms_' . substr(md5(uniqid('', true)), 0, 6);
        }
        $snippets = [];

        // 只注入一次解析函数（接收作用域变量表）
        $snippets[] = <<<'PHP'
if (!function_exists('Weline_Taglib_resolve')) {
    function Weline_Taglib_resolve($__expr, array $__scope) {
        $__default = '';
        $__hasDefault = false;
        if (strpos($__expr,'|') !== false) { list($__path,$__default) = explode('|',$__expr,2); $__hasDefault = true; } else { $__path = $__expr; }
        $__path = trim($__path);
        $__default = trim($__default);
        if ($__default === "''" || $__default === '""') { $__default = ''; }

        if (strpos($__path,'.') === false) {
            $__varName = $__path;
            if (array_key_exists($__varName, $__scope)) { return $__scope[$__varName]; }
            // 优先从 w_env 获取模板变量，向后兼容 $GLOBALS
            $__wenvVal = w_env('template.' . $__varName);
            if ($__wenvVal !== null) { return $__wenvVal; }
            if (isset($GLOBALS[$__varName])) { return $GLOBALS[$__varName]; }
            return $__hasDefault ? $__default : $__varName;
        }

        $__segments = array_filter(explode('.',$__path),'strlen');
        if (empty($__segments)) { return $__hasDefault ? $__default : $__expr; }
        $__rootName = array_shift($__segments);
        // 优先从 w_env 获取模板变量，向后兼容 $GLOBALS
        $__wenvVal = w_env('template.' . $__rootName);
        $__val = array_key_exists($__rootName, $__scope) ? $__scope[$__rootName] : ($__wenvVal ?? ($GLOBALS[$__rootName] ?? null));
        if ($__val === null) { return $__hasDefault ? $__default : $__expr; }
        foreach ($__segments as $__seg) {
            if (is_array($__val) && array_key_exists($__seg,$__val)) { $__val = $__val[$__seg]; continue; }
            if (is_object($__val)) {
                $__getter = 'get'.str_replace(' ','',ucwords(str_replace(['-','_'],' ',$__seg)));
                if (method_exists($__val,$__getter)) { $__val = $__val->{$__getter}(); continue; }
                if (isset($__val->{$__seg})) { $__val = $__val->{$__seg}; continue; }
            }
            $__val = null; break;
        }
        if ($__val === null || $__val === '') { return $__hasDefault ? $__default : $__expr; }
        return $__val;
    }
}
PHP;

        // 需要解析的键（除 json/showJson）
        $keys = array_keys(array_diff_key($attributes, ['json' => true, 'showJson' => true]));

        // 定义所有目标变量为默认空串
        foreach ($keys as $key) {
            $snippets[] = '$' . self::targetName($key) . ' = "";';
        }
        // 调用解析函数（传入 get_defined_vars() 作为作用域）
        foreach ($keys as $key) {
            $expr = (string)$attributes[$key];
            $snippets[] = '$' . self::targetName($key) . ' = Weline_Taglib_resolve(' . var_export($expr, true) . ', get_defined_vars());';
        }

        // 生成 JSON 串（不一定直接打印）
        $pairs = [];
        foreach ($keys as $key) {
            $pairs[] = var_export((string)$key, true) . '=>$' . self::targetName($key);
        }
        $jsonExpr = 'json_encode([' . implode(',', $pairs) . '], JSON_UNESCAPED_UNICODE)';

        // showJson=true 才直接 echo，否则赋值给 $Taglib__json
        $showJson = in_array(strtolower((string)($attributes['showJson'] ?? '')), ['1','true','yes'], true);
        if ($showJson) {
            $snippets[] = 'echo ' . $jsonExpr . ';';
        } else {
            $snippets[] = '$Taglib__json = ' . $jsonExpr . ';';
        }

        return implode("\n", $snippets);
    }

    private static function targetName(string $attr): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $attr) ?? $attr;
        return 'Taglib__' . $clean;
    }
}
