<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Controller\Backend\Taglib;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

class Meta extends BackendController
{
    public function get()
    {
        /** @var I18n $i18nModel */
        $i18nModel = ObjectManager::getInstance(I18n::class);
        $localsModel = $i18nModel->getActiveLocalsModel(Cookie::getLangLocal());
        
        if ($search = $this->request->getGet('search', '')) {
            $localsModel->where("concat(" . implode(',', $localsModel->getModelFields()) . ")", '%' . $search . '%', 'like');
        }
        $localsModel->pagination()->select();
        $locals = $localsModel->fetchArray();
        
        if (empty($locals)) {
            $url = $this->request->getUrlBuilder()->getUrl('*/backend/countries');
            MessageManager::error(__('没有找到任何本地化数据！<a target="_blank" href="%{url}">前往I18n安装启用</a>搜索本地语言：%{search} 或者手动刷新页面：<a href="%{refresh}">刷新</a>', [
                'search' => $search,
                'url' => $url,
                'refresh' => $this->request->getUrlBuilder()->getCurrentUrl()
            ]));
            $this->redirect(404);
        }
        
        $this->assign('local_pagination', $localsModel->getPagination());
        
        $metaKey = $this->request->getGet('key');
        if (empty($metaKey)) {
            MessageManager::error(__('请设置meta标签key属性！'));
            $this->redirect(404);
        }
        
        $value = $this->request->getGet('value', '');
        $scope = $this->request->getGet('scope', 'default');
        
        // 构建带 scope 的 meta key
        $metaKeyWithScope = $metaKey;
        if (!empty($scope) && $scope !== 'default') {
            $metaKeyWithScope = $metaKey . '|scope:' . $scope;
        }
        
        // 获取所有语言的翻译
        $translations = [];
        foreach ($locals as $local) {
            $locale = $local['code'];
            /** @var LocaleDictionary $localeDict */
            $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
            
            // 先尝试带 scope 的 key
            $md5 = LocaleDictionary::generateMd5($metaKeyWithScope, $locale);
            $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
            
            $translation = '';
            if ($localeDict->getId()) {
                $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
            }
            
            // 如果没有找到，尝试不带 scope 的 key（使用默认值）
            if (empty($translation) && $scope !== 'default') {
                $md5Default = LocaleDictionary::generateMd5($metaKey, $locale);
                $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5Default);
                if ($localeDict->getId()) {
                    $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                }
            }
            
            $translations[] = [
                'local_code' => $locale,
                'local' => $local,
                'translate' => $translation ?: $value,
                'md5' => $md5
            ];
        }
        
        $this->assign('translations', $translations);
        $this->assign('meta_key', $metaKey);
        $this->assign('meta_key_with_scope', $metaKeyWithScope);
        $this->assign('value', $value);
        $this->assign('scope', $scope);
        $this->assign('field', 'translate');
        
        $params = $this->request->getGet();
        $action = $this->request->getUrlBuilder()->getBackendUrl('meta/backend/taglib/meta', $params);
        $this->assign('action', $action);
        
        return $this->fetch();
    }

    public function post()
    {
        $metaKey = $this->request->getGet('key');
        if (empty($metaKey)) {
            MessageManager::error(__('请设置meta标签key属性！'));
            $this->redirect(404);
        }
        
        $value = $this->request->getGet('value', '');
        $scope = $this->request->getGet('scope', 'default');
        
        // 构建带 scope 的 meta key
        $metaKeyWithScope = $metaKey;
        if (!empty($scope) && $scope !== 'default') {
            $metaKeyWithScope = $metaKey . '|scope:' . $scope;
        }
        
        // 获取翻译数据
        $translations = $this->request->getPost('translation', []);
        
        // 保存翻译（使用带 scope 的 key）
        foreach ($translations as $translation) {
            $locale = $translation['local_code'] ?? '';
            $translateValue = $translation['translate'] ?? '';
            
            if (empty($locale)) {
                continue;
            }
            
            /** @var LocaleDictionary $localeDict */
            $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
            $md5 = LocaleDictionary::generateMd5($metaKeyWithScope, $locale);
            $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
            
            if ($localeDict->getId()) {
                // 更新
                $localeDict->setData(LocaleDictionary::schema_fields_TRANSLATE, $translateValue);
                $localeDict->save();
            } else {
                // 新增
                $localeDict->setData(LocaleDictionary::schema_fields_MD5, $md5);
                $localeDict->setData(LocaleDictionary::schema_fields_STRING, $metaKeyWithScope);
                $localeDict->setData(LocaleDictionary::schema_fields_LOCALE, $locale);
                $localeDict->setData(LocaleDictionary::schema_fields_TRANSLATE, $translateValue);
                $localeDict->save();
            }
        }
        
        MessageManager::success(__('翻译完成!'));
        return $this->get();
    }
}

