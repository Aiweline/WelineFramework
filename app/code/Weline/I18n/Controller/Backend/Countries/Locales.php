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
use Weline\Framework\App\Debug;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\Framework\App\Env;
use Weline\I18n\Model\Locale\Name;
use Weline\I18n\Service\CountryLocaleLifecycleService;

class Locales extends BaseController
{
    /**
     * @var \Weline\I18n\Model\Locale\Name
     */
    private Name $localeName;
    private CountryLocaleLifecycleService $lifecycle;

    public function __construct(
        Locale $locale,
        I18n   $i18n,
        Name   $localeName
    )
    {
        parent::__construct($locale, $i18n);
        $this->localeName = $localeName;
        $this->lifecycle = ObjectManager::getInstance(CountryLocaleLifecycleService::class);
    }

    public function __init()
    {
        parent::__init();
        $country_code = $this->request->getParam('country_code');
        // WLS worker 常驻时模型对象可能跨请求复用。列表查询必须使用
        // 请求级模型，避免上一请求的 items/data 参与本次状态判断。
        $this->locale = ObjectManager::make(Locale::class);

        // 先设置基础条件
        $this->locale->where('main_table.' . $this->locale::schema_fields_COUNTRY_CODE, $country_code);
        
        // 先执行joinModel，然后再使用where条件引用join的表
        $this->locale->joinModel(
                \Weline\I18n\Model\Countries::class,
                'c',
                'main_table.' . $this->locale::schema_fields_COUNTRY_CODE . '=c.' . \Weline\I18n\Model\Countries::schema_fields_CODE,
                'left',
                'c.flag'
            )
            ->joinModel(
                \Weline\I18n\Model\Countries\Locale\Name::class,
                'cln',
                'c.' . \Weline\I18n\Model\Countries::schema_fields_CODE . '=cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_COUNTRY_CODE,
                'left',
                'cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_NAME . ' as country_name'
            )->joinModel(
                Name::class,
                'lln',
                'main_table.' . $this->locale::schema_fields_CODE . '=lln.' . Name::schema_fields_LOCALE_CODE,
                'left',
                'lln.' . Name::schema_fields_DISPLAY_NAME . ' as locale_name'
            );
        
        // joinModel之后才能使用where条件引用join的表
        $this->locale->where('lln.' . Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->where('cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->where('c.' . \Weline\I18n\Model\Countries::schema_fields_CODE, $country_code);

        // 搜索条件（在join之后才能使用country_name）
        if ($search = $this->request->getParam('search')) {
            $code = $this->locale::schema_fields_CODE;
            $country_code_field = $this->locale::schema_fields_COUNTRY_CODE;
            $this->locale->where("CONCAT(main_table.{$code},cln." . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_NAME . ",main_table.{$country_code_field})", "%{$search}%", 'LIKE');
        }
    }

    public function getIndex()
    {
        // 执行查询
        $query_copy = clone $this->locale;
        $locales_result = $this->locale
            ->fields('main_table.*')
            ->pagination()
            ->select()
            ->fetch();
            
        // 如果没有实际行才自动更新区域数据。AbstractModel 没有真正的
        // getTotal() 方法，调用它会退化为读取一个不存在的 data 字段，
        // 导致每次刷新都误判为空并用默认 is_install=0 覆盖已安装状态。
        if (empty($locales_result->getItems())) {
            $this->autoUpdateLocaleData();
            // 重新查询
            $locales_result = $query_copy
                ->fields('main_table.*')
                ->pagination()
                ->select()
                ->fetch();
        }
        
        // 每次访问都自动更新区域名称数据（确保当前语言的显示名称存在）
        $this->autoUpdateMissingLocaleNames();
        
//        p($this->locale->getLastSql());
        $this->assign('locales', $locales_result->getItems());
        $this->assign('pagination', $locales_result->getPagination());
        // The view intentionally uses getIndex.phtml to distinguish the
        // locale listing from the country listing. The implicit action view
        // resolver looks for index.phtml, so select the template explicitly.
        return $this->fetch('getIndex');
    }


    public function getUpdate()
    {
        $isJsonRequest = $this->isJsonRequest();
        $this->request->checkParam();
        $country_code = (string)$this->request->getGet('country_code', '');
        $this->request->checkParam(false);
        if (!Countries::exists($country_code)) {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('国家不存在！代码：%{1}', $country_code));
            }
            $this->getMessageManager()->addWarning(__('国家不存在！代码：%{1}', $country_code));
            return $this->redirect('*/backend/countries/locales');
        }
        try {
            // 仅补齐缺失目录和名称，不能用默认状态 0 全量 upsert 覆盖已安装/已激活状态。
            $this->autoUpdateLocaleData();
            $this->autoUpdateMissingLocaleNames();
            $localeCount = count($this->lifecycle->getLocaleCodesByCountry($country_code));
            $message = (string)__('国家地区数据同步完成！共 %{1} 个地区。', $localeCount);
            if ($isJsonRequest) {
                return $this->jsonActionResponse(true, $message, [
                    'country_code' => $country_code,
                    'locale_count' => $localeCount,
                ]);
            }
            $this->getMessageManager()->addSuccess($message);
        } catch (\Throwable $exception) {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, $exception->getMessage());
            }
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries/locales', [], true);
    }

    public function postActive()
    {
        $code = (string)$this->request->getPost('code', '');
        $isJsonRequest = $this->isJsonRequest();
        if ($this->i18n->localeExists($code)) {
            try {
                $summary = $this->lifecycle->activateLocale($code);
                $message = (string)__('区域已激活！区域代码：%{1}', $code);
                if ($isJsonRequest) {
                    return $this->jsonActionResponse(true, $message, $summary);
                }
                $this->getMessageManager()->addSuccess($message);
            } catch (\Exception $exception) {
                if ($isJsonRequest) {
                    return $this->jsonActionResponse(false, $exception->getMessage());
                }
                $this->getMessageManager()->addException($exception);
            }
        } else {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('地区已经不存在！'));
            }
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function postDisable()
    {
        $code = (string)$this->request->getPost('code', '');
        $isJsonRequest = $this->isJsonRequest();
        if ($code === '') {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('请选择要停用的区域！'));
            }
            $this->getMessageManager()->addWarning(__('请选择要停用的区域！'));
            return $this->redirect($this->request->getReferer());
        }
        if ($this->i18n->localeExists($code)) {
            try {
                $summary = $this->lifecycle->deactivateLocale($code);
                $message = (string)__('区域已停用！区域代码：%{1}', $code);
                if ($isJsonRequest) {
                    return $this->jsonActionResponse(true, $message, $summary);
                }
                $this->getMessageManager()->addSuccess($message);
            } catch (\Throwable $exception) {
                if ($isJsonRequest) {
                    return $this->jsonActionResponse(false, $exception->getMessage());
                }
                $this->getMessageManager()->addException($exception);
            }
        } else {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('地区已经不存在！'));
            }
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function postInstall()
    {
        $code = (string)$this->request->getPost('code', '');
        $isJsonRequest = $this->isJsonRequest();
        try {
            $summary = $this->lifecycle->installLocale($code);
            $message = (string)__('区域已安装！区域代码：%{1}', $code);
            if ($isJsonRequest) {
                return $this->jsonActionResponse(true, $message, $summary);
            }
            $this->getMessageManager()->addSuccess($message);
        } catch (\Exception $exception) {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, $exception->getMessage());
            }
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->request->getReferer());
    }

    private function isJsonRequest(): bool
    {
        $accept = strtolower((string)($this->request->getHeader('Accept') ?? ''));
        return $this->request->isAjax() || str_contains($accept, 'application/json');
    }

    private function jsonActionResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function postUninstall()
    {
        $code = (string)$this->request->getPost('code', '');
        $isJsonRequest = $this->isJsonRequest();
        if ($code === '') {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('请选择要卸载的区域！'));
            }
            $this->getMessageManager()->addWarning(__('请选择要卸载的区域！'));
            return $this->redirect($this->request->getReferer());
        }
        try {
            $summary = $this->lifecycle->uninstallLocale($code);
            $message = (string)__('区域已卸载！区域代码：%{1}', $code);
            if ($isJsonRequest) {
                return $this->jsonActionResponse(true, $message, $summary);
            }
            $this->getMessageManager()->addSuccess($message);
        } catch (\Throwable $exception) {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, $exception->getMessage());
            }
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->request->getReferer());
    }

    /**
     * 自动更新区域数据
     */
    private function autoUpdateLocaleData(): void
    {
        try {
            $country_code = $this->request->getParam('country_code');
            if (!$country_code) {
                return;
            }
            
            // 检查国家是否存在
            if (!\Symfony\Component\Intl\Countries::exists($country_code)) {
                return;
            }
            
            // 获取该国家的所有区域
            $country = $this->i18n->getCountry($country_code);
            $locales = (array)($country['locales'] ?? []);
            
            if (empty($locales)) {
                return;
            }
            
            $locales_data = [];
            $locales_display = [];
            
            foreach ($locales as $locale) {
                // 提取简码、ISO2和ISO3
                $localeCodes = Locale::extractLocaleCodes($locale);
                
                $locales_data[] = [
                    $this->locale::schema_fields_CODE => $locale,
                    $this->locale::schema_fields_COUNTRY_CODE => $country_code,
                    $this->locale::schema_fields_SHORT_CODE => $localeCodes['short_code'],
                    $this->locale::schema_fields_ISO2 => $localeCodes['iso2'],
                    $this->locale::schema_fields_ISO3 => $localeCodes['iso3'],
                    $this->locale::schema_fields_IS_INSTALL => 0,
                    $this->locale::schema_fields_IS_ACTIVE => 0,
                    $this->locale::schema_fields_FLAG => ''
                ];
                
                $locales_display[] = [
                    Name::schema_fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                    Name::schema_fields_LOCALE_CODE => $locale,
                    Name::schema_fields_DISPLAY_NAME => $this->i18n->getLocaleName($locale, Cookie::getLangLocal()),
                ];
            }
            
            // 使用独立且干净的模型实例，避免影响主查询对象。
            $localeModel = ObjectManager::make(Locale::class);
            $localeNameModel = ObjectManager::make(Name::class);

            // 只插入数据库中缺失的区域。这里不能使用带冲突更新的
            // 全量 upsert：自动补数据的默认状态是 0，会覆盖用户刚安装
            // 的区域。
            $existingLocales = $localeModel->reset()
                ->where(Locale::schema_fields_COUNTRY_CODE, $country_code)
                ->select(Locale::schema_fields_CODE)
                ->fetch()
                ->getItems();
            $existingLocaleCodes = [];
            foreach ($existingLocales as $existingLocale) {
                $existingCode = (string)$existingLocale->getData(Locale::schema_fields_CODE);
                if ($existingCode !== '') {
                    $existingLocaleCodes[$existingCode] = true;
                }
            }

            $missingLocales = array_values(array_filter(
                $locales_data,
                static fn(array $localeData): bool => !isset($existingLocaleCodes[(string)$localeData[Locale::schema_fields_CODE]])
            ));
            if ($missingLocales !== []) {
                $localeModel->reset()
                    ->insert($missingLocales, Locale::schema_fields_CODE)
                    ->fetch();
            }

            // 名称表允许按 locale + 展示语言补齐；不会触碰区域安装状态。
            if ($locales_display !== []) {
                $localeNameModel->reset()->insert($locales_display, [
                    Name::schema_fields_LOCALE_CODE,
                    Name::schema_fields_DISPLAY_LOCALE_CODE
                ])->fetch();
            }
            
        } catch (\Exception $e) {
            // 这里没有显式开启事务，不能为了补数据去回滚共享连接上
            // 其他请求留下的事务；记录错误并保留当前页面可用性即可。
            w_log_error('Auto update locale data failed: ' . $e->getMessage(), [], 'i18n');
        }
    }

    /**
     * 自动更新缺失的区域名称数据（优化版本，只检查当前语言）
     */
    private function autoUpdateMissingLocaleNames(): void
    {
        try {
            $country_code = $this->request->getParam('country_code');
            $currentLang = Cookie::getLangLocal();
            
            if (!$country_code) {
                return;
            }
            
            // 首先检查该国家的当前语言是否已经有区域名称数据
            $existingNamesCount = $this->localeName->clearQuery()
                ->joinModel(
                    Locale::class,
                    'l',
                    'main_table.' . Name::schema_fields_LOCALE_CODE . '=l.' . Locale::schema_fields_CODE,
                    'inner'
                )
                ->where('l.' . Locale::schema_fields_COUNTRY_CODE, $country_code)
                ->where('main_table.' . Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLang)
                ->count();
            
            if ($existingNamesCount > 0) {
                // 当前语言的区域名称已存在，无需更新
                return;
            }
            
            // 获取该国家下所有已存在的区域
            $existingLocales = $this->locale->clearQuery()
                ->where($this->locale::schema_fields_COUNTRY_CODE, $country_code)
                ->select($this->locale::schema_fields_CODE)
                ->fetch()
                ->getItems();
            
            if (empty($existingLocales)) {
                return;
            }
            
            // 批量准备当前语言的区域名称数据
            $missingNames = [];
            
            foreach ($existingLocales as $locale) {
                $localeCode = $locale->getData($this->locale::schema_fields_CODE);
                
                // 获取区域显示名称
                $displayName = $this->i18n->getLocaleName($localeCode, $currentLang);
                if ($displayName) {
                    $missingNames[] = [
                        Name::schema_fields_DISPLAY_LOCALE_CODE => $currentLang,
                        Name::schema_fields_LOCALE_CODE => $localeCode,
                        Name::schema_fields_DISPLAY_NAME => $displayName,
                    ];
                }
            }
            
            // 批量插入当前语言的区域名称数据
            if (!empty($missingNames)) {
                $this->localeName->clearQuery()
                    ->insert($missingNames, [
                        Name::schema_fields_LOCALE_CODE,
                        Name::schema_fields_DISPLAY_LOCALE_CODE
                    ])
                    ->fetch();
            }
            
        } catch (\Exception $e) {
            // 静默处理异常，不影响页面显示
            w_log_error('Auto update missing locale names failed: ' . $e->getMessage(), [], 'i18n');
        }
    }
}
