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

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

class Local extends \Weline\Framework\App\Controller\FrontendController
{
    public function get()
    {
        // 没有开启实时翻译，不能访问
        if (Env::get('i18n.translate_mode') !== 'online') {
            MessageManager::add_error(__('没有开启实时翻译，不能访问！'));
            $this->redirect(404);
        }
        /**@var I18n $i18nModel */
        $i18nModel = ObjectManager::getInstance(I18n::class);
        $localsModel = $i18nModel->getActiveLocalsModel(Cookie::getLangLocal());
        if ($search = $this->request->getGet('search')) {
            $localsModel->where("concat(" . implode(',', $localsModel->getModelFields()) . ")", '%' . $search . '%', 'like');
        }
        $localsModel->pagination()->select();
        $locals = $localsModel->fetchArray();
        if (empty($locals)) {
            $url = $this->request->getUrlBuilder()->getUrl('*/backend/countries');
            $this->getMessageManager()->addError(__('没有找到任何本地化数据！<a target="_blank" href="%{url}">前往I18n安装启用</a>搜索本地语言：%{search} 或者手动刷新页面：<a href="%{refresh}">刷新</a>', [
                'search'=>$search,
                'url'=>$url,
                'refresh'=>$this->request->getUrlBuilder()->getCurrentUrl()
            ]));
            $this->redirect(404);
        }
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
        
        // 判断字段是否是 config 嵌套字段（如：config.demo.title）
        $isConfigField = str_starts_with($field, 'config.');
        $configPath = $isConfigField ? substr($field, 7) : ''; // 去掉 "config." 前缀
        
        /**@var \Weline\I18n\LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
//        $local_codes = [];
//        foreach ($locals as $local) {
//            $local_codes[] = $local['code'];
//            $model->where($model::schema_fields_local_code, $local['code'], '=', 'or');
//        }
        $local_descriptions = $model->reset()
            ->where($model::schema_fields_ID, $id)
            ->select()
            ->fetchArray();
        
        // 处理 config 嵌套字段的数据提取
        if ($isConfigField) {
            foreach ($local_descriptions as &$local_description) {
                $configData = isset($local_description['config']) ? json_decode($local_description['config'], true) : [];
                $local_description[$field] = $this->getNestedValue($configData, $configPath) ?? $value;
            }
            unset($local_description);
        }
        
        foreach ($locals as $local) {
            $in_ = false;
            foreach ($local_descriptions as &$local_description) {
                if ($local_description[$model::schema_fields_local_code] == $local['code']) {
                    $local_description['local'] = $local;
                    $in_ = true;
                    continue;
                }
            }
            if (!$in_) {
                $local_descriptions[] = [
                    $model::schema_fields_local_code => $local['code'],
                    $field => $value,
                    $model::schema_fields_ID => $id,
                    'local' => $local
                ];
            }
        }
        $this->assign('local_descriptions', $local_descriptions);
        $this->assign('translate_field', $field);
        $this->assign('id_field', $model::schema_fields_ID);
        $this->assign('value', $value);
        $this->assign('id', $id);
        $params = $this->request->getGet();
        $action = $this->request->getUrlBuilder()->getUrl('i18n/frontend/taglib/local', $params);
        $this->assign('action', $action);
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
        
        // 判断字段是否是 config 嵌套字段
        $isConfigField = str_starts_with($field, 'config.');
        $configPath = $isConfigField ? substr($field, 7) : '';
        
        # 更新翻译
        $descriptions = $this->request->getPost('description');
        $insertDesriptions = [];
        
        /**@var \Weline\I18n\LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        
        if ($isConfigField) {
            // 处理 config 嵌套字段：需要更新 JSON 数据
            foreach ($descriptions as $description) {
                // 获取现有的 config 数据
                $existingModel = clone $model;
                $existingModel->clear()
                    ->where($model::schema_fields_ID, $description[$model::schema_fields_ID])
                    ->where($model::schema_fields_local_code, $description[$model::schema_fields_local_code])
                    ->find()
                    ->fetch();
                
                $configData = [];
                if ($existingModel->getId()) {
                    $existingConfig = $existingModel->getData('config');
                    $configData = $existingConfig ? json_decode($existingConfig, true) : [];
                }
                
                // 设置嵌套值
                $configData = $this->setNestedValue($configData, $configPath, $description[$field]);
                
                // 更新描述数据
                $description['config'] = json_encode($configData, JSON_UNESCAPED_UNICODE);
                unset($description[$field]); // 移除虚拟字段
                $insertDesriptions[] = $description;
            }
            
            // 使用 config 字段更新
            $model->reset()->insert($insertDesriptions, $model::schema_fields_ID . ',local_code', 'config')->fetch();
        } else {
            // 处理普通字段
            foreach ($descriptions as $description) {
                $insertDesriptions[] = $description;
            }
            $model->reset()->insert($insertDesriptions, $model::schema_fields_ID . ',local_code', $field)->fetch();
        }
        
        $this->getMessageManager()->addSuccess(__('翻译完成!'));
        return $this->get();
    }
    
    /**
     * 从嵌套数组中获取值
     * 
     * @param array $data
     * @param string $path 点号分隔的路径，如：demo.title
     * @return mixed|null
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    /**
     * 在嵌套数组中设置值
     * 
     * @param array $data
     * @param string $path 点号分隔的路径，如：demo.title
     * @param mixed $value
     * @return array
     */
    private function setNestedValue(array $data, string $path, $value): array
    {
        $keys = explode('.', $path);
        $current = &$data;
        
        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
        
        return $data;
    }
}