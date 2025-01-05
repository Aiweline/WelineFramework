<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/23 22:28:53
 */

namespace Weline\I18n\Controller\Backend\Countries;

use Symfony\Component\Intl\Countries;
use Weline\Framework\Http\Cookie;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name;

class Locales extends BaseController
{
    /**
     * @var \Weline\I18n\Model\Locale\Name
     */
    private Name $localeName;

    public function __construct(
        Locale $locale,
        I18n   $i18n,
        Name   $localeName
    )
    {
        parent::__construct($locale, $i18n);
        $this->localeName = $localeName;
    }

    public function __init()
    {
        parent::__init();
        $country_code = $this->request->getParam('country_code');
        $this->locale->where('main_table.' . $this->locale::fields_COUNTRY_CODE, $country_code);

        if ($search = $this->request->getParam('search')) {
            $code = $this->locale::fields_CODE;
            $country_code = $this->locale::fields_COUNTRY_CODE;
            $this->locale->where("CONCAT(main_table.{$code},country_name,main_table.{$country_code})", "%{$search}%", 'LIKE');
        }

        $this->locale->where('lln.' . Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->where('cln.' . \Weline\I18n\Model\Countries\Locale\Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->joinModel(
                \Weline\I18n\Model\Countries::class,
                'c',
                'main_table.' . $this->locale::fields_COUNTRY_CODE . '=c.' . \Weline\I18n\Model\Countries::fields_CODE,
                'left',
                'c.flag'
            )
            ->joinModel(
                \Weline\I18n\Model\Countries\Locale\Name::class,
                'cln',
                'c.' . \Weline\I18n\Model\Countries::fields_CODE . '=cln.' . \Weline\I18n\Model\Countries\Locale\Name::fields_COUNTRY_CODE,
                'left',
                'cln.' . \Weline\I18n\Model\Countries\Locale\Name::fields_DISPLAY_NAME . ' as country_name'
            )->joinModel(
                Name::class,
                'lln',
                'main_table.' . $this->locale::fields_CODE . '=lln.' . Name::fields_LOCALE_CODE,
                'left',
                'lln.' . Name::fields_DISPLAY_NAME . ' as locale_name'
            )
            ->where('c.' . \Weline\I18n\Model\Countries::fields_CODE, $this->request->getParam('country_code'));
    }

    public function getIndex()
    {
        $this->locale
            ->fields('main_table.*')
            ->pagination()
            ->select()
            ->fetch();
//        p($this->locale->getLastSql());
        $this->assign('locales', $this->locale->getItems());
        $this->assign('pagination', $this->locale->getPagination());
        return $this->fetch();
    }


    public function getUpdate()
    {
        $this->request->checkParam();
        $country_code = $this->request->getGet('country_code');
        $this->request->checkParam(false);
        if (!Countries::exists($country_code)) {
            $this->getMessageManager()->addWarning(__('国家不存在！代码：%1', $country_code));
            $this->redirect('*/backend/countries/locales');
        }
        $country = $this->i18n->getCountry($country_code);
        $locales = $country->getLocales();
        $locales_display = [];
        $this->locale->clearQuery();
        foreach ($locales as $key => $locale) {
            unset($locales[$key]);
            $locales[] = [
                $this->locale::fields_CODE => $locale,
                $this->locale::fields_COUNTRY_CODE => $country_code,
            ];
            $locales_display[] = [
                Name::fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                Name::fields_LOCALE_CODE => $locale,
                Name::fields_DISPLAY_NAME => $this->i18n->getLocaleName($locale, Cookie::getLangLocal()),
            ];
        }
        $this->locale->beginTransaction();
        try {
            // 安装地区
            $result = $this->locale->reset()->insert($locales, $this->locale::fields_CODE)->fetch();
            // 安装地区展示码
            $this->localeName->reset()->insert($locales_display, [
                $this->localeName::fields_LOCALE_CODE,
                $this->localeName::fields_DISPLAY_LOCALE_CODE
            ])->fetch();
            $this->locale->commit();
            $this->getMessageManager()->addSuccess(__('安装国家地区数据成功！'));
        } catch (\Exception $exception) {
            $this->locale->rollBack();
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries/locales', [], true);
    }

    public function postActive()
    {
        $code = $this->request->getPost('code');
        if ($this->i18n->localeExists($code)) {
            try {
                $this->locale->clearQuery();# 清理之前加载的target_locale_code数据
                $this->locale->where($this->locale::fields_CODE, $code)
                    ->setData($this->locale::fields_IS_ACTIVE, 1)
                    ->update()
                    ->fetch();
                $this->getMessageManager()->addSuccess(__('激活成功！'));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
        } else {
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function postDisable()
    {
        $code = $this->request->getPost('code');
        if ($this->i18n->localeExists($code)) {
            try {
                $this->locale->clearQuery();# 清理之前加载的target_locale_code数据
                $this->locale->clearData();# 清理之前加载的target_locale_code数据
                $this->locale->where($this->locale::fields_CODE, $code)
                    ->setData($this->locale::fields_IS_ACTIVE, 0)
                    ->update()
                    ->fetch();
                $this->getMessageManager()->addSuccess(__('禁用成功！'));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
        } else {
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function install()
    {
        $code = $this->request->getPost('code');
        $this->locale->clearQuery();
        $locale = $this->locale->load($code);
        if (!$locale->getId()) {
            $this->getMessageManager()->addWarning(__('该区域不存在！区域代码：%1', $code));
            $this->redirect($this->request->getReferer());
        }
        $flag = $this->i18n->getCountryFlagWithLocal($code, 42);
        $locale->setData($locale::fields_IS_INSTALL, 1)->setData($locale::fields_FLAG, $flag['flag'] ?? '')->save(true);
        $this->getMessageManager()->addSuccess(__('区域已安装！区域代码：%1', $code));
        $this->redirect($this->request->getReferer());
    }

    public function postUninstall()
    {
        $code = $this->request->getPost('code');
        $this->locale->clearQuery();
        $locale = $this->locale->load($code);
        if (!$locale->getId()) {
            $this->getMessageManager()->addWarning(__('该区域不存在！区域代码：%1', $code));
            $this->redirect($this->request->getReferer());
        }
        $locale->setData($locale::fields_IS_INSTALL, 0)->save();
        $this->getMessageManager()->addSuccess(__('区域已卸载！区域代码：%1', $code));
        $this->redirect($this->request->getReferer());
    }
}
