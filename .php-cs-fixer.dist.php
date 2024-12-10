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
                           ->name('*.php')
                           ->exclude('pub/media')
                           ->exclude('pub/static')
                           ->exclude('var')
                           ->exclude('vendor')
                           ->exclude('generated')
                           ->exclude('extend')
                           ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true);
//    ->setRules([
//                   '@PSR2'                                       => true,
//                   'array_syntax'                                => ['syntax' => 'short'],
//                   'concat_space'                                => ['spacing' => 'one'],
//                   //                   'new_with_braces'                               => true,
//                   //                   'no_leading_import_slash'                       => true,
//                   'no_multiline_whitespace_around_double_arrow' => true,
//                   'normalize_index_brace'                       => true,
//                   'array_push'                                  => true,
//                   'backtick_to_shell_exec'                      => true,
//                   'comment_to_phpdoc'                           => true,
//                   //                   'no_trailing_comma_in_singleline_array'         => true,
//                   //                   'object_operator_without_whitespace'            => true,
//                   //                   'phpdoc_add_missing_param_annotation'           => true,   //添加缺少的 Phpdoc @param参数
//                   //                   'phpdoc_trim'                                   => true,
//                   //                   'phpdoc_trim_consecutive_blank_line_separation' => true, //删除在摘要之后和PHPDoc中的描述之后，多余的空行。
//                   //                   'phpdoc_order'                                  => true,
//                   //                   'strict_comparison'                             => true,   //严格比较,会修改代码有风险
//                   //                   'ternary_operator_spaces'                       => true,  //标准化三元运算的格式
//                   //                   'ternary_to_null_coalescing'                    => true,  //尽可能使用null合并运算符??。需要PHP> = 7.0。
//                   //                   'whitespace_after_comma_in_array'               => true, // 在数组声明中，每个逗号后必须有一个空格
//                   //                   'trim_array_spaces'                             => true,  //删除数组首或尾随单行空格
//                   //                   'align_multiline_comment'                       => [
//                   //                       //每行多行 DocComments 必须有一个星号（PSR-5），并且必须与第一行对齐。
//                   //                       'comment_type' => 'phpdocs_only',
//                   //                   ],
//                   //                   'array_indentation'                             => true,  //数组的每个元素必须缩进一次
//                   //                   'ordered_imports'                               => false, // use 排序
//                   //                   'ordered_class_elements'                        => false, //class elements排序
//                   //                   'no_whitespace_in_blank_line'                   => true,  //删除空白行末尾的空白
//                   //                   'no_whitespace_before_comma_in_array'           => true,  //删除数组声明中，每个逗号前的空格
//                   //                   'no_trailing_whitespace'                        => true,  //删除非空白行末尾的空白
//                   //                   'no_spaces_inside_parenthesis'                  => true,  //删除括号后内两端的空格
//                   //                   'no_empty_phpdoc'                               => true,  // 删除空注释
//                   //                   'no_useless_return'                             => true,  //删除函数末尾无用的return
//                   //                   'no_useless_else'                               => true,  //删除无用的eles
//                   //                   'no_unreachable_default_argument_value'         => false, //在函数参数中，不能有默认值在非缺省值之前的参数。有风险
//                   //                   'heredoc_to_nowdoc'                             => true,     //删除配置中多余的空行和/或者空行。
//                   //                   'general_phpdoc_annotation_remove'              => ['expectedException', 'expectedExceptionMessage', 'expectedExceptionMessageRegExp'], //phpdocs中应该省略已经配置的注释
//                   //                   'combine_consecutive_unsets'                    => true,   //多个unset，合并成一个
//                   //                   'header_comment'                                => ['header' => $header], //添加，替换或者删除 header 注释。
//                   //                   'single_quote'                                  => true, //简单字符串应该使用单引号代替双引号；
//                   //                   'no_unused_imports'                             => true, //删除没用到的use
//                   //                   'no_singleline_whitespace_before_semicolons'    => true, //禁止只有单行空格和分号的写法；
//                   //                   'self_accessor'                                 => false, //在当前类中使用 self 代替类名；
//                   //                   'no_empty_statement'                            => true, //多余的分号
//                   //                   'no_extra_blank_lines'                          => ['break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block'],
//                   //                   'no_blank_lines_after_class_opening'            => true, //类开始标签后不应该有空白行；
//                   //                   'include'                                       => true, //include 和文件路径之间需要有一个空格，文件路径不需要用括号括起来；
//                   //                   'no_trailing_comma_in_list_call'                => true, //删除 list 语句中多余的逗号；
//                   //                   'no_leading_namespace_whitespace'               => true, //命名空间前面不应该有空格；
//                   //
//                   //                   'binary_operator_spaces'                 => [
//                   //                       'align_double_arrow' => true, // 对齐双箭头操作符
//                   //                       'align_equals'       => true, // 对齐赋值操作符
//                   //                   ],
//                   //                   'blank_line_after_namespace'             => true, // 命名空间之后有一个空行
//                   //                   'blank_line_after_opening_tag'           => true, // PHP 打开标记之后有一个空行
//                   //                   //                   'blank_line_before_return'               => true, // return 语句之前有一个空行
//                   //                   'blank_line_before_statement'            => [
//                   //                       'statements' => [
//                   //                           'break',
//                   //                           'continue',
//                   //                           'declare',
//                   //                           'return',
//                   //                           'throw',
//                   //                           'try',
//                   //                       ], // 这些声明之前有一个空行
//                   //                   ],
//                   //                   'class_attributes_separation'            => [
//                   //                       'elements' => [
//                   //                           'const',
//                   //                           'method',
//                   //                           'property',
//                   //                       ], // 类的这些元素分开，也就是元素之间加上空行
//                   //                   ],
//                   //                   'dir_constant'                           => true, // 将 dirname(__FILE__) 替换成 __DIR__
//                   //                   'is_null'                                => true, // 将 is_null($a) 替换成 null === $a
//                   //                   'linebreak_after_opening_tag'            => true,
//                   //                   'list_syntax'                            => [
//                   //                       'syntax' => 'long', // list 使用 long 语法
//                   //                   ],
//                   //                   'constant_case'                          => true, // 常量 true, false, null 使用小写
//                   //                   'lowercase_keywords'                     => true, // PHP 关键字使用小写
//                   //                   'method_chaining_indentation'            => true, // 方法链式调用不需要缩进
//                   //                   'modernize_types_casting'                => true, // 将 *val 函数做对应类型的强制转换
//                   //                   'no_blank_lines_before_namespace'        => false, // 命名空间之前没有穿行
//                   //                   'no_closing_tag'                         => true, // 纯 PHP 文件不需要闭合标记
//                   //                   'multiline_whitespace_before_semicolons' => true, // 移除结束分号之前的多余空行
//                   //                   'no_null_property_initialization'        => true, // 移除属性用 null 初始化是的显式指定
//                   //                   'no_php4_constructor'                    => true, // 移除 PHP4 风格的构造方法
//                   //                   'no_short_bool_cast'                     => true, // 采用双感叹号表示布尔情况的不应该使用，会转换为 (bool)
//                   //                   'echo_tag_syntax'                        => false, // 替换短标记输出为长标记
//                   //                   'no_spaces_after_function_name'          => true, // 在进行方法或函数调用时，移除方法或函数名称与左括号之间的空格
//                   //                   'no_spaces_around_offset'                => true, // 移除偏移大括号的空格
//                   //                   'no_superfluous_elseif'                  => true, // 用 if 替换多余的 elseif
//                   //                   'no_trailing_whitespace_in_comment'      => true, // 移除注释末行的尾随空格
//                   //                   'no_unneeded_control_parentheses'        => [
//                   //                       'statements' => [
//                   //                           'break',
//                   //                           'clone',
//                   //                           'continue',
//                   //                           'echo_print',
//                   //                           'return',
//                   //                           'switch_case',
//                   //                           'yield',
//                   //                       ], // 移除控制语句周围不需要的括号
//                   //                   ],
//                   //                   'not_operator_with_space'                => false, // 逻辑非操作符前后有一个空格
//                   //                   'not_operator_with_successor_space'      => true, // 逻辑非操作符尾随一个空格
//                   //                   'short_scalar_cast'                      => true, // 标量使用缩写
//                   //                   'single_blank_line_at_eof'               => true, // 纯 PHP 文件总是以一个空行换行符结束
//                   //                   'single_blank_line_before_namespace'     => true, // 命名空间之前有一个空行
//                   //                   'single_class_element_per_statement'     => [
//                   //                       'elements' => [
//                   //                           'const',
//                   //                           'property',
//                   //                       ], // 类元素的每个声明有自己的关键字
//                   //                   ],
//                   //                   'single_import_per_statement'            => true, // 导入的每个声明有自己的关键字
//                   //                   'single_line_after_imports'              => true, // 每个命名空间的 use 独占一行，最后一个 use 后有一个空行
//                   //                   'standardize_not_equals'                 => true, // 将 <> 替换为 !=
//                   //                   'strict_param'                           => true, // 参数使用严格化
//                   //                   'switch_case_space'                      => true, // 移除冒号和 case value 之间的多余空格
//                   //                   'trailing_comma_in_multiline'            => true, // 多个数组元素的最后一个元素后有一个逗号
//                   //                   'unary_operator_spaces'                  => true, // 一元操作符紧挨操作项，不需要空格分开
//                   //                   'visibility_required'                    => true, // 访问修饰符放置在属性方法前，abstract 和 final 放置在访问修饰符前面，static 放置在访问修饰符后
//               ]);
