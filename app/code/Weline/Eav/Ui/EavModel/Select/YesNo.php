<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：25/4/2024 15:29:13
 */

namespace Weline\Eav\Ui\EavModel\Select;

use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;

class YesNo implements EavModelInterface
{

    function getHtml(EavAttribute &$attribute, mixed $value, string &$label_class, array &$attrs, array &$option_items = []): string
    {
        $dependence = $attribute->getDependence();
        if(!$dependence){
            $options = $this->getModelData();
            foreach ($option_items as $key => $option_item) {
                $options[$key] = $option_item;
            }
        }else{
            $options[''] = __('-请选择-%1-依赖项-',$dependence);
        }

        $type = $attribute->getType();
        $attrStr = $type->processElementAttr($attribute, $attrs);
        $html = '
        <select ' . $attrStr . '>';
        foreach ($options as $key => $v) {
            if ($value == $key) {
                $html .= '<option value="' . $key . '" selected>' . $v . '</option>';
                continue;
            }
            $html .= '<option value="' . $key . '">' . $v . '</option>';
        }
        $html .= '</select>';
        $type::processLabel($attribute, $label_class, $html);
        $type::processDependence($attribute, $html);
        return $html;
    }

    public function getModelData(string|array $dependenceValues = []): mixed
    {
        return [
            '1' => __('是'),
            '0' => __('否'),
        ];
    }

    static function dependenceProcess(array $dependenceValue=[]): mixed
    {
        return '';
    }
}