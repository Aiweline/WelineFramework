<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 11:33:44
 */

namespace Weline\Taglib\Taglib;

class Test implements \Weline\Taglib\TaglibInterface
{
    static function name(): string
    {
        return 'test_test'; # 标签的名字 <test_test></test_test>
    }

    static function tag(): bool
    {
        return true; # 处理成对的标签。只有true才会把成对的标签发送到callback函数中供你处理
    }

    static function attr(): array
    {
        return []; # 标签的属性列表。控制属性必须还是非必要 例如：<test_test b='2'></test_test> 如果你的程序需要这个属性那么b是必须的，此时你可以返回['b'=>true]
    }

    static function tag_start(): bool
    {
        return false; # 控制要不要处理标签开始。每个常规的标签一般都是成对出现的。<test_test></test_test>当出现要解析这对标签成为某些特殊的标签是，需要使用这个函数控制返回标签开始的匹配，一般配合tag_end使用。
        #例如：你需要把<test_test>转换成"<?php test_test( " 配合tag_end你可以把后续的标签补上。
    }

    static function tag_end(): bool
    {
        return false; # 控制要不要处理标签结尾。配合tag_start处理成对的标签结尾转换。例如：</test_test>转换成 );\?\>
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            # 这里可以做任何处理，然后返回对应处理后的内容
            return match ($tag_key) {
                'tag'=>'<test_test>这是一个测试标签</test_test>',
                default     => "<?php include({$tag_data[1]});?>"
            };
        };
    }

    static function tag_self_close(): bool
    {
        return false; # 控制自闭和标签 <test_test/> 如果你的标签支持自闭和，返回true。然后在callback中处理
    }

    static function tag_self_close_with_attrs(): bool
    {
        return false; # 控制自闭和标签(携带属性) <test_test class="demo"/> 如果你的标签支持自闭和，返回true。然后在callback中处理
    }

    static function document(): string
    {
        return '使用方法：'.htmlentities('<test_test>测试提示</test_test>').' 这个标签没有作用，仅作为自定义标签参考使用';
    }
}