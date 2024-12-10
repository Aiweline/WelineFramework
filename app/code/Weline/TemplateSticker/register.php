<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_TemplateSticker',
    __DIR__,
    '1.1.0',
    '<p>支持模块（支持多个模块操作同一个位置）中对现有的模板或者主题模板进行粘贴各种模板内容（替换、置前、置后），而无需对原模板文件进行任何修改。</p>
<p>描述：支持模块中对现有的模板或者主题模板进行粘贴各种模板内容，而无需对原模板文件进行任何修改。</p>
<p>作者：秋枫雁飞（Aiweline）</p>
<p>签名：人生总要做点有意义的事儿，非是一定要成功，但是一定是在做。</p>
<a href="http://bbs.aiweline.com"></a>',
    [
        'Aiweline_KteShop'
    ]
);
