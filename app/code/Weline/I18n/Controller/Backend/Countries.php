<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries as CountriesModel;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Service\CountryDataUpdateService;
use Weline\I18n\Service\CountryLocaleLifecycleService;

class Countries extends BaseController
{
    private CountriesModel $countries;
    private Name $countryNames;
    private CountryLocaleLifecycleService $lifecycle;
    private CountryDataUpdateService $countryDataUpdateService;

    public function __construct(
        Locale $locale,
        I18n $i18n,
        CountriesModel $countries,
        Name $countryName
    ) {
        parent::__construct($locale, $i18n);
        $this->countries = $countries;
        $this->countryNames = $countryName;
        $this->lifecycle = ObjectManager::getInstance(CountryLocaleLifecycleService::class);
        $this->countryDataUpdateService = ObjectManager::getInstance(CountryDataUpdateService::class);

        $currentLang = Cookie::getLangLocal();
        $joinCondition = sprintf(
            'main_table.code=cln.country_code AND cln.%s=\'%s\'',
            Name::schema_fields_DISPLAY_LOCALE_CODE,
            addslashes($currentLang)
        );

        $this->countries->joinModel(
            Name::class,
            'cln',
            $joinCondition,
            'left',
            'cln.' . Name::schema_fields_DISPLAY_NAME . ' as display_name, cln.' . Name::schema_fields_DISPLAY_LOCALE_CODE
        );
    }

    public function __init()
    {
        parent::__init();

        if ($search = trim((string)$this->request->getGet('search', ''))) {
            $search = addslashes($search);
            $code = $this->countries::schema_fields_CODE;
            $name = Name::schema_fields_DISPLAY_NAME;
            $this->countries->concat_like('main_table.' . $code . ',cln.' . $name, '%' . $search . '%');
        }

        if ($searchCode = trim((string)$this->request->getGet('search_code', ''))) {
            $searchCode = addslashes($searchCode);
            $this->countries->like('main_table.' . $this->countries::schema_fields_CODE, '%' . $searchCode . '%');
        }

        if ($searchName = trim((string)$this->request->getGet('search_name', ''))) {
            $searchName = addslashes($searchName);
            $this->countries->like('cln.' . Name::schema_fields_DISPLAY_NAME, '%' . $searchName . '%');
        }

        if ($searchStatus = trim((string)$this->request->getGet('search_status', ''))) {
            $this->applyFilter($this->countries, $searchStatus);
        }
    }

    public function index()
    {
        $filter = (string)$this->request->getGet('filter', 'active');
        $search = (string)$this->request->getGet('search', '');

        $this->ensureCountryData();
        $this->autoUpdateMissingCountryNames();

        $query = clone $this->countries;
        $this->applyFilter($query, $filter);
        $result = $query->pagination()->select()->fetch();
        $countries = $result->getItems();

        foreach ($countries as $country) {
            $this->hydrateCountry($country);
        }

        $stats = $this->getCountryStats();
        $recommendations = $this->getRecommendations($countries);

        $isAjax = $this->request->isAjax();
        $acceptHeader = (string)($this->request->getHeader('Accept') ?? '');
        $format = (string)$this->request->getGet('format', '');
        $isJsonRequest = $isAjax || str_contains($acceptHeader, 'application/json') || $format === 'json';

        if ($isJsonRequest) {
            return $this->jsonResponse($countries, $result->getPagination(), $filter, $search, $stats, $recommendations);
        }

        $this->assign('countries', $countries);
        $this->assign('countries_pagination', $result->getPagination());
        $this->assign('current_filter', $filter);
        $this->assign('search', $search);
        $this->assign('stats', $stats);
        $this->assign('recommendations', $recommendations);

        return $this->fetch();
    }

    public function getUpdate()
    {
        if ($this->countryDataUpdateService->updateCountryData()) {
            Message::success(__('国家数据同步完成'));
        } else {
            Message::error(__('国家数据同步失败，请检查日志'));
        }

        return $this->redirect('*/backend/countries');
    }

