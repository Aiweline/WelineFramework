<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/14 21:49:39
 */

namespace Weline\Component\Block;

use PHPUnit\Framework\Exception;

class OffCanvas extends \Weline\Framework\View\Block implements \Weline\Component\ComponentInterface
{
    protected string $_template = 'Weline_Component::off-canvas.phtml';

    const default_data = [
        'cache' => 300,
        'target-tag' => 'a',
        'icon' => '',
        'target-button-text' => '添加',
        'target-button-class' => '',
        'submit-button-text' => '保存',
        'submit-button-class' => 'btn btn-primary',
        'title' => '',
        'close-button-show' => '1',
        'close-button-text' => '关闭',
        'direction' => 'right',
        'flush' => '1',
        'flush-button-text' => "刷新",
        'flush-button-class' => "btn btn-default",
        'save' => '0',
        'save-form' => '',
        'class-names' => 'h-100 overflow-hidden w-75',
        'off-canvas-body-style' => '',
    ];
    const direction = [
        'left' => 'offcanvas-start',
        'right' => 'offcanvas-end',
        'bottom' => 'offcanvas-bottom',
        'top' => 'offcanvas-top',
    ];

    function __init(): void
    {
        parent::__init();
        // 解析参数传参
        $action_params = $this->getParseVarsParams('action-params');
        $check_fields  = ['action', 'id'];
        $data          = $this->getData();
        foreach ($check_fields as $check_field) {
            $field = $this->getData($check_field) ?: '';
            if (empty($field)) {
                throw new Exception(__('请设置OffCanvas的Block块参数：' . $field . '.示例：%1', $this->doc()));
            }
            if ($check_field === 'action') {
                $action_params['isIframe'] = 'true';
                $field                     = $this->request->isBackend() ? $this->getBackendUrl($field, $action_params) : $this->getUrl($field, $action_params);
            }
            $data[$check_field] = $field;
        }
        if (isset($data['template'])) {
            $this->_template = $data['template'];
        }
        // 默认数据
        foreach (self::default_data as $key => $value) {
            if (str_contains($key, '-text')) {
                $value = __($value);
            }
            $data[$key] = $data[$key] ?? $value;
        }
        $data['class-names'] = $data['class-names'] . ' ' . self::direction[$data['direction']];
        $data                = array_merge(self::default_data, $data);
        foreach ($data as $key => $value) {
            unset($data[$key]);
            $key        = str_replace('-', '_', $key);
            $data[$key] = $value;
        }
        // $data['id']只留下字母和下划线
        $data['id'] = preg_replace('/[^\w]+/', '', $data['id']);
        $data['id'] = $data['id'] . md5(json_encode($data));
        $this->setData($data);
        $this->assign($data);
    }

    public function doc(): string
    {
        return htmlspecialchars($this->tmp_replace('
<h3><lang>OffCanvas组件：侧边栏弹出窗</lang></h3>
<block class="Weline\Component\Block\OffCanvas" 
template="Weline_Component::off-canvas.phtml" 
title=""
cache="0" 
id="demo_off_canvas" 
action="*/demo" 
vars="demo,lang"
target-tag="a"
icon="mdi mdi-eye"
action-params="{code:demo.code,lang:lang.code}"
submit-button-text="保存"
submit-button-class="btn btn-primary"
target-button-text="添加"
target-button-class=""
flush-button-text="刷新"
flush-button-class="btn btn -default"
flush="1"
save="1"
save-form="#demo-form"
close-button-show="1"
close-button-text="取消"
direction="right"
class-names="w-75"
off-canvas-body-style=""
/>
'));
    }

}