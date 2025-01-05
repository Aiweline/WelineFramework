<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:37:13
 */

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Cache\PhraseCache;
use Weline\I18n\Cache\I18nCache;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;

class Countries extends BaseController
{
    private \Weline\I18n\Model\Countries $countries;
    /**
     * @var \Weline\I18n\Model\Countries\Locale\Name
     */
    private Name $localeNames;

    public function __construct(
        Locale                       $locale,
        I18n                         $i18n,
        \Weline\I18n\Model\Countries $countries,
        Name                         $localeName
    )
    {
        parent::__construct($locale, $i18n);
        $this->countries = $countries
            ->joinModel(Name::class, 'cln', 'main_table.code=cln.' . Name::fields_COUNTRY_CODE, 'left');
        $this->localeNames = $localeName;
    }

    public function __init()
    {
        parent::__init();
        if ($search = $this->request->getGet('search')) {
            $search = $this->countries->getConnection()->getConnector()->quote($search);
            $search = trim($search, '\'');
            $code = $this->countries::fields_CODE;
            $name = Name::fields_DISPLAY_NAME;
            $this->countries->concat_like("main_table.{$code},cln.{$name}", "%{$search}%");
        }
        $this->countries->where('cln.' . $this->localeNames::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal());
    }

    public function index()
    {
        // 已安装国家
        $installed_countries = $this->countries
            ->where(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 1)
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('countries', $installed_countries->getItems());
        $this->assign('countries_pagination', $installed_countries->getPagination());
        return $this->fetch();
    }

    /**
     * @DESC          # 更新国家
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/22 15:57
     * 参数区：
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function getUpdate()
    {
        $countries = $this->i18n->getCountries(Cookie::getLangLocal());
        $insert_countries = [];
        $insert_countries_display = [];
        foreach ($countries as $code => $country) {
            $insert_countries[] = [
                \Weline\I18n\Model\Countries::fields_CODE => $code,
                \Weline\I18n\Model\Countries::fields_FLAG => (string)$this->i18n->getCountryFlag($code),
                \Weline\I18n\Model\Countries::fields_IS_ACTIVE => 0,
                \Weline\I18n\Model\Countries::fields_IS_INSTALL => 0,
            ];
            $insert_countries_display[] = [
                Name::fields_COUNTRY_CODE => $code,
                Name::fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                Name::fields_DISPLAY_NAME => $country,
            ];
        }
        $this->countries->beginTransaction();
        try {
            // 安装国家数据
            $this->countries->clearQuery()->insert($insert_countries, \Weline\I18n\Model\Countries::fields_CODE)->fetch();
            // 安装显示数据
            $this->localeNames->insert($insert_countries_display, [
                $this->localeNames::fields_COUNTRY_CODE,
                $this->localeNames::fields_DISPLAY_LOCALE_CODE,
                $this->localeNames::fields_DISPLAY_NAME
            ])->fetch();
            $this->countries->commit();
            Message::success(__('操作成功！更新%1记录。', count($insert_countries)));
        } catch (\Exception $exception) {
            $this->countries->rollBack();
            Message::exception($exception);
        }
        $this->redirect($this->request->getUrlBuilder()->getBackendUrl('*/backend/countries'));
    }

    public function install()
    {
        if ($this->request->isGet()) {
            $this->countries
                ->pagination()
                ->select()
                ->fetch();
            $this->assign('countries', $this->countries->getItems());
            $this->assign('pagination', $this->countries->getPagination());
            return $this->fetch();
        }
        if ($this->request->isPost()) {
            $code = $this->request->getPost('code');
            try {
                $this->countries->clearQuery();
                $this->countries->load($this->countries::fields_CODE, $code);
                if ($this->countries->getId()) {
                    $this->countries->setData($this->countries::fields_IS_INSTALL, 1)->save(true);
                    $this->getMessageManager()->addSuccess(__('成功安装!国家：%1(%2)', [$this->countries->getData($this->localeNames::fields_DISPLAY_NAME),
                        $this->countries->getData($this->countries::fields_CODE)]));
                } else {
                    $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%1', $code));
                }
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/countries/install');
        } else {
            $this->getMessageManager()->addError(__('请求错误！'));
            $this->redirect(404);
        }
    }

    public function postUninstall()
    {
        $code = $this->request->getPost('code');
        try {
            $this->countries->clearQuery()->where($this->countries::fields_CODE, $code)
                ->find()
                ->fetch();
            if ($this->countries->getId()) {
                $this->countries->setData($this->countries::fields_IS_INSTALL, 0)->save(true);
                $this->countries->getLocaleModel()->where(Locale::fields_COUNTRY_CODE, $code)
                    ->update([Locale::fields_IS_INSTALL => 0, Locale::fields_IS_ACTIVE => 0])
                    ->fetch();
                $this->getMessageManager()->addSuccess(__('成功卸载!国家：%1(%2)', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                    $this->countries->getData($this->countries::fields_CODE)]));
            } else {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%1', $code));
            }
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries');
    }

    public function postActive()
    {
        $code = $this->request->getPost('code');
        if (!$code) {
            $this->getMessageManager()->addWarning(__('请选择国家激活！'));
            $this->redirect('*/backend/countries');
        }
        try {
            $this->countries->clearQuery()->load($this->countries::fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%1', $code));
                $this->redirect('*/backend/countries');
            }
            $this->countries->setData($this->countries::fields_IS_ACTIVE, 1)->save(true);
            $this->getMessageManager()->addSuccess(__('成功激活国家！国家：%1（%2）', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::fields_CODE)]));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries');
    }

    public function postDisable()
    {
        $code = $this->request->getPost('code');
        if (!$code) {
            $this->getMessageManager()->addWarning(__('请选择国家禁用！'));
            $this->redirect('*/backend/countries');
        }
        try {
            $this->countries->clearQuery();
            $this->countries->load($this->countries::fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%1', $code));
                $this->redirect('*/backend/countries');
            }
            $this->countries->setData($this->countries::fields_IS_ACTIVE, 0)->save(true);
            $this->getMessageManager()->addSuccess(__('成功禁用国家！国家：%1（%2）', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::fields_CODE)]));
            // FIXME 禁用应当删除对应语言的翻译包
            $country_locales = $this->locale->where($this->locale::fields_COUNTRY_CODE, $code)->select()->fetch()->getItems();
            $pack_dir = Env::path_LANGUAGE_PACK;
            /**@var System $system */
            $system = ObjectManager::getInstance(System::class);
            /**@var */
            foreach ($country_locales as $country_locale) {
                $locale_dirs = glob($pack_dir . '*' . DS . $country_locale->getData($this->locale::fields_ID), GLOB_ONLYDIR);
                foreach ($locale_dirs as $locale_dir) {
                    $result = $system->exec('rm -rf ' . $locale_dir);
                }
            }
            // 清理i18n缓存
            /**@var \Weline\Framework\Cache\CacheInterface $i18n */
            $i18n = ObjectManager::getInstance(I18nCache::class . 'Factory');
            $i18n->clear();
            /**@var \Weline\Framework\Cache\CacheInterface $phrase */
            $phrase = ObjectManager::getInstance(PhraseCache::class . 'Factory');
            $phrase->clear();
            $this->getMessageManager()->addWarning(__('该国家下的所有安装包已删除！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries');
    }
}