    public function postInstall()
    {
        if (!$this->request->isPost()) {
            Message::error(__('请求错误！'));
            return $this->redirect('*/backend/countries');
        }

        $code = (string)$this->request->getPost('code', '');
        $filter = $this->getRequestFilter('all');
        if ($code === '') {
            Message::warning(__('请选择要安装的国家！'));
            return $this->redirect($this->buildListUrl($filter));
        }

        try {
            $summary = $this->lifecycle->installCountry($code);
            Message::success(__('国家 %{1} 已安装，可用地区 %{2} 个', [
                $summary['display_name'] ?? $summary['country_code'],
                $summary['locale_count'] ?? 0,
            ]));
        } catch (\Exception $exception) {
            Message::exception($exception);
        }

        return $this->redirect($this->buildListUrl($filter));
    }

    public function postActive()
    {
        $code = (string)$this->request->getPost('code', '');
        $filter = $this->getRequestFilter('active');
        if ($code === '') {
            Message::warning(__('请选择国家激活！'));
            return $this->redirect($this->buildListUrl($filter));
        }

        try {
            $summary = $this->lifecycle->activateCountry($code);
            Message::success(__('国家 %{1} 已启用，推荐地区 %{2} 已同步启用', [
                $summary['display_name'] ?? $summary['country_code'],
                $summary['preferred_locale'] ?? __('默认地区'),
            ]));
        } catch (\Exception $exception) {
            Message::exception($exception);
        }

        return $this->redirect($this->buildListUrl($filter));
    }

    public function postDisable()
    {
        $code = (string)$this->request->getPost('code', '');
        $filter = $this->getRequestFilter('active');
        if ($code === '') {
            Message::warning(__('请选择国家禁用！'));
            return $this->redirect($this->buildListUrl($filter));
        }

        try {
            $summary = $this->lifecycle->deactivateCountry($code);
            Message::success(__('国家 %{1} 已停用，关联地区已一并停用', [
                $summary['display_name'] ?? $summary['country_code'],
            ]));
        } catch (\Exception $exception) {
            Message::exception($exception);
        }

        return $this->redirect($this->buildListUrl($filter));
    }

    public function postUninstall()
    {
        $code = (string)$this->request->getPost('code', '');
        $filter = $this->getRequestFilter('all');
        if ($code === '') {
            Message::warning(__('请选择要卸载的国家！'));
            return $this->redirect($this->buildListUrl($filter));
        }

        try {
            $summary = $this->lifecycle->uninstallCountry($code);
            Message::success(__('国家 %{1} 已卸载，关联地区 %{2} 个已同步卸载', [
                $summary['display_name'] ?? $summary['country_code'],
                $summary['locale_count'] ?? 0,
            ]));
        } catch (\Exception $exception) {
            Message::exception($exception);
        }

        return $this->redirect($this->buildListUrl($filter));
    }

    public function batchActive()
    {
        return $this->runBatchAction(
            (array)$this->request->getPost('codes', []),
            function (string $code): void {
                $this->lifecycle->activateCountry($code);
            },
            __('请选择要启用的国家！'),
            __('已一键启用 %{1} 个国家'),
            __('%{1} 个国家启用失败'),
            $this->getRequestFilter('active')
        );
    }

    public function batchDisable()
    {
        return $this->runBatchAction(
            (array)$this->request->getPost('codes', []),
            function (string $code): void {
                $this->lifecycle->deactivateCountry($code);
            },
            __('请选择要停用的国家！'),
            __('已停用 %{1} 个国家'),
            __('%{1} 个国家停用失败'),
            $this->getRequestFilter('active')
        );
    }

    public function batchInstall()
    {
        return $this->runBatchAction(
            (array)$this->request->getPost('codes', []),
            function (string $code): void {
                $this->lifecycle->installCountry($code);
            },
            __('请选择要安装的国家！'),
            __('已安装 %{1} 个国家'),
            __('%{1} 个国家安装失败'),
            $this->getRequestFilter('all')
        );
    }

