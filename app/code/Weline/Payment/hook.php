<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Payment 模块 Hook 规约
 * 
 * 定义支付模块提供的所有Hook点
 */
return [
    // 结账支付布局：支付方式区域之前
    'Weline_Payment::frontend::layouts::checkout-payment::methods-before' => [
        'name' => '支付方式选择区域之前',
        'description' => '在结账界面支付方式选择区域之前执行的Hook点，可以用于添加自定义内容',
        'doc' => 'doc/hooks/frontend/checkout/payment-methods-before.md',
    ],
    // 结账支付布局：支付方式区域之后
    'Weline_Payment::frontend::layouts::checkout-payment::methods-after' => [
        'name' => '支付方式选择区域之后',
        'description' => '在结账界面支付方式选择区域之后执行的Hook点，可以用于添加自定义内容',
        'doc' => 'doc/hooks/frontend/checkout/payment-methods-after.md',
    ],
    // 结账支付布局：支付表单之前
    'Weline_Payment::frontend::layouts::checkout-payment::form-before' => [
        'name' => '支付表单之前',
        'description' => '在结账界面支付表单之前执行的Hook点，可以用于添加自定义表单字段',
        'doc' => 'doc/hooks/frontend/checkout/payment-form-before.md',
    ],
    // 结账支付布局：支付表单之后
    'Weline_Payment::frontend::layouts::checkout-payment::form-after' => [
        'name' => '支付表单之后',
        'description' => '在结账界面支付表单之后执行的Hook点，可以用于添加自定义内容',
        'doc' => 'doc/hooks/frontend/checkout/payment-form-after.md',
    ],
    // 结账支付布局：支付结果展示
    'Weline_Payment::frontend::layouts::checkout-payment::result' => [
        'name' => '支付结果展示',
        'description' => '在结账界面支付结果展示区域执行的Hook点，可以用于自定义支付结果展示',
        'doc' => 'doc/hooks/frontend/checkout/payment-result.md',
    ],
];

