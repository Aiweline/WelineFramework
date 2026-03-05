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
            ->where('ln.' . Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal());
        $this->locale = $locale;
        $targetLocale = clone $locale;
        $targetLocale = $targetLocale->clearQuery()
            ->where($locale::schema_fields_CODE, Cookie::getLangLocal())
            ->find()
            ->fetch();
        if (!$targetLocale->getId()) {
            $currentLang = Cookie::getLangLocal();
            // 尝试从语言代码中提取国家代码（例如：zh_Hans_CN -> CN）
            $countryCode = '';
            if (preg_match('/_([A-Z]{2})$/', $currentLang, $matches)) {
                $countryCode = $matches[1];
            } elseif (preg_match('/^([A-Z]{2})_/', $currentLang, $matches)) {
                $countryCode = $matches[1];
            }
            
            $flag = '';
            if ($countryCode) {
                try {
                    $flag = $i18n->getCountryFlag($countryCode, 24, 18);
                    if (empty($flag)) {
                        throw new \Exception('Empty flag');
                    }
                } catch (\Exception $e) {
                    // 如果获取失败，尝试使用 getCountryFlagWithLocal
                    try {
                        $flagData = $i18n->getCountryFlagWithLocal($currentLang, 24, 18);
                        if (is_array($flagData) && isset($flagData['flag']) && !empty($flagData['flag'])) {
                            $flag = $flagData['flag'];
                        }
                    } catch (\Exception $e2) {
                        // 静默失败，使用空字符串
                        $flag = '';
                    }
                }
            } else {
                // 如果无法提取国家代码，尝试使用 getCountryFlagWithLocal
                try {
                    $flagData = $i18n->getCountryFlagWithLocal($currentLang, 24, 18);
                    if (is_array($flagData) && isset($flagData['flag']) && !empty($flagData['flag'])) {
                        $flag = $flagData['flag'];
                    }
                } catch (\Exception $e) {
                    // 静默失败，使用空字符串
                    $flag = '';
                }
            }
            
            $target_locale = [
                'code' => $currentLang,
                'name' => $i18n->getLocaleName($currentLang, $currentLang),
                'flag' => $flag,
            ];
        } else {
            $target_locale = $targetLocale->getData();
            $target_locale['name'] = $targetLocale->getData(Locale\Name::schema_fields_DISPLAY_NAME) ?: $i18n->getLocaleName(Cookie::getLangLocal(), Cookie::getLangLocal());
            // 获取国家代码并获取国旗
            $countryCode = $targetLocale->getData($locale::schema_fields_COUNTRY_CODE);
            if ($countryCode) {
                try {
                    $target_locale['flag'] = $i18n->getCountryFlag($countryCode, 24, 18) ?: '';
                } catch (\Exception $e) {
                    $target_locale['flag'] = '';
                }
            } else {
                $target_locale['flag'] = '';
            }
        }
        $this->assign('target_locale', $target_locale);
        $this->i18n   = $i18n;
    }
}
