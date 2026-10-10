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

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
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
        // Always keep a clean Locale handle for lifecycle / mutation POSTs.
        // Listing joins are request-scoped clones — never bind the OM singleton.
        $this->locale = (clone $locale)->clear();
        $this->i18n = $i18n;

        $currentLang = (string)Cookie::getLangLocal();
        $target_locale = [
            'code' => $currentLang,
            'name' => $currentLang,
            'flag' => '',
        ];

        try {
            // Clone before joinModel: the injected Locale is often the ObjectManager
            // singleton also used by CountryLocaleLifecycleService. Binding a join
            // query onto that singleton makes lifecycle save() beginTransaction on a
            // different Query than TransactionCoordinator::run owns → 并行事务.
            // Join a Name clone too — joinModel(class-string) bindQuery's the OM singleton.
            $localeJoined = (clone $this->locale)->clear();
            $localeNameJoin = (clone ObjectManager::getInstance(Locale\Name::class))->clear();
            $localeJoined = $localeJoined
                ->joinModel($localeNameJoin, 'ln', 'main_table.code=ln.locale_code')
                ->where('ln.' . Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLang);
            $targetLocale = (clone $localeJoined)->clearQuery()
                ->where(Locale::schema_fields_CODE, $currentLang)
                ->find()
                ->fetch();
            if (!$targetLocale->getId()) {
                $countryCode = '';
                if (preg_match('/_([A-Z]{2})$/', $currentLang, $matches)) {
                    $countryCode = $matches[1];
                } elseif (preg_match('/^([A-Z]{2})_/', $currentLang, $matches)) {
                    $countryCode = $matches[1];
                }

                $flag = '';
                if ($countryCode) {
                    try {
                        $flag = (string)$i18n->getCountryFlag($countryCode, 24, 18);
                    } catch (\Throwable) {
                        $flag = '';
                    }
                }
                if ($flag === '') {
                    try {
                        $flagData = $i18n->getCountryFlagWithLocal($currentLang, 24, 18);
                        if (is_array($flagData) && !empty($flagData['flag'])) {
                            $flag = (string)$flagData['flag'];
                        }
                    } catch (\Throwable) {
                        $flag = '';
                    }
                }

                $target_locale = [
                    'code' => $currentLang,
                    'name' => (string)$i18n->getLocaleName($currentLang, $currentLang),
                    'flag' => $flag,
                ];
            } else {
                $target_locale = $targetLocale->getData();
                $target_locale['name'] = $targetLocale->getData(Locale\Name::schema_fields_DISPLAY_NAME)
                    ?: (string)$i18n->getLocaleName($currentLang, $currentLang);
                $countryCode = (string)$targetLocale->getData(Locale::schema_fields_COUNTRY_CODE);
                try {
                    $target_locale['flag'] = $countryCode !== ''
                        ? ((string)$i18n->getCountryFlag($countryCode, 24, 18) ?: '')
                        : '';
                } catch (\Throwable) {
                    $target_locale['flag'] = '';
                }
            }
        } catch (\Throwable) {
            // Mutation POSTs must not fail constructing target_locale chrome.
        }

        $this->assign('target_locale', $target_locale);
    }

    /**
     * I18n 后台动作统一支持 bin-query 异步提交。
     * 普通表单仍保留 redirect，方便旧入口和无脚本场景继续工作。
     */
    protected function isAsyncRequest(): bool
    {
        $accept = strtolower((string)($this->request->getHeader('Accept') ?? ''));
        return $this->request->isAjax() || str_contains($accept, 'application/json');
    }

    protected function asyncJsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
