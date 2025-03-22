<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * PHP编码标准fixer配置
 */
$header = <<<'EOF'
本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
邮箱：aiweline@qq.com
网址：aiweline.com
论坛：https://bbs.aiweline.com
EOF;

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
                           ->name('*.php')
                           ->exclude('pub/media')
                           ->exclude('pub/static')
                           ->exclude('var')
                           ->exclude('vendor')
                           ->exclude('generated')
    ->exclude('extend');

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true, // 启用 PSR-12 编码标准
        'array_syntax' => ['syntax' => 'short'], // 使用短数组语法 ([])
        'binary_operator_spaces' => [
            'default' => 'align_single_space_minimal',
            'operators' => ['=>' => 'align_single_space'], // 二元运算符对齐
        ],
        'blank_line_after_namespace' => true, // 命名空间后插入空行
        'blank_line_after_opening_tag' => true, // PHP 开始标签后插入空行
        'blank_line_before_statement' => [
            'statements' => ['return'], // 在 return 语句前插入空行
        ],
        'concat_space' => ['spacing' => 'one'], // 字符串连接符两侧留一个空格
        'declare_strict_types' => true, // 在文件开头声明严格类型
        'function_typehint_space' => true, // 函数参数和返回类型提示之间留空格
        'include' => true, // include/require 关键字后留一个空格
        'lowercase_cast' => true, // 类型转换小写
        'no_blank_lines_after_class_opening' => true, // 类开头后不留空行
        'no_blank_lines_after_phpdoc' => true, // PHPDoc 后不留空行
        'no_empty_phpdoc' => true, // 移除空的 PHPDoc 块
        'no_empty_statement' => true, // 移除空语句
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
                'use_trait',
            ], // 移除多余的空行
        ],
        'no_leading_import_slash' => true, // 移除 use 语句中的前导斜杠
        'no_leading_namespace_whitespace' => true, // 移除命名空间声明中的前导空格
        'no_mixed_echo_print' => ['use' => 'echo'], // 统一使用 echo 而不是 print
        'no_multiline_whitespace_around_double_arrow' => true, // 移除多行数组中 => 周围的空白
        'no_short_bool_cast' => true, // 移除短布尔类型转换
        'no_singleline_whitespace_before_semicolons' => true, // 移除分号前的空白
        'no_spaces_around_offset' => true, // 移除数组偏移量中的空白
        'no_trailing_comma_in_list_call' => true, // 移除 list() 调用中的尾随逗号
        'no_trailing_comma_in_singleline_array' => true, // 移除单行数组中的尾随逗号
        'no_trailing_whitespace' => true, // 移除行尾空白
        'no_unneeded_control_parentheses' => true, // 移除不必要的控制结构括号
        'no_unused_imports' => true, // 移除未使用的 use 语句
        'no_whitespace_before_comma_in_array' => true, // 移除数组中逗号前的空白
        'no_whitespace_in_blank_line' => true, // 移除空行中的空白
        'normalize_index_brace' => true, // 规范化数组索引括号
        'object_operator_without_whitespace' => true, // 对象操作符前后不留空格
        'ordered_imports' => ['sort_algorithm' => 'alpha'], // 按字母顺序排序 use 语句
        'phpdoc_align' => ['align' => 'left'], // PHPDoc 注释左对齐
        'phpdoc_indent' => true, // PHPDoc 注释缩进
        'phpdoc_no_access' => true, // 移除 PHPDoc 中的 @access 标签
        'phpdoc_no_package' => true, // 移除 PHPDoc 中的 @package 标签
        'phpdoc_no_useless_inheritdoc' => true, // 移除无用的 @inheritDoc 标签
        'phpdoc_scalar' => true, // PHPDoc 中的标量类型小写
        'phpdoc_single_line_var_spacing' => true, // PHPDoc 中的单行变量注释间距
        'phpdoc_summary' => false, // PHPDoc 中不强制要求摘要
        'phpdoc_to_comment' => false, // 不将 PHPDoc 转换为普通注释
        'phpdoc_trim' => true, // 移除 PHPDoc 中多余的空白
        'phpdoc_types' => true, // PHPDoc 中的类型小写
        'phpdoc_var_without_name' => true, // 移除 PHPDoc 中无用的变量名
        'return_type_declaration' => ['space_before' => 'none'], // 返回类型声明前不留空格
        'single_blank_line_at_eof' => true, // 文件末尾留一个空行
        'single_blank_line_before_namespace' => true, // 命名空间前留一个空行
        'single_class_element_per_statement' => ['elements' => ['const', 'property']], // 每个类元素单独一行
        'single_import_per_statement' => true, // 每个 use 语句单独一行
        'single_line_after_imports' => true, // use 语句后留一个空行
        'single_quote' => true, // 使用单引号
        'space_after_semicolon' => true, // 分号后留一个空格
        'standardize_not_equals' => true, // 使用标准的 != 而不是 <>
        'ternary_operator_spaces' => true, // 三元运算符周围留空格
        'trailing_comma_in_multiline' => ['elements' => ['arrays']], // 多行数组中使用尾随逗号
        'trim_array_spaces' => true, // 移除数组中多余的空格
        'unary_operator_spaces' => true, // 一元运算符后留空格
        'visibility_required' => ['elements' => ['method', 'property']], // 强制要求方法和属性的可见性声明
    ]);
