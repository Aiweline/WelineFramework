<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block\Csrf;
use Weline\Framework\View\Exception\TemplateException;
use Weline\Hook\HookData;

class Taglib
{
    public const operators_symbols = [
        # 比较
        '>',
        '<',
        '!==',
        '===',
        '==',
        '!=',
        '<>',
        '>=',
        '<=>',
        '<=',
        # 逻辑
        '&&',
        '||',
        '|',
        '!',
        ' and ',
        ' or ',
        ' xor ',
        # 算数运算
        '**',
        '%',
        '/',
        '*',
        '-',
        '+',
        # 位运算
        '<<',
        '>>',
        '&',
        '^^',
        '^',
        '|',
        # 赋值运算
        '=',
        '+=',
        '-=',
        '*=',
        '/=',
        '%=',
        '<<=',
        '>>=',
        '&=',
        '^^=',
        '^=',
        '|='
    ];

    public const operators_symbols_to_lang = [
        '||' => ' or ',
        '&&' => ' and ',
//        '|'=>' or ', 已当做过滤器使用
        '&' => ' and ',
        'xor' => ' xor ',
        ' neq ' => ' !== ',
        ' eq ' => ' == ',
        ' gt ' => ' > ',
        ' lt ' => ' < ',
        ' gte ' => ' >= ',
        ' lte ' => ' <= '
    ];

    public const special_lang_symbols = [
        'null', 'and', 'or', 'xor', '||', 'neq', 'eq', 'gt', 'lt', 'gte', 'lte'
    ];

    public function checkFilter(string $name, string $filter = '|', $default = '\'\''): array
    {
        if (str_contains($name, PHP_EOL)) {
            $name = str_replace(array("\r\n", "\r", "\n", "\t", ' '), '', $name);
        }
        if (str_contains($name, $filter)) {
            $name_arr = explode('|', $name);
            $name = $name_arr[0];
            if (w_get_string_between_quotes($name_arr[1])) {
                $default = $name_arr[1];
            } else {
                $default = $this->varParser($name_arr[1]);
            }
        }
        return [$name, $default];
    }

    public function checkVar(string $name): string
    {
        if (str_starts_with($name, '$')) {
            //            return '('.$name.'??"")';
            return $name;
        }
        # 有字母的，且不是字符串，不存在特殊字符内的，可以加$
        if (preg_match('/^[a-zA-Z|\|\|]/', $name)) {
            if (!in_array($name, self::special_lang_symbols) and !str_starts_with($name, '"') and !str_starts_with($name, "'")) {
                $name = $name ? '$' . $name : $name;
            }
        }
        return $name;
    }

    public function varParser(string $name): string
    {
        $name_str = '';
        # 处理过滤器
        list($name, $default) = $this->checkFilter($name);
        # 去除空白以及空格
        $name = $this->checkVar($name);


        # 处理转行变量
        //        $name = str_replace('    ', '', $name);
        $name = preg_replace('/ {4,}/', '', $name);

        # 单双引号包含的字符串不解析
        $exclude_names = w_get_string_between_quotes($name);

        foreach ($exclude_names as $key => $exclude_name) {
            $name = str_replace($exclude_name, 'w_var_str' . $key, $name);
        }

        $pattern = '/(?<![\-\>()\s])\s*([><=!]={1,3}+|&&|\|\|)\s*(?![()\s])/';
        $name = preg_replace($pattern, ' $1 ', $name);

        //        $name = $newString;
        //        d($name);
        foreach ($exclude_names as $key => $exclude_name) {
            $name = str_replace('w_var_str' . $key, $exclude_name, $name);
        }
        $names = explode(' ', $name);
        foreach ($names as $name_key => $var) {
            # 排除字符串
            if (!str_contains($var, '"') && !str_contains($var, '\'')) {
                $var = $this->checkVar($var);
            }
            $pieces = explode('.', $var);
            $has_piece = false;
            if (count($pieces) > 1) {
                //                if (PROD) {
                //                    $name_str .= '(';
                //                }
                $name_str .= '(';
                $has_piece = true;
            }
            foreach ($pieces as $key => $piece) {
                if (0 !== $key) {
                    if (str_contains($piece, '$')) {
                        $piece = '[' . $this->varParser(implode('.', $pieces)) . ']';
                        $name_str .= $piece;
                        break;
                    } else {
                        $piece = '[\'' . $piece . '\']';
                    }
                }
                $name_str .= $piece;
                unset($pieces[$key]);
            }
            $name_str = $has_piece ? "{$name_str}??{$default}) " : $name_str . ' ';
        }

        // 替换操作符
        foreach (self::operators_symbols_to_lang as $item) {
            if (str_contains($name_str, $item)) {
                $name_str = str_replace($item, ' ' . $item . ' ', $name_str);
            }
        }

        return $name_str;
    }

    /**
     * 静态缓存：在同一个请求中缓存已收集的标签配置
     * 避免重复的事件分发和标签类实例化
     */
    private static ?array $cachedTags = null;

