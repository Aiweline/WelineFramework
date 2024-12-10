<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 15:14:45
 */

namespace Weline\I18n\Controller\Backend;

use Symfony\Component\Intl\Locales;
use Weline\Framework\Http\Cookie;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;

class BaseController extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * @var \Weline\I18n\Model\Locale
     */
    protected Locale $locale;
    /**
     * @var \Weline\I18n\Model\I18n
     */
    protected I18n $i18n;

    public function __construct(
        Locale $locale,
        I18n   $i18n
    )
    {
        $cache_key     = 'CurrentLang' . Cookie::getLangLocal();
        $target_locale = $i18n->i18nCache->get($cache_key);
        if (!$target_locale) {
            $locale = $locale->joinModel(Locale\Name::class, 'lln', 'main_table.code=lln.locale_code')
                             ->where('lln.' . Locale\Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
                             ->where($locale::fields_CODE, Cookie::getLangLocal())
                             ->find()
                             ->fetch();
            if (!$locale->getId()) {
                $target_locale = [
                    'code' => Cookie::getLangLocal(),
                    'name' => $i18n->getLocaleName(Cookie::getLangLocal(), Cookie::getLangLocal()),
                ];
            } else {
                $target_locale         = $locale->getData();
                $target_locale['name'] = $locale->getData(Locale\Name::fields_DISPLAY_NAME);
            }
            $i18n->i18nCache->set($cache_key, $target_locale);
        }
        $this->assign('target_locale', $target_locale);
        $this->locale = $locale;
        $this->i18n   = $i18n;
    }
}
