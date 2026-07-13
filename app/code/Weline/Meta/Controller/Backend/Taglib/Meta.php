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
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;

class Meta extends BackendController
{
    public function get()
    {
        $displayLocale = Cookie::getLangLocal();
        $search = trim((string)$this->request->getGet('search', ''));
        $locals = [];
        foreach ($this->localeRepository()->installedActive($displayLocale) as $locale) {
            if ($search !== ''
                && stripos($locale->code, $search) === false
                && stripos($locale->displayName, $search) === false) {
                continue;
            }
            $locals[] = [
                'code' => $locale->code,
                'target_code' => $locale->displayLocale,
                'name' => $locale->displayName,
                'flag' => $locale->flag,
                'is_active' => $locale->active ? 1 : 0,
                'is_install' => $locale->installed ? 1 : 0,
            ];
        }
        
        if (empty($locals)) {
            $url = $this->request->getUrlBuilder()->getUrl('*/backend/countries');
            MessageManager::error(__('没有找到任何本地化数据！<a target="_blank" href="%{url}">前往I18n安装启用</a>搜索本地语言：%{search} 或者手动刷新页面：<a href="%{refresh}">刷新</a>', [
                'search' => $search,
                'url' => $url,
                'refresh' => $this->request->getUrlBuilder()->getCurrentUrl()
            ]));
            $this->redirect(404);
        }
        
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
        $dictionary = $this->dictionaryRepository();
        foreach ($locals as $local) {
            $locale = $local['code'];
            
            // 先尝试带 scope 的 key
            $resolvedKey = $metaKeyWithScope;
            $translation = $dictionary->getEntry($resolvedKey, $locale)?->translation ?? '';
            
            // 如果没有找到，尝试不带 scope 的 key（使用默认值）
            if (empty($translation) && $scope !== 'default') {
                $resolvedKey = $metaKey;
                $translation = $dictionary->getEntry($resolvedKey, $locale)?->translation ?? '';
            }
            
            $translations[] = [
                'local_code' => $locale,
                'local' => $local,
                'translate' => $translation ?: $value,
                // 保留旧模板数据形状；字典指纹的存储细节不再用于读写。
                'md5' => md5($resolvedKey . $locale)
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
        $dictionary = $this->dictionaryRepository();
        
        // 保存翻译（使用带 scope 的 key）
        foreach ($translations as $translation) {
            $locale = $translation['local_code'] ?? '';
            $translateValue = $translation['translate'] ?? '';
            
            if (empty($locale)) {
                continue;
            }
            
            $dictionary->upsert($metaKeyWithScope, $locale, $translateValue);
        }
        
        MessageManager::success(__('翻译完成!'));
        return $this->get();
    }

    private function dictionaryRepository(): DictionaryRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$provider instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }

        return $provider;
    }

    private function localeRepository(): LocaleRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(LocaleRepositoryInterface::class);
        if (!$provider instanceof LocaleRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n locale repository provider is unavailable.');
        }

        return $provider;
    }
}