    public function getTags(Template $template, string $fileName = '', $content = ''): array
    {
        // 优化：使用静态缓存，避免同一请求内重复收集标签
        if (self::$cachedTags !== null) {
            return self::$cachedTags;
        }
        
        $tags = [
            'php' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => '<?php ',
                            'tag-end' => '?>',
                            default => "<?php {$tag_data[1]} ?>"
                        };
                    }
            ],
            'include' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => '<?php include(',
                            'tag-end' => ');?>',
                            default => "<?php include({$tag_data[1]});?>"
                        };
                    }
            ],
            'var' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag()':
                            case '@tag{}':
                                $var_name = $this->varParser($tag_data[1]);
                                return "<?=$var_name?>";
                            default:
                                $var_name = $this->varParser($this->checkVar($tag_data[2]));
                                return "<?=$var_name?>";
                        }
                    }
            ],
            'pp' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=p({$var_name})?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=p({$var_name})?>";
                        }
                    }
            ],
            'dd' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=dd({$var_name})?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=dd({$var_name})?>";
                        }
                    }
            ],
            'count' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=$var_name?count({$var_name}):0?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=$var_name?count({$var_name}):0?>";
                        }
                    }
            ],
            
            'hook' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        // 优化：使用静态缓存 HookReader 实例，避免重复实例化（在函数最外层声明）
                        static $cachedHookReader = null;
                        
                        // 处理成对标签的情况（支持 else）
                        if ($tag_key === 'tag') {
                            $content = $tag_data[2] ?? '';
                            
                            // 检查是否有 else 标签（支持多种格式）
                            $else_pattern = '/<(?:w:)?else\s*\/?>/i';
                            $has_else = preg_match($else_pattern, $content, $else_matches, PREG_OFFSET_CAPTURE);
                            
                            if ($has_else && isset($else_matches[0]) && is_array($else_matches[0]) && isset($else_matches[0][1])) {
                                // 分割内容：else 之前是 hook 名称，之后是 else 内容
                                $else_pos = is_int($else_matches[0][1]) ? $else_matches[0][1] : (int)$else_matches[0][1];
                                $else_match_str = $else_matches[0][0] ?? '';
                                $hook_name = trim(substr($content, 0, $else_pos));
                                // 移除 hook 名称中的所有空白字符（包括换行符、制表符等）
                                $hook_name = preg_replace('/\s+/', '', $hook_name);
                                $else_content = substr($content, $else_pos + strlen($else_match_str));
                            } else {
                                // 没有 else 标签的情况
                                // 支持简单格式：<w:hook>HookName</w:hook>
                                $trimmed_content = trim($content);
                                
                                // 检查是否包含 HTML 标签或 PHP 代码
                                $html_pos = strpos($content, '<');
                                $php_pos = strpos($content, '<?');
                                
                                if ($html_pos === false && $php_pos === false) {
                                    // 没有 HTML 标签和 PHP 代码，直接作为 hook 名称处理
                                    // 移除所有空白字符（包括换行符、制表符等），只保留 hook 名称
                                    $hook_name = preg_replace('/\s+/', '', $trimmed_content);
                                    $else_content = '';
                                } else {
                                    // 可能内容中混入了 HTML 或其他内容
                                    // 找到第一个 HTML 标签或 PHP 代码的位置，在那之前提取 hook 名称
                                    $min_pos = false;
                                    if ($html_pos !== false) {
                                        $min_pos = $html_pos;
                                    }
                                    if ($php_pos !== false) {
                                        $min_pos = ($min_pos === false) ? $php_pos : min($min_pos, $php_pos);
                                    }
                                    if ($min_pos !== false) {
                                        $hook_name = trim(substr($content, 0, $min_pos));
                                        // 移除 hook 名称中的空白字符
                                        $hook_name = preg_replace('/\s+/', '', $hook_name);
                                        $else_content = substr($content, $min_pos);
                                    } else {
                                        // 如果没找到 HTML 或 PHP 标签，尝试提取 hook 名称（在遇到非合法字符前停止）
                                        $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $trimmed_content);
                                        $hook_name = preg_replace('/\s+/', '', $hook_name);
                                        $else_content = '';
                                    }
                                }
                            }
                            
                            // 统一清理 hook 名称，移除可能混入的 PHP 代码标签和 HTML 标签
                            $hook_name = preg_replace('/<\?php\s*else\s*:?\s*\?>/i', '', $hook_name);
                            $hook_name = preg_replace('/<\?=\s*else\s*\?>/i', '', $hook_name);
                            // 移除可能混入的 HTML 标签（防止 else 内容被错误包含）
                            $hook_name = preg_replace('/<[^>]+>/', '', $hook_name);
                            // 移除可能混入的 PHP 代码
                            $hook_name = preg_replace('/<\?[^?]+\?>/', '', $hook_name);
                            // 只保留 hook 名称（在遇到非合法字符前停止，防止包含后续内容）
                            $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                            $hook_name = trim($hook_name);
                            // 检查 hook 是否存在
                            $hook_exists = false;
                            $hook_has_files = false;
                            
                            try {
                                // 使用 HookData 检查 hook 是否存在
                                $hook_exists = \Weline\Hook\HookData::hookExists($hook_name);
                                // 检查 hook 是否有实现文件
                                if ($hook_exists) {
                                    try {
                                        // 使用缓存的 HookReader 实例
                                        if ($cachedHookReader === null) {
                                            $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                                        }
                                        $hookReader = $cachedHookReader;
                                        $hookReader->setPath($hook_name);
                                        $hook_files = $hookReader->getFileList();
                                        $hook_has_files = !empty($hook_files);
                                    } catch (\Throwable $e) {
                                        // 如果检查失败，假设有文件（运行时处理）
                                        $hook_has_files = true;
                                    }
                                }
                            } catch (\Throwable $e) {
                                // HookData 不可用时，继续执行
                            }
                            
                            // 如果 hook 存在且有实现文件，返回 hook 调用代码
                            if ($hook_exists && $hook_has_files) {
                                // 在开发环境下，检查 hook 是否有规约
                                if (defined('DEV') && DEV) {
                                    try {
                                        $hookRegistry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Hook\HookRegistry::class);
                                        
                                        // 在开发环境下，如果 generated/hooks.php 不存在，提示需要运行 setup:upgrade
                                        $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
                                        if (!file_exists($registryFile)) {
                                            throw new \Exception(
                                                sprintf(
                                                    'Hook 注册表文件不存在，请先运行 php bin/w setup:upgrade 命令收集注册表信息。'
                                                )
                                            );
                                        }
                                        
                                        // 如果钩子没有规约，提示需要运行 setup:upgrade
                                        if (!$hookRegistry->hasSpec($hook_name)) {
                                            // 重新检查
                                            if (!$hookRegistry->hasSpec($hook_name)) {
                                                // 解析模块名
                                                $moduleName = '';
                                                if (str_contains($hook_name, '::')) {
                                                    // 新格式：ModuleName::area::type::component::position
                                                    $parts = explode('::', $hook_name);
                                                    $moduleName = $parts[0] ?? '';
                                                } else {
                                                    // 简单格式：尝试从注册表中查找所属模块
                                                    $hookModule = $hookRegistry->getHookModule($hook_name);
                                                    if ($hookModule) {
                                                        $moduleName = $hookModule;
                                                    } else {
                                                        // 如果找不到，提示用户需要定义规约
                                                        $moduleName = '相关模块';
                                                    }
                                                }
                                                
                                                // 在开发环境下抛出异常
                                                throw new \Exception(
                                                    sprintf(
                                                        'Hook 未定义规约：%s。请在模块 %s 的 hook.php 文件中定义此 hook 的规约。',
                                                        $hook_name,
                                                        $moduleName
                                                    )
                                                );
                                            }
                                        }
                                    } catch (\Throwable $e) {
                                        // 如果是我们主动抛出的异常（hook 未定义规约），继续抛出
                                        if (str_contains($e->getMessage(), 'Hook 未定义规约')) {
                                            throw $e;
                                        }
                                        // 如果 HookRegistry 不可用，记录错误但继续执行
                                    }
                                    
                                    // 在开发模式下，获取 hook 实现文件列表并添加注释
                                    try {
                                        // 使用缓存的 HookReader 实例
                                        if ($cachedHookReader === null) {
                                            $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                                        }
                                        $hookReader = $cachedHookReader;
                                        $hookReader->setPath($hook_name);
                                        // 获取原始文件列表（不使用 callback，获取绝对路径）
                                        $hook_files_raw = $hookReader->getFileList(function($modules_files) {
                                            return $modules_files; // 返回原始格式
                                        });
                                        
                                        if (!empty($hook_files_raw)) {
                                            $hook_comment = "<!-- Hook: {$hook_name} -->\n";
                                            $hook_comment .= "<!-- Hook 实现来源（开发模式显示）:\n";
                                            foreach ($hook_files_raw as $module => $file) {
                                                // 提取相对路径（相对于项目根目录）
                                                if (strpos($file, BP) === 0) {
                                                    $relativePath = str_replace(BP, '', $file);
                                                    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                                                } else {
                                                    // 如果已经是相对路径格式（Module::path），直接使用
                                                    $relativePath = str_replace($module . '::', '', $file);
                                                }
                                                
                                                $hook_comment .= "  - 模块: {$module}\n";
                                                $hook_comment .= "    文件: {$relativePath}\n";
                                                
                                                // 检查是否有 hover 相关的 CSS 类
                                                $cssClass = str_replace(['::', '-'], ['-', '-'], $hook_name);
                                                $hook_comment .= "    CSS 类: .{$cssClass}, .header-{$cssClass}\n";
                                            }
                                            $hook_comment .= "  Hover 展开: 检查 CSS 中是否有 .header-{$hook_name}:hover 或相关 hover 样式\n";
                                            $hook_comment .= "-->\n";
                                            
                                            return $hook_comment . "<?=\$this->getHook('" . $hook_name . "')?>";
                                        }
                                    } catch (\Throwable $e) {
                                        // 如果获取文件列表失败，继续执行但不添加注释
                                    }
                                }
                                
                                return "<?=\$this->getHook('" . $hook_name . "')?>";
                            } else {
                                // hook 不存在或没有实现文件，返回 else 内容
                                // 注意：<else/> 在 hook 标签中只用作切分，不需要转换为 PHP else
                                // 返回的 else_content 中不包含用于切分的 <else/> 标签
                                // 如果 else_content 中有其他 <else/>，由 tagReplace 的后续处理来处理
                                return $else_content;
                            }
                        } else {
                            // 单标签情况：支持 @hook() 和 @hook{} 两种格式
                            // 处理方式与其他标签一致（如 @var(), @lang() 等）
                            if ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                                $hook_name = trim($tag_data[1] ?? '');
                                
                                // 对于 @hook() 或 @hook{} 格式，括号/花括号内的内容应该就是 hook 名称
                                // 但是可能包含后续内容（如另一个 @hook() 调用），需要清理
                                
                                // 先检查是否包含后续的 @hook( 或 @hook{ 或其他标签调用
                                // 如果包含，只保留第一个 hook 名称部分（在遇到后续 hook 调用之前截断）
                                $next_hook_pos = strpos($hook_name, '@hook(');
                                if ($next_hook_pos === false) {
                                    $next_hook_pos = strpos($hook_name, '@hook{');
                                }
                                if ($next_hook_pos !== false) {
                                    $hook_name = trim(substr($hook_name, 0, $next_hook_pos));
                                }
                                
                                // 移除可能混入的 PHP 代码标签和 HTML 标签
                                $hook_name = preg_replace('/<\?php\s*else\s*:?\s*\?>/i', '', $hook_name);
                                $hook_name = preg_replace('/<\?=\s*else\s*\?>/i', '', $hook_name);
                                // 移除可能混入的 HTML 标签
                                $hook_name = preg_replace('/<[^>]+>/', '', $hook_name);
                                // 移除可能混入的 PHP 代码
                                $hook_name = preg_replace('/<\?[^?]+\?>/', '', $hook_name);
                                
                                // 只保留 hook 名称（在遇到非合法字符前停止）
                                // hook 名称格式：Module::area::type::component::position，只允许字母、数字、下划线、连字符、冒号
                                // 遇到空格、括号等字符时，应该截断
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                                
                                // 在开发环境下，检查 hook 是否有规约（在 hook.php 中定义）
                                // 统一要求所有 hook 都必须定义规约
                                // 再次清理 hook 名称，确保没有混入其他内容
                                $hook_name = preg_replace('/<[^>]+>/', '', $hook_name); // 移除 HTML 标签
                                $hook_name = preg_replace('/<\?[^?]+\?>/', '', $hook_name); // 移除 PHP 代码
                                // 只保留 hook 名称（在遇到非合法字符前停止，防止包含后续内容）
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                            } else {
                                // 其他格式（向后兼容）
                                $hook_name = trim($tag_data[1] ?? '');
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                            }
                            
                            if (defined('DEV') && DEV) {
                                try {
                                    $hookRegistry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Hook\HookRegistry::class);
                                    
                                    // 在开发环境下，如果 generated/hooks.php 不存在，提示需要运行 setup:upgrade
                                    $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
                                    if (!file_exists($registryFile)) {
                                        throw new \Exception(
                                            sprintf(
                                                'Hook 注册表文件不存在，请先运行 php bin/w setup:upgrade 命令收集注册表信息。'
                                            )
                                        );
                                    }
                                    
                                    if (!$hookRegistry->hasSpec($hook_name)) {
                                        // 解析模块名
                                        $moduleName = '';
                                        if (str_contains($hook_name, '::')) {
                                            // 新格式：ModuleName::area::type::component::position
                                            $parts = explode('::', $hook_name);
                                            $moduleName = $parts[0] ?? '';
                                        } else {
                                            // 简单格式：尝试从注册表中查找所属模块
                                            $hookModule = $hookRegistry->getHookModule($hook_name);
                                            if ($hookModule) {
                                                $moduleName = $hookModule;
                                            } else {
                                                // 如果找不到，提示用户需要定义规约
                                                $moduleName = '相关模块';
                                            }
                                        }
                                        
                                        // 在开发环境下抛出异常
                                        throw new \Exception(
                                            sprintf(
                                                'Hook 未定义规约：%s。请在模块 %s 的 hook.php 文件中定义此 hook 的规约。',
                                                $hook_name,
                                                $moduleName
                                            )
                                        );
                                    }
                                } catch (\Throwable $e) {
                                    // 如果是我们主动抛出的异常（hook 未定义规约），继续抛出
                                    if (str_contains($e->getMessage(), 'Hook 未定义规约')) {
                                        throw $e;
                                    }
                                    // 如果 HookRegistry 不可用，记录错误但继续执行
                                    // 这样可以在运行时处理
                                }
                            }
                        
                        // 在编译阶段尝试检查 hook 是否有实现文件（用于开发模式注释）
                        // 但即使没有找到文件，也生成 getHook() 调用，让运行时处理
                        $hook_files = [];
                        try {
                            // 使用缓存的 HookReader 实例
                            if ($cachedHookReader === null) {
                                $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                            }
                            $hookReader = $cachedHookReader;
                            $hookReader->setPath($hook_name);
                            $hook_files = $hookReader->getFileList();
                        } catch (\Throwable $e) {
                            // 如果检查失败（例如 Hook 模块未安装或 HookReader 不可用），继续生成 PHP 代码
                            // 这样可以在运行时处理
                        }
                        
                        // 在开发模式下，如果有实现文件，添加 hook 实现来源注释
                        if (defined('DEV') && DEV && !empty($hook_files)) {
                            $hook_comment = "<!-- Hook: {$hook_name} -->\n";
                            $hook_comment .= "<!-- Hook 实现来源（开发模式显示）:\n";
                            foreach ($hook_files as $module => $file) {
                                // 提取相对路径
                                if (strpos($file, BP) === 0) {
                                    $relativePath = str_replace(BP, '', $file);
                                    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                                } else {
                                    // 如果已经是相对路径格式（Module::path），直接使用
                                    $relativePath = str_replace($module . '::', '', $file);
                                }
                                
                                $hook_comment .= "  - 模块: {$module}\n";
                                $hook_comment .= "    文件: {$relativePath}\n";
                                
                                // 检查是否有 hover 相关的 CSS 类
                                $cssClass = str_replace(['::', '-'], ['-', '-'], $hook_name);
                                $hook_comment .= "    CSS 类: .{$cssClass}, .header-{$cssClass}\n";
                            }
                            $hook_comment .= "  Hover 展开: 检查 CSS 中是否有 .header-{$hook_name}:hover 或相关 hover 样式\n";
                            $hook_comment .= "-->\n";
                            
                            return $hook_comment . "<?=\$this->getHook('" . $hook_name . "')?>";
                        }
                        
                        // 始终生成 PHP 代码，让运行时处理 hook 文件查找
                        // 即使编译时没有找到文件，运行时可能能找到（因为缓存可能已更新）
                        return "<?=\$this->getHook('" . $hook_name . "')?>";
                        }
                    }
            ],
            'if' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'attr' => ['condition' => 1],
                'callback' => function ($tag_key, $config, $tag_data, $attributes) {
                    $result = '';
                    switch ($tag_key) {
                        // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $condition = $this->varParser($content_arr[0][0]);
                                $result = "<?php if({$condition}):echo {$content_arr[0][1]};endif;?>";
                            } else {
                                foreach ($content_arr as $key => $data) {
                                    // 统一转成数组，避免在 count() / 下标访问时出现字符串类型
                                    $dataArray = (array)$data;
                                    if (0 === $key) {
                                        $condition = $this->varParser($dataArray[0]);
                                        $result = "<?php if($condition):echo " . $dataArray[1] . ';';
                                    } else {
                                        if (count($dataArray) > 1) {
                                            $condition = $this->varParser($dataArray[0]);
                                            $result .= " elseif($condition):echo " . $dataArray[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $dataArray[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;?>';
                                    }
                                }
                            }
                            break;
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("if没有自闭合标签:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                        case 'tag-start':
                            # 排除非if和属性标签的情况
                            if (str_starts_with($tag_data[0], '<if ') || str_starts_with($tag_data[0], '<w:if ')) {
                                if (!isset($attributes['condition'])) {
                                    if (str_starts_with($tag_data[0], '<if ')) {
                                        $template_html = htmlentities($tag_data[0]);
                                        throw new TemplateException(__("if标签缺少condition条件属性:[{$template_html}]，示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                                    }
                                }
                                $condition = $this->varParser($attributes['condition']);
                                $result = "<?php if({$condition}):?>";
                                break;
                            }
                            $result = $tag_data[0];
                            break;
                        case 'tag-end':
                            $result = '<?php endif;?>';
                            break;
                        default:
                    }
                    return $result;
                }
            ],
            'elseif' => [
                'attr' => ['condition' => 1],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            case 'tag-self-close-with-attrs':
                                $condition = $this->varParser($this->checkVar($attributes['condition']));
                                $result = "<?php elseif({$condition}):?>";
                                break;
                            default:
                        }
                        return $result;
                    }],
            'else' => [
                'tag-self-close' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            // <else/>
                            case 'tag-self-close':
                                $result = '<?php else:?>';
                                break;
                            default:
                        }
                        return $result;
                    }],
            'empty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php if(empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[{$template_html}] 例如：%{1}", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(empty(' . $name . ')): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'notempty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php if(!empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[$template_html]例如：%{1}", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(!empty(' . $name . ') ): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'has' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $name = $this->varParser($content_arr[0][0]);
                                $result = "<?php if(!empty({$name})):echo {$content_arr[0][1]};endif;?>";
                            } else {
                                $result = '';
                                foreach ($content_arr as $key => $data) {
                                    // 统一转为数组，避免 count()/下标访问时类型为 string
                                    $dataArray = (array)$data;
                                    if (0 === $key) {
                                        $name = $this->varParser($dataArray[0]);
                                        $result = "<?php if(!empty($name)):echo " . $dataArray[1] . ';';
                                    } else {
                                        if (count($dataArray) > 1) {
                                            $name = $this->varParser($dataArray[0]);
                                            $result .= " elseif(!empty($name)):echo " . $dataArray[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $dataArray[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;?>';
                                    }
                                }
                            }
                            return $result;
                        //                            $content_arr = explode('|', $tag_data[1]);
                        //                            $name        = $this->varParser($this->checkVar($content_arr[0]));
                        /*                            return "<?php if(!empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";*/
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("has标签需要设置name属性:[$template_html]例如：%{1}", htmlentities('<has name="catalogs"><li>有数据</li><else/>没数据</has>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(!empty(' . $name . ') ): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'block' => [
                'doc' => '@block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}或者@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)或者' . htmlentities('<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>') . '或者' . htmlentities('<block>Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml</block>'),
                'tag' => 1,
                'attr' => ['class' => 0, 'template' => 0, 'cache' => 0],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            //<block>Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300</block>
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $data = array_merge($data, $attributes);
                                $result = '<?php echo framework_view_process_block(' . w_var_export($data, true) . ');?>';
                                break;
                            // @block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}
                            case '@tag{}':
                            case '@tag()':
                                $data = explode('|', $tag_data[1]);
                                if (!isset($data[0]) || !$data[0]) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(
                                        __(
                                            "@block标签语法使用错误：未指定block类:[{$template_html}]。示例：%{1}或者%{2}",
                                            [htmlentities('@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml)'), htmlentities('@block{Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml}')]
                                        )
                                    );
                                }
                                $result = '<?php echo framework_view_process_block(' . w_var_export($data, true) . ');?>';
                                break;
                            // <block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml'/>
                            case 'tag-self-close-with-attrs':
                                if (!isset($attributes['class']) || !$attributes['class']) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(__("block标签语法使用错误:[{$template_html}]：未指定block类。示例：%{1}", htmlentities("<block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml' vars='item|pageSize|page'/>")));
                                }
                                // 变量导入
                                $vars_string = '[';
                                if (isset($attributes['vars'])) {
                                    $vars = explode(',', $attributes['vars']);
                                    foreach ($vars as $key => $var) {
                                        $var_name = trim($var);
                                        $var = '$' . $var_name;
                                        $vars_string .= "'$var_name'=>&$var,";
                                    }
                                }
                                $vars_string .= ']';
                                $result = '<?php echo framework_view_process_block(' . w_var_export($attributes, true) . ',$vars=' . $vars_string . ');?>';
                                break;
                            default:
                        }
                        return $result;
                    }
            ],
            'foreach' => [
                'attr' => ['name' => 1, 'key' => 0, 'item' => 0],
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @foreach{$name as $key=>$v|<li><var>$k</var>:<var>$v</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $foreach_str = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php
                        foreach({$foreach_str}){
                        ?>
                            {$template->tmp_replace($content_arr[1] ?? '')}
                            <?php
                        }
                        ?>";
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("foreach没有自闭合标签:[{$template_html}]。示例：%{1}", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                        case 'tag-start':
                            if (!isset($attributes['item'])) {
                                $attributes['item'] = 'v';
                            }
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("foreach标签需要指定要循环的变量name属性:[{$template_html}]。例如：需要循环catalogs变量则%{1}", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                            }
                            foreach ($attributes as $key => $attribute) {
                                $attributes[$key] = $this->checkVar($attribute);
                            }
                            $vars = $this->varParser($this->checkVar($attributes['name']));
                            $k_i = isset($attributes['key']) ? $attributes['key'] . ' => ' . $attributes['item'] : $attributes['item'];
                            return "<?php foreach($vars as $k_i):?>";
                        case 'tag-end':
                            return '<?php endforeach;?>';
                        default:
                            return '';
                    }
                }
            ],
            'static' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => $template->fetchTagSource('statics', trim($tag_data[2])),
                            default => $template->fetchTagSource('statics', trim($tag_data[1]))
                        };
                    }
            ],
            'template' => [
                'tag' => 1,
                'attr' => ['enable' => 0],
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $enable = $attributes['enable'] ?? 1;
                        if (!$enable or ($enable === 'false')) {
                            $template_string = $tag_data[0] ?? '';
                            $target_template = $tag_data[2] ?? '';
                            return "<!-- 模块被禁用：{$target_template} 原始模板：{$template_string}-->";
                        }
                        return match ($tag_key) {
                            'tag' => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[2]))),
                            default => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[1])))
                        };
                    }
            ],
            'js' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<script {$tag_data[1]} src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}'></script>",
                            default => "<script src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}'></script>"
                        };
                    }
            ],
            'css' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<link {$tag_data[1]} href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}' rel=\"stylesheet\" type=\"text/css\"/>",
                            default => "<link href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}' rel=\"stylesheet\" type=\"text/css\"/>"
                        };
                    }
            ],
            'lang' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        // 处理 @lang() 和 @lang{} 格式的参数
                        if ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                            $content = trim($tag_data[1] ?? '');
                            
                            // 检查是否包含逗号（可能是参数分隔符）
                            // 注意：需要处理字符串中的逗号，不能简单按逗号分割
                            // 先尝试解析：可能是 "文本, 参数" 或 "文本" 格式
                            $word = $content;
                            $args_code = null;
                            
                            // 尝试解析参数（如果内容以引号开始，可能是字符串参数）
                            // 否则检查是否有逗号分隔的参数
                            if (preg_match('/^([^,]+?)\s*,\s*(.+)$/', $content, $matches)) {
                                // 有逗号，可能是参数格式：@lang(文本, 参数)
                                $word = trim($matches[1]);
                                $args_code = trim($matches[2]);
                                
                                // 移除可能的引号
                                $word = trim($word, '\'"');
                                
                                // 返回带参数的 PHP 代码
                                return "<?=__('" . addslashes($word) . "', {$args_code})?>";
                            } else {
                                // 无参数，直接翻译
                                $word = trim($content, '\'"');
                                return __($word);
                            }
                        }
                        
                        // 处理 <lang> 标签格式
                        $word = match ($tag_key) {
                            'tag' => $tag_data[2] ?? '',
                            default => $tag_data[1] ?? ''
                        };
                        $word = trim($word);
                        
                        // 处理 args 属性
                        if (isset($attributes['args']) && !empty($attributes['args'])) {
                            // 如果有 args 属性，传递给 __() 函数
                            $args_code = $attributes['args'];
                            return "<?=__('" . addslashes($word) . "', {$args_code})?>";
                        } else {
                            // 没有 args 属性，直接调用 __() 函数
                            return __($word);
                        }
                    }
            ],
            'url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= "<?=\$this->getUrl('{$var}',{$arr_str})?>";
                                } else {
                                    $result .= "<?=\$this->getUrl('{$var}')?>";
                                }
                                break;
                            case  'tag-start':
                                $result .= "<?=\$this->getUrl(";
                                break;
                            case 'tag-end':
                                $result .= ')?>';
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= "<?=\$this->getUrl({$data})?>";
                        };
                        return $result;
                    }
            ],
            'frontend-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= "<?=\$this->getFrontendUrl('{$var}',{$arr_str})?>";
                                } else {
                                    $result .= "<?=\$this->getFrontendUrl('{$var}')?>";
                                }
                                break;
                            case  'tag-start':
                                $result .= "<?=\$this->getFrontendUrl(";
                                break;
                            case 'tag-end':
                                $result .= ')?>';
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= "<?=\$this->getFrontendUrl({$data})?>";
                        };
                        return $result;
                    }
            ],
            'api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= "<?=\$this->getApi('{$var}',{$arr_str})?>";
                                } else {
                                    $result .= "<?=\$this->getApi('{$var}')?>";
                                }
                                break;
                            case  'tag-start':
                                $result .= "<?=\$this->getApi(";
                                break;
                            case 'tag-end':
                                $result .= ')?>';
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= "<?=\$this->getApi({$data})?>";
                        };
                        return $result;
                    }
            ],
            'admin-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendUrl(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'backend-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendUrl(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'backend-api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendApi({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendApi({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendApi(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendApi({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendApi({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'string' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $string = $tag_data[2];
                                $str_arr = explode('|', $string);
                                $str_var = $this->varParser($this->checkVar(array_shift($str_arr)));
                                $str_len = intval(array_shift($str_arr));

                                return "<?php if(!empty({$str_var})&&$str_len>0 && strlen({$str_var})>{$str_len}){
                                    echo mb_substr({$str_var},0,{$str_len},'UTF8').'...';
                                }else{
                                echo {$str_var};
                                }?>";
                            default:
                                $string = $tag_data[1];
                                $str_arr = explode('|', $string);
                                $str_var = $this->checkVar(array_shift($str_arr));
                                $str_len = intval(array_shift($str_arr));

                                return "<?php if($str_len>0 && strlen({$str_var})>{$str_len}){
                                    echo mb_substr({$str_var},0,{$str_len},'UTF8').'...';
                                }else{
                                echo {$str_var};
                                }?>";
                        }
                    }
            ],
            'csrf' => [
                'tag' => 1,
                'doc' => '@csrf{demo}或者@csrf(demo)或者' . htmlentities('<csrf name="demo"/>') . '或者' . htmlentities('<csrf>demo</csrf>') . ' 协助在form表单中设置csrf令牌，防止跨站请求伪造（CSRF）攻击',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $name = $tag_data[2] ?? '';
                            // no break
                            case 'tag-self-close-with-attrs':
                                $name = $attributes['name'] ?? '';
                            // no break
                            default:
                                if (empty($name)) {
                                    $name = $tag_data[1] ?? 'csrf';
                                }
                                /**@var Csrf $csrf */
                                $csrf = ObjectManager::getInstance(Csrf::class);
                                return $csrf->render($name);
                        }
                    }
            ],
            'message' => [
                'tag' => 1,
                'doc' => '@message{}或者@message()或者' . htmlentities('<message/>') . '或者' . htmlentities('<message></message>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
            'msg' => [
                'tag' => 1,
                'doc' => '@msg{}或者@msg()或者' . htmlentities('<msg/>') . '或者' . htmlentities('<msg></msg>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
        ];
        # 兼容自定义tag
        /**@var EventsManager $event */
        $event = ObjectManager::getInstance(EventsManager::class);
        $data = (new DataObject(['template' => $template, 'tags' => $tags, 'Taglib' => $this]));
        $event->dispatch('Weline_Framework_Template::after_tags_config', $data);
        $tags = $data->getData('tags') ?: $tags;
        
        # 构造w:tag，确保w:标签紧挨着原始标签且优先处理
        $reordered_tags = [];
        foreach ($tags as $tag => $tag_data) {
            $reordered_tags["w:$tag"] = $tag_data;  // w:标签优先
            $reordered_tags[$tag] = $tag_data;      // 原始标签紧随其后
        }
        $tags = $reordered_tags;
        
        // 缓存结果，避免同一请求内重复收集
        self::$cachedTags = $tags;
        
        return $tags;
    }

    public function tagReplace(Template &$template, string &$content, string &$fileName = ''): array|string
    {
        // 替换{{key}}标签
        preg_match_all('/\{\{([\s\S]*?)\}\}/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $key => $value) {
            $content = str_replace($value[0], "<?={$this->varParser(trim($value[1]))};?>", $content);
        }
        
        // 非开发环境清除所有注释
        if (PROD) {
            preg_match_all('/\<!--([\s\S]*?)-->/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $key => $value) {
                $content = str_replace($value[0], '', $content);
            }
        }
        
        // 系统自带的标签
        $tags = $this->getTags($template, $fileName, $content);
        
        // 直接按getTags返回的顺序处理标签
        $totalTagProcessTime = 0;
        $tagCount = 0;
        foreach ($tags as $tag => $tag_configs) {
            $tag_patterns = [
                'tag-self-close-with-attrs' => '/<' . $tag . '([\s\S]*?)\/>/m',
                'tag' => '/<' . $tag . '([\s\S]*?)>([\s\S]*?)<\/' . $tag . '>/m',
                'tag-start' => '/<' . $tag . '([\s\S]*?)>/m',
                'tag-end' => '/<\/' . $tag . '>/m',
                'tag-self-close' => '/<' . $tag . '\s*\/>/m',
                '@tag()' => '/\@' . $tag . '\(([\s\S]*?)\)/m',
                '@tag{}' => '/\@' . $tag . '\{([\s\S]*?)\}/m',
            ];
            
            # 检测标签所需要的元素，不需要的就跳过
            foreach ($tag_patterns as $tag_key => $tag_pattern) {
                if (str_starts_with($tag_key, 'tag') && !isset($tag_configs[$tag_key])) {
                    unset($tag_patterns[$tag_key]);
                }
            }
            
            # 匹配标签所需处理的tag
            $tag_config_patterns = [];
            foreach ($tag_configs as $config_name => $tag_config) {
                if (str_starts_with($config_name, 'tag') && $tag_config) {
                    $tag_config_patterns[$config_name] = $tag_patterns[$config_name];
                }
            }
            # 默认匹配@tag()和@tag{}
            $tag_config_patterns['@tag()'] = $tag_patterns['@tag()'];
            $tag_config_patterns['@tag{}'] = $tag_patterns['@tag{}'];

            $tagMatchCount = 0;
            $tagCallbackTime = 0;
            foreach ($tag_config_patterns as $tag_key => $tag_pattern) {
                preg_match_all($tag_pattern, $content, $customTags, PREG_SET_ORDER);
                $tagMatchCount += count($customTags);
                
                foreach ($customTags as $customTag) {
                    
                    $format_function = $tag_configs['callback'];
                    if (isset($customTag[1])) {
                        if ($tag_key == 'tag' or $tag_key == 'tag-self-close-with-attrs' or $tag_key == 'tag-start') {
                            // 替换操作符
                            foreach (self::operators_symbols_to_lang as $operator => $symbol) {
                                if (str_contains($customTag[1], $operator)) {
                                    $customTag[1] = str_replace($operator, ' ' . $symbol . ' ', $customTag[1]);
                                }
                            }
                        }
                        // 移除换行符和制表符，确保多行属性能正确解析
                        $customTag[1] = str_replace(PHP_EOL, ' ', $customTag[1]);
                        $customTag[1] = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $customTag[1]);
                    }

                    $rawAttributes = $customTag[1] ?? '';
                    # 如果有属性接下来的字母就不会和标签紧贴着，而如果没有属性那么应该是>括号和标签紧贴着，如果都不是说明并非tag标签
                    if ($rawAttributes && ('tag' === $tag_key || 'tar-start' === $tag_key || 'tag-self-close-with-attrs' === $tag_key || 'tag-self-close' === $tag_key) && !str_starts_with($rawAttributes, ' ')) {
                        continue;
                    }

                    if (isset($customTag[2])) {
                        $customTag[2] = str_replace(PHP_EOL, '', $customTag[2]);
                        $customTag[2] = str_replace(array("\r\n", "\r", "\n", "\t"), '', $customTag[2]);
                    }
                    # 标签支持匹配->
                    $customTag[1] = $rawAttributes;
                    $formatedAttributes = array();
                    # 兼容：属性值单双引号
                    preg_match_all("/(\S*?)='([\s\S]*?)'/", $rawAttributes, $attributes, PREG_SET_ORDER);
                    foreach ($attributes as $attribute) {
                        if (isset($attribute[2])) {
                            $attr = trim($attribute[1]);
                            $formatedAttributes[$attr] = trim($attribute[2]);
                        }
                    }
                    preg_match_all('/(\S*?)="([\s\S]*?)"/', $rawAttributes, $attributes, PREG_SET_ORDER);
                    foreach ($attributes as $attribute) {
                        if (isset($attribute[2])) {
                            $attr = trim($attribute[1]);
                            $formatedAttributes[$attr] = trim($attribute[2]);
                        }
                    }

                    # 验证标签属性
                    $attrs = $tag_configs['attr'] ?? [];
                    if ($attrs && ('tar-start' === $tag_key || 'tag-self-close-with-attrs' === $tag_key || 'tag' === $tag_key)) {
                        $attributes_keys = array_keys($formatedAttributes);
                        foreach ($attrs as $attr => $required) {
                            if ($required && !in_array($attr, $attributes_keys)) {
                                $provide_attr = implode(',', $attributes_keys);
                                $template_html = htmlentities($attr);
                                throw new TemplateException(__("代码：[{$template_html}] %{1}:标签必须设置属性%{2}, 提供的属性：%{3} 文件：%{4}", [$tag, $attr, $provide_attr, $fileName]));
                            }
                        }
                    }
                    $result = $format_function($tag_key, $tag_configs, $customTag, $formatedAttributes);
                    $content = str_replace($customTag[0], $result, $content);
                }
            }
        }
        
        return $content;
    }
}
