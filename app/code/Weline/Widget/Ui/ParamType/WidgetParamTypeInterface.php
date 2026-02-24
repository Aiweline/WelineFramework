<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * Widget 参数类型 UI 组件接口
 *
 * 每种参数类型（string, number, bool, select 等）都需要实现此接口，
 * 用于生成统一风格的表单控件 HTML。
 */
interface WidgetParamTypeInterface
{
    /**
     * 渲染参数输入控件 HTML
     *
     * @param string $key 参数键名
     * @param array $param 参数定义，包含 type, label, default, options 等
     * @param mixed $value 当前值
     * @param int|string $layoutId 布局ID，用于生成唯一的字段ID
     * @param array $attrs 附加 HTML 属性
     * @return string 渲染后的 HTML
     */
    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string;

    /**
     * 获取类型代码
     *
     * @return string 类型代码，如 'string', 'number', 'bool' 等
     */
    public function getTypeCode(): string;

    /**
     * 验证值是否有效
     *
     * @param mixed $value 要验证的值
     * @param array $param 参数定义
     * @return bool 是否有效
     */
    public function validate(mixed $value, array $param): bool;

    /**
     * 处理/转换值
     *
     * 用于在保存前对值进行预处理，如类型转换、格式化等
     *
     * @param mixed $value 原始值
     * @param array $param 参数定义
     * @return mixed 处理后的值
     */
    public function processValue(mixed $value, array $param): mixed;

    /**
     * 获取默认值
     *
     * @param array $param 参数定义
     * @return mixed 默认值
     */
    public function getDefaultValue(array $param): mixed;
}
