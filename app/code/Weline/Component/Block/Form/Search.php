<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/24 23:00:45
 */

namespace Weline\Component\Block\Form;

use PHPUnit\Framework\Exception;
use Weline\Component\ComponentInterface;

class Search extends \Weline\Framework\View\Block implements ComponentInterface
{
    protected string $_template = 'Weline_Component::form/search.phtml';

    public function __init(): void
    {
        parent::__init();
        // 解析参数传参
        $action_params = $this->getParseVarsParams('var-params');
        $check_fields = ['action', 'id'];
        $data         = $this->getData();
        foreach ($check_fields as $check_field) {
            $field = $this->getData($check_field) ?: '';
            if (empty($field)) {
                throw new Exception(__('请设置搜索Block块参数：' . $field . '.示例：%1', $this->doc()));
            }
            if ($check_field === 'action') {
                $field = $this->request->isBackend() ? $this->getBackendUrl($field) : $this->getUrl($field);
            }
            $data[$check_field] = $field;
        }
        if (isset($data['template'])) {
            $this->_template = $data['template'];
        }
        $params = $this->getData('params') ?? [];
        if ($params) {
            $params = explode(',', $params);
            foreach ($params as $key=>$param) {
                unset($params[$key]);
                $params[$param]  = $this->request->getParam($param);
            }
        }else {
            $params = [];
        }

        $data['params']     = array_merge($params, $action_params);
        $data['keyword']     = $data['keyword'] ?? 'keyword';
        $data['method']      = $data['method'] ?? 'GET';
        $data['placeholder'] = $data['placeholder'] ??__( '回车搜索');
        $data['value']       = $this->request->getGet($data['keyword']) ?:$data['value']??'';
        $this->assign($data);
    }

    public function doc(): string
    {
        return htmlspecialchars($this->tmp_replace('
<h3><lang>搜索组件：快速构建搜索框</lang></h3>
<p>params：来自请求的参数（需要回填参数到action上时使用）</p>
<p>vars：来自传入的变量列表</p>
<p>var-params：来自传入的变量组成的参数</p>
<block class="Weline\Component\Block\Form\Search" 
template="Weline_Component::form/search.phtml" 
cache="0" 
id="demo_search" 
action="/demo/search" 
params="demo_id,demo_name" 
method="get" 
keyword="keyword" 
value="Demo Keyword" 
vars="demo,req" 
var-params="{demo_id:demo.id,request_name:req.keyword}" 
placeholder="Please input keywords"/>
'));
    }
}
