<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;

#[Acl('Weline_Websites::website', '网站管理', 'mdi mdi-web', '网站管理')]
class Website extends BackendController
{
    private \Weline\Websites\Model\Website $website;

    public function __construct(\Weline\Websites\Model\Website $website)
    {
        $this->website = $website;
    }

    #[Acl('Weline_Websites::website_list', '网站列表', 'mdi mdi-view-list', '网站管理')]
    public function index()
    {
        $websites = $this->website->order()->pagination()->select()->fetch();
        $this->assign('websites', $websites->getItems());
        $this->assign('pagination', $websites->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Websites::website_add', '添加网站', 'mdi mdi-plus', '网站管理')]
    public function add()
    {
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            try {
                $this->website->setData($data)->save();
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站添加成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站添加失败'),
                    'url' => '*/admin/website/add',
                    'reload' => '1',
                    'time' => '3',
                ]);
            }
        }
        // 初始化空网站数据，避免模板中访问未定义变量
        $this->assign('website', []);
        // 安装的语言 - 优先查询当前语言的翻译，如果没有则查询所有激活的语言
        $targetCode = Cookie::getLangLocal();
        $localsModel = ObjectManager::getInstance(Locals::class);
        $locales = $localsModel
            ->clearQuery()
            ->where(Locals::fields_TARGET_CODE, $targetCode)
            ->where(Locals::fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        
        $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        
        // 如果根据 target_code 查不到数据，则查询所有激活的语言（不限制 target_code）
        if (!$locales) {
            $allLocales = $localsModel
                ->clearQuery()
                ->where(Locals::fields_IS_INSTALL, 1)
                ->where(Locals::fields_IS_ACTIVE, 1)
                ->order(Locals::fields_CODE, 'ASC')
                ->select()
                ->fetchArray();
            
            if (!$allLocales) {
                MessageManager::error(__('当前语言没有对应语言包翻译，请前往i18n模块对%{1}语言的地区语言进行更新', $targetCode));
            } else {
                // 按 code 去重，获取唯一的语言代码列表
                $uniqueCodes = [];
                foreach ($allLocales as $locale) {
                    $code = $locale['code'];
                    if (!in_array($code, $uniqueCodes)) {
                        $uniqueCodes[] = $code;
                    }
                }
                
                // 使用 Symfony Intl 获取当前界面语言下的语言名称
                $locales = [];
                foreach ($uniqueCodes as $code) {
                    $locales[] = [
                        'code' => $code,
                        'name' => $i18n->getLocaleName($code, $targetCode),
                        'target_code' => $targetCode,
                        'is_active' => 1,
                        'is_install' => 1
                    ];
                }
            }
        } else {
            // 即使查询成功，也确保名称是当前界面语言下的名称
            foreach ($locales as &$locale) {
                // 如果 target_code 匹配，使用数据库中的名称；否则使用 Symfony Intl 获取
                if ($locale['target_code'] !== $targetCode) {
                    $locale['name'] = $i18n->getLocaleName($locale['code'], $targetCode);
                }
            }
        }
        $this->assign('locales', $locales ?: []);
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        return $this->fetch('form');
    }

    #[Acl('Weline_Websites::website_edit', '编辑网站', 'mdi mdi-pencil', '网站管理')]
    public function edit()
    {
        $websiteId = $this->request->getParam('id');
        $this->website->load($websiteId);

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            try {
                $this->website->addData($data)->save();
                $this->redirect('/component/offcanvas/success',
                    [
                        'msg' => __('网站更新成功'),
                        'url' => '*/admin/website',
                        'reload' => '1',
                        'time' => '3',
                    ]);
            } catch (\Exception $e) {
                MessageManager::exception($e);
            }
        }

        // 安装的语言 - 优先查询当前语言的翻译，如果没有则查询所有激活的语言
        $targetCode = Cookie::getLangLocal();
        $localsModel = ObjectManager::getInstance(Locals::class);
        $locales = $localsModel
            ->clearQuery()
            ->where(Locals::fields_TARGET_CODE, $targetCode)
            ->where(Locals::fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        
        $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        
        // 如果根据 target_code 查不到数据，则查询所有激活的语言（不限制 target_code）
        if (!$locales) {
            $allLocales = $localsModel
                ->clearQuery()
                ->where(Locals::fields_IS_INSTALL, 1)
                ->where(Locals::fields_IS_ACTIVE, 1)
                ->order(Locals::fields_CODE, 'ASC')
                ->select()
                ->fetchArray();
            
            if (!$allLocales) {
                MessageManager::error(__('当前语言没有对应语言包翻译，请前往i18n模块对%{1}语言的地区语言进行更新', $targetCode));
            } else {
                // 按 code 去重，获取唯一的语言代码列表
                $uniqueCodes = [];
                foreach ($allLocales as $locale) {
                    $code = $locale['code'];
                    if (!in_array($code, $uniqueCodes)) {
                        $uniqueCodes[] = $code;
                    }
                }
                
                // 使用 Symfony Intl 获取当前界面语言下的语言名称
                $locales = [];
                foreach ($uniqueCodes as $code) {
                    $locales[] = [
                        'code' => $code,
                        'name' => $i18n->getLocaleName($code, $targetCode),
                        'target_code' => $targetCode,
                        'is_active' => 1,
                        'is_install' => 1
                    ];
                }
            }
        } else {
            // 即使查询成功，也确保名称是当前界面语言下的名称
            foreach ($locales as &$locale) {
                // 如果 target_code 匹配，使用数据库中的名称；否则使用 Symfony Intl 获取
                if ($locale['target_code'] !== $targetCode) {
                    $locale['name'] = $i18n->getLocaleName($locale['code'], $targetCode);
                }
            }
        }
        $this->assign('locales', $locales ?: []);
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        $this->assign('website', $this->website->getData());
        return $this->fetch('form');
    }

    #[Acl('Weline_Websites::website_delete', '删除网站', 'mdi mdi-delete', '网站管理')]
    public function deleteDelete(): string
    {
        $websiteId = $this->request->getGet('id');
        try {
            $this->website->load($websiteId)->delete()->fetch();
            return $this->fetchJson([
                'code' => 200,
                'success' => true,
                'msg' => __('网站删除成功'),
                'reload' => '1',
                'url' => '*/admin/website',
                'time' => '3',
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'code' => 500,
                'msg' => __('网站删除失败'),
            ]);
        }
    }
}


