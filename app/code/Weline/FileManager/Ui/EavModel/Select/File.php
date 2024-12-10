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

namespace Weline\FileManager\Ui\EavModel\Select;

use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Backend\Model\BackendUserConfig;
use Weline\FileManager\Taglib\FileManager;
use Weline\Framework\Manager\ObjectManager;

class File implements EavModelInterface
{

    function getHtml(EavAttribute &$attribute, mixed $value, string &$label_class, array &$attrs, array &$option_items = []): string
    {
        $type               = $attribute->getTypeModel();
        $defaults           = [
            'target' => '#' . $type->getCode() . '-' . $attribute->getCode() . '-' . 'file',
            'value' => $value,
            'cache' => '0',
        ];
        $attrs['attr_code'] = $attrs['code'];
        unset($attrs['code']);
        foreach ($attrs as $key => $attr) {
            if (str_starts_with($key, 'file-')) {
                $key = substr($key, 5);
                $attrs[$key] = $attr;
            }
        }
        $attr    = array_merge($this->getModelData(), $defaults, $attrs);
        $id      = str_replace('#', '', $attr['target']);
        $title   = $attribute->getName();
        $func    = FileManager::callback();
        $attrStr = '';
        foreach ($attr as $key => &$attr_val) {
            if ($key != 'target') {
                if (is_array($attr_val)) {
                    $attr_val = implode(',', $attr_val);
                    $attrStr  .= ' ' . $key . '="' . $attr_val . '"';
                } else {
                    $attrStr .= ' ' . $key . '="' . $attr_val . '"';
                }
            }
        }
        $params = [
            'file-manager', [], [], $attr
        ];

        $res = call_user_func($func, ...$params);
        $res = str_replace('<?php', '', $res);
        $res = str_replace('?>', '', $res);

        ob_start();
        eval($res);
        $frontendAttrs = $type->getFrontendAttrs();
        $download      = '';
        $value_str     = '';
        if ($value) {
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (!empty($value)) {
                $download .= __('已选文件:');
            }
            foreach ($value as $item) {
                if (empty($item)) {
                    continue;
                }
                $file_name = basename($item);
                $download  .= '<a title="' . __('点击下载') . '" href="' . $item . '" download>' . $file_name . '</a>';
            }
            $value_str = implode(',', $value);
        }
        return '<label class="' . $label_class . '" for="' . $id . '">' . $title . '  ' . $download . '</label><input ' . $attrStr . '  name="' . $type->getCode() . '" value="' . $value_str . '" id="' . $id . '" ' . $frontendAttrs . ' type="text" >' . ob_get_clean();
    }

    public function getModelData(): mixed
    {
        return [
            'var' => '',
            'path' => 'media/uploader/',
            'title' => '从文件管理器选择',
            'multi' => '0',
            'ext' => '*',
            'w' => '50',
            'h' => '50',
            'size' => '102400',
            'code' => ObjectManager::getInstance(BackendUserConfig::class)->getConfig('file_manager')
        ];
    }

    static function dependenceProcess(array $dependenceValue = []): mixed
    {
        return '';
    }
}
