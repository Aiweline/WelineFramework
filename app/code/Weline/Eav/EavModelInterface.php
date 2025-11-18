<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：25/4/2024 13:46:55
 */

namespace Weline\Eav;

use Weline\Eav\Controller\Backend\Attribute;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type;

interface EavModelInterface
{
    /**
     * @DESC          # 获取模型html
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 26/4/2024 上午10:55
     * 参数区：
     * @param EavAttribute $attribute 示例：\Weline\Eav\Model\EavAttribute
     * @param mixed $value 示例：1
     * @param mixed $label_class 示例：'control-label'
     * @param mixed $attrs 示例：['class'=>'form-control']
     * @param array $option_items  ['1'=>'是','0'=>'否']
     * @param bool $only_custom_options  是否只显示自定义选项
     * @return string
     */
    function getHtml(EavAttribute &$attribute, mixed $value, string &$label_class, array &$attrs, array &$option_items = [], bool $only_custom_options = true): string;

    /**
     * @DESC          # 模型数据
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/5/11 下午6:01
     * 参数区：
     * @return mixed
     */
    function getModelData():mixed;

    /**
     * @DESC          # 依赖处理
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/7/9 下午4:16
     * 参数区：
     * @param array $dependenceValue
     * @return array
     */
    static function dependenceProcess(array $dependenceValue=[]): mixed;
}