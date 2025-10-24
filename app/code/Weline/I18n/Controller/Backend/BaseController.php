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
        // 尝试使用JOIN查询，如果失败则使用基本查询
        $locale = $locale->joinModel(Locale\Name::class, 'ln', 'main_table.code=ln.locale_code')
            ->where('ln.' . Locale\Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal());
        $this->locale = $locale;
        $targetLocale = clone $locale;
        $targetLocale = $targetLocale->where($locale::fields_CODE, Cookie::getLangLocal())
        ->find()
        ->fetch();
        if (!$targetLocale->getId()) {
            $target_locale = [
                'code' => Cookie::getLangLocal(),
                'name' => $i18n->getLocaleName(Cookie::getLangLocal(), Cookie::getLangLocal()),
            ];
        } else {
            $target_locale         = $targetLocale->getData();
            $target_locale['name'] = $locale->getData(Locale\Name::fields_DISPLAY_NAME);
        }
        $this->assign('target_locale', $target_locale);
        $this->i18n   = $i18n;
    }
}