    public function batchUninstall()
    {
        return $this->runBatchAction(
            (array)$this->request->getPost('codes', []),
            function (string $code): void {
                $this->lifecycle->uninstallCountry($code);
            },
            __('请选择要卸载的国家！'),
            __('已卸载 %{1} 个国家'),
            __('%{1} 个国家卸载失败'),
            $this->getRequestFilter('all')
        );
    }

    private function runBatchAction(
        array $codes,
        callable $handler,
        string $emptyMessage,
        string $successMessage,
        string $errorMessage,
        string $filter
    ) {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if (empty($codes)) {
            Message::warning($emptyMessage);
            return $this->redirect($this->buildListUrl($filter));
        }

        $successCount = 0;
        $errors = [];
        foreach ($codes as $code) {
            try {
                $handler($code);
                $successCount++;
            } catch (\Throwable $throwable) {
                $errors[] = $code . ': ' . $throwable->getMessage();
            }
        }

        if ($successCount > 0) {
            Message::success(__($successMessage, [$successCount]));
        }
        if (!empty($errors)) {
            Message::warning(__($errorMessage, [count($errors)]));
            Message::warning(implode('<br>', array_slice($errors, 0, 5)));
        }

        return $this->redirect($this->buildListUrl($filter));
    }

    private function jsonResponse(
        array $countries,
        $pagination,
        string $filter,
        string $search,
        array $stats,
        array $recommendations
    ): string {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        $countriesData = [];
        foreach ($countries as $country) {
            $countriesData[] = [
                'id' => $country->getId(),
                'code' => $country->getData($this->countries::schema_fields_CODE),
                'display_name' => $country->getData('display_name') ?: $country->getData($this->countries::schema_fields_CODE),
                'flag' => $country->getData('flag') ?: '',
                'is_install' => (bool)$country->getData($this->countries::schema_fields_IS_INSTALL),
                'is_active' => (bool)$country->getData($this->countries::schema_fields_IS_ACTIVE),
                'locale_count' => (int)$country->getData('locale_count'),
                'installed_locale_count' => (int)$country->getData('installed_locale_count'),
                'active_locale_count' => (int)$country->getData('active_locale_count'),
                'preferred_locale' => $country->getData('preferred_locale'),
                'display_locale_code' => $country->getData('display_locale_code') ?: Cookie::getLangLocal(),
            ];
        }

        $paginationData = null;
        if ($pagination) {
            $paginationData = [
                'current_page' => $pagination->getCurrentPage(),
                'page_size' => $pagination->getPageSize(),
                'total_pages' => $pagination->getTotalPages(),
                'total_records' => $pagination->getTotalRecords(),
            ];
        }

        return json_encode([
            'success' => true,
            'message' => __('获取成功'),
            'data' => [
                'countries' => $countriesData,
                'pagination' => $paginationData,
                'filter' => $filter,
                'search' => $search,
                'stats' => $stats,
                'recommendations' => $recommendations,
                'current_locale' => Cookie::getLangLocal(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ensureCountryData(): void
    {
        $countryCount = (int)(clone ObjectManager::getInstance(CountriesModel::class))->reset()->count();
        if ($countryCount === 0) {
            $this->countryDataUpdateService->updateCountryData();
        }
    }

    private function autoUpdateMissingCountryNames(): void
    {
        try {
            $currentLang = Cookie::getLangLocal();
            $countryNames = $this->i18n->getCountries($currentLang);
            if (empty($countryNames)) {
                return;
            }

            $allCountries = (clone ObjectManager::getInstance(CountriesModel::class))->reset()->select()->fetch()->getItems();
            if (empty($allCountries)) {
                return;
            }

            $missingData = [];
            foreach ($allCountries as $country) {
                $countryCode = (string)$country->getData(CountriesModel::schema_fields_CODE);
                $existingName = (clone ObjectManager::getInstance(Name::class))->reset()
                    ->where(Name::schema_fields_COUNTRY_CODE, $countryCode)
                    ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLang)
                    ->find()
                    ->fetch();

                if ($existingName->getId() || !isset($countryNames[$countryCode])) {
                    continue;
                }

                $missingData[] = [
                    Name::schema_fields_COUNTRY_CODE => $countryCode,
                    Name::schema_fields_DISPLAY_LOCALE_CODE => $currentLang,
                    Name::schema_fields_DISPLAY_NAME => $countryNames[$countryCode],
                ];
            }

            if (!empty($missingData)) {
                (clone ObjectManager::getInstance(Name::class))->reset()->insert($missingData, [
                    Name::schema_fields_COUNTRY_CODE,
                    Name::schema_fields_DISPLAY_LOCALE_CODE,
                ])->fetch();
            }
        } catch (\Throwable $throwable) {
            w_log_error('Auto update missing country names failed: ' . $throwable->getMessage(), [], 'i18n');
        }
    }

    private function hydrateCountry($country): void
    {
        $countryCode = (string)$country->getData($this->countries::schema_fields_CODE);
        $summary = $this->lifecycle->getCountrySummary($countryCode);

        if (!$country->getData('display_name')) {
            $country->setData('display_name', $summary['display_name'] ?? $countryCode);
        }
        if (!$country->getData('flag')) {
            try {
                $country->setData('flag', $this->i18n->getCountryFlag($countryCode, 32, 24) ?: '');
            } catch (\Throwable) {
                $country->setData('flag', '');
            }
        }

        $country->setData('locale_count', (int)($summary['locale_count'] ?? 0));
        $country->setData('installed_locale_count', (int)($summary['installed_locale_count'] ?? 0));
        $country->setData('active_locale_count', (int)($summary['active_locale_count'] ?? 0));
        $country->setData('preferred_locale', $summary['preferred_locale'] ?? '');
    }

    private function getCountryStats(): array
    {
        $countryModel = clone ObjectManager::getInstance(CountriesModel::class);
        $activeModel = clone ObjectManager::getInstance(CountriesModel::class);
        $installedModel = clone ObjectManager::getInstance(CountriesModel::class);

        $total = (int)$countryModel->reset()->count();
        $active = (int)$activeModel->reset()
            ->where(CountriesModel::schema_fields_IS_INSTALL, 1)
            ->where(CountriesModel::schema_fields_IS_ACTIVE, 1)
            ->count();
        $installed = (int)$installedModel->reset()
            ->where(CountriesModel::schema_fields_IS_INSTALL, 1)
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'installed' => $installed,
            'inactive' => max(0, $installed - $active),
            'uninstalled' => max(0, $total - $installed),
        ];
    }

    private function getRecommendations(array $countries): array
    {
        $recommendations = [];
        foreach ($countries as $country) {
            $countryCode = (string)$country->getData($this->countries::schema_fields_CODE);
            $preferredLocale = (string)$country->getData('preferred_locale');
            if ($preferredLocale === '') {
                continue;
            }

            $recommendations[$countryCode] = [
                'locale_code' => $preferredLocale,
                'country_code' => $countryCode,
                'label' => $country->getData('display_name') ?: $countryCode,
            ];
        }

        return $recommendations;
    }

    private function applyFilter(CountriesModel $query, string $filter): void
    {
        switch ($filter) {
            case 'active':
                $query->where('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 1)
                    ->where('main_table.' . CountriesModel::schema_fields_IS_ACTIVE, 1);
                break;
            case 'inactive':
                $query->where('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 1)
                    ->where('main_table.' . CountriesModel::schema_fields_IS_ACTIVE, 0);
                break;
            case 'installed':
                $query->where('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 1);
                break;
            case 'uninstalled':
                $query->where('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 0);
                break;
            default:
                break;
        }
    }

    private function getRequestFilter(string $default): string
    {
        return (string)$this->request->getPost('filter', $default);
    }

    private function buildListUrl(string $filter): string
    {
        return '*/backend/countries?filter=' . urlencode($filter);
    }
}
