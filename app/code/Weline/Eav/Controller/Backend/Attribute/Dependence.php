<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/7/5 17:07:56
 */

namespace Weline\Eav\Controller\Backend\Attribute;

use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Dependence extends BackendController
{

    public function getIndex()
    {
        $json = ['code' => 200, 'msg' => '获取成功！', 'data' => [], 'query' => $this->request->getGet()];
        $d   = $this->request->getGet('d');
        if (empty($d)) {
            $json['code'] = 403;
            $json['msg']  = __('需要选择依赖属性');
            return $this->fetchJson($json);
        }
        $dv   = $this->request->getGet('dv');
        if (empty($dv)) {
            $json['code'] = 403;
            $json['msg']  = __('需要选择依赖值');
            return $this->fetchJson($json);
        }

        // 当前属性
        $a = $this->request->getGet('a', '');
        if (empty($a)) {
            $json['code'] = 403;
            $json['msg']  = __('需要选择当前属性');
            return $this->fetchJson($json);
        }
        $av = $this->request->getGet('av', '');
        if (empty($av)) {
            $av = '';
        }
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->loadByCode($a);
        if (!$attribute->getId()) {
            $json['code'] = 403;
            $json['msg']  = __('当前属性不存在');
            return $this->fetchJson($json);
        }
        $attributeType = $attribute->getType();
        if (!$attributeType->getId()) {
            $json['code'] = 403;
            $json['msg']  = __('当前属性类型不存在');
            return $this->fetchJson($json);
        }
        # 获取属性类型模型
        $class = $attributeType->getModelClass();
        if (empty($class)) {
            $json['code'] = 200;
            $json['msg']  = __('当前属性类型模型不存在');
            $json['data'] = [];
            return $this->fetchJson($json);
        }
        /**@var EavModelInterface $modelClass */
        $modelClass    = ObjectManager::getInstance($class);
        $json['model'] = $modelClass::class;
        // 如果有方法
        if (method_exists($modelClass, 'dependenceProcess')) {
            $options      = $modelClass::dependenceProcess([
                'dependenceAttribute'=>$d,
                'dependenceAttributeValue'=>$dv,
                'attribute'=>$a,
                'attributeValue'=>$av,
            ]);
            $json['data'] = $options;
            return $this->fetchJson($json);
        }
        $options      = [];
        $json['data'] = $options;
        return $this->fetchJson($json);
    }
}