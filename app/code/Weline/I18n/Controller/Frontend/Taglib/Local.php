<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/2 13:39:02
 */

namespace Weline\I18n\Controller\Frontend\Taglib;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

class Local extends \Weline\Framework\App\Controller\FrontendController
{
    public function get()
    {
        /**@var I18n $i18nModel */
        $i18nModel = ObjectManager::getInstance(I18n::class);
        $localsModel = $i18nModel->getActiveLocalsModel(Cookie::getLangLocal());
        if ($search = $this->request->getGet('search')) {
            $localsModel->where("concat(" . implode(',', $localsModel->getModelFields()) . ")", '%' . $search . '%', 'like');
        }
        $localsModel->pagination()->select();
        $locals = $localsModel->fetchArray();
        $this->assign('local_pagination', $localsModel->getPagination());
        $modelName = $this->request->getGet('model');
        if (empty($modelName)) {
            $this->getMessageManager()->addError(__('请设置local标签model属性！'));
            $this->redirect(404);
        }
        $value = $this->request->getGet('value');
        if (empty($value)) {
            $this->getMessageManager()->addError(__('请传输local标签值！'));
            $this->redirect(404);
        }
        $field = $this->request->getGet('field');
        if (empty($field)) {
            $this->getMessageManager()->addError(__('请选择一个字段！'));
            $this->redirect(404);
        }
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $this->getMessageManager()->addError(__('请设置local标签id属性！'));
            $this->redirect(404);
        }
        /**@var \Weline\I18n\LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        $local_codes = [];
        foreach ($locals as $local) {
            $local_codes[] = $local['code'];
            $model->where($model::fields_local_code, $local['code'], '=', 'or');
        }
        # TODO 读取翻译后的文本
        $local_descriptions = $model->reset()
            ->where($model::fields_ID, $id)
            ->select()
            ->fetchArray();
        foreach ($locals as $local) {
            $in_ = false;
            foreach ($local_descriptions as &$local_description) {
                if ($local_description[$model::fields_local_code] == $local['code']) {
                    $local_description['local'] = $local;
                    $in_ = true;
                    continue;
                }
            }
            if (!$in_) {
                $local_descriptions[] = [
                    $model::fields_local_code => $local['code'],
                    $field => $value,
                    $model::fields_ID => $id,
                    'local' => $local
                ];
            }
        }
        $this->assign('local_descriptions', $local_descriptions);
        $this->assign('translate_field', $field);
        $this->assign('id_field', $model::fields_ID);
        $this->assign('value', $value);
        $this->assign('id', $id);
        $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
        return $this->fetch();
    }

    public function post()
    {
        $modelName = $this->request->getGet('model');
        if (empty($modelName)) {
            $this->getMessageManager()->addError(__('请设置local标签model属性！'));
            $this->redirect(404);
        }
        $value = $this->request->getGet('value');
        if (empty($value)) {
            $this->getMessageManager()->addError(__('请传输local标签值！'));
            $this->redirect(404);
        }
        $field = $this->request->getGet('field');
        if (empty($field)) {
            $this->getMessageManager()->addError(__('请选择一个字段！'));
            $this->redirect(404);
        }
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $this->getMessageManager()->addError(__('请设置local标签id属性！'));
            $this->redirect(404);
        }
        # 更新翻译
        $descriptions = $this->request->getPost('description');
        $insertDesriptions = [];
        foreach ($descriptions as $description) {
            $insertDesriptions[] = $description;
        }
        /**@var \Weline\I18n\LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
//        dd($model->reset()->insert($insertDesriptions,'eav_entity_id,local_code',$field)->getPrepareSql());
        $model->reset()->insert($insertDesriptions, 'eav_entity_id,local_code', $field)->fetch();
        $this->getMessageManager()->addSuccess(__('翻译完成!'));
        return $this->get();
    }
}