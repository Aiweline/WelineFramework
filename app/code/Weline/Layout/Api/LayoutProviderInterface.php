<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：布局提供者接口 - 其他模块实现此接口来注册布局支持
 */

namespace Weline\Layout\Api;

/**
 * 布局提供者接口
 * 
 * 其他模块通过实现此接口来注册自己的布局类型和布局选项。
 * 实现类应放在 extends/module/Weline_Layout/ 目录下。
 * 
 * 示例：
 * app/code/WeShop/Product/Extends/Weline_Layout/ProductLayoutProvider.php
 */
interface LayoutProviderInterface
{
    /**
     * 获取模块代码
     * 
     * @return string 模块代码，如 'WeShop_Product'
     */
    public function getModuleCode(): string;

    /**
     * 获取支持的布局类型列表
     * 
     * @return array 布局类型数组，格式如：
     * [
     *     'product_list' => [
     *         'name' => '产品列表布局',
     *         'description' => '用于产品列表页面的布局'
     *     ],
     *     'product_detail' => [
     *         'name' => '产品详情布局',
     *         'description' => '用于产品详情页面的布局'
     *     ]
     * ]
     */
    public function getLayoutTypes(): array;

    /**
     * 获取指定布局类型的可用布局选项
     * 
     * @param string $layoutType 布局类型代码
     * @return array 布局选项数组，格式如：
     * [
     *     'grid' => [
     *         'name' => '网格布局',
     *         'template' => 'WeShop_Product::Frontend/Product/list-grid.phtml',
     *         'preview_image' => 'WeShop_Product::images/layout/grid.png'
     *     ],
     *     'list' => [
     *         'name' => '列表布局',
     *         'template' => 'WeShop_Product::Frontend/Product/list-list.phtml',
     *         'preview_image' => 'WeShop_Product::images/layout/list.png'
     *     ]
     * ]
     */
    public function getLayoutOptions(string $layoutType): array;

    /**
     * 应用布局到指定实体
     * 
     * @param string $layoutType 布局类型代码
     * @param string $layoutCode 布局选项代码
     * @param mixed $entity 实体对象（如产品、分类等）
     * @return bool 是否成功应用
     */
    public function applyLayout(string $layoutType, string $layoutCode, mixed $entity): bool;

    /**
     * 获取当前使用的布局
     * 
     * @param string $layoutType 布局类型代码
     * @param mixed $entity 实体对象
     * @return string|null 当前布局代码，如果没有则返回null
     */
    public function getCurrentLayout(string $layoutType, mixed $entity): ?string;

    /**
     * 获取布局类型的默认布局
     * 
     * @param string $layoutType 布局类型代码
     * @return string 默认布局代码
     */
    public function getDefaultLayout(string $layoutType): string;

    /**
     * 布局切换时的回调
     * 
     * @param string $layoutType 布局类型代码
     * @param string $oldLayout 旧布局代码
     * @param string $newLayout 新布局代码
     * @return void
     */
    public function onLayoutSwitch(string $layoutType, string $oldLayout, string $newLayout): void;
}

