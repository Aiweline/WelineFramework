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

use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
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
        // Mutation POSTs only need the lifecycle service — skip listing joins.
        // joinModel(class-string) bindQuery() onto ObjectManager singletons; that
        // pollutes Countries / Name models used by DisplayNameCartesianSeeder
        // (which historically only clearQuery()'d, leaving _bind_query set) and
        // surfaces as 并行事务 / SQLSTATE[25P02] on install/activate.
        if (!$this->request->isGet()) {
            return;
        }

        // WLS worker 常驻时模型对象可能跨请求复用。列表查询必须使用
        // 请求级模型，避免上一请求的 items/data 参与本次状态判断。
        $this->locale = ObjectManager::make(Locale::class);

        // 先设置基础条件
        $this->locale->where('main_table.' . $this->locale::schema_fields_COUNTRY_CODE, $country_code);

        // Join clones — never bindQuery onto OM singletons.
        $countriesJoin = (clone ObjectManager::getInstance(\Weline\I18n\Model\Countries::class))->clear();
        $countryNameJoin = (clone ObjectManager::getInstance(\Weline\I18n\Model\Countries\Locale\Name::class))->clear();
        $localeNameJoin = (clone ObjectManager::getInstance(Name::class))->clear();

        // 先执行joinModel，然后再使用where条件引用join的表
        $this->locale->joinModel(
                $countriesJoin,
                'c',
                'main_table.' . $this->locale::schema_fields_COUNTRY_CODE . '=c.' . \Weline\I18n\Model\Countries::schema_fields_CODE,
                'left',
                'c.flag'
            )
            ->joinModel(
                $countryNameJoin,
                'cln',
                'c.' . \Weline\I18n\Model\Countries::schema_fields_CODE . '=cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_COUNTRY_CODE,
                'left',
                'cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_NAME . ' as country_name'
            )->joinModel(
                $localeNameJoin,
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
        $locales_result = $this->locale
            ->fields('main_table.*')
            ->order('main_table.' . Locale::schema_fields_IS_ACTIVE, 'DESC')
            ->order('main_table.' . Locale::schema_fields_IS_INSTALL, 'DESC')
            ->order('main_table.' . Locale::schema_fields_CODE, 'ASC')
            ->pagination()
            ->select()
            ->fetch();

        // 空列表不回落读种子包、不 Message 刷屏；缺库存由安装动作走 w_msg（dedupe）报运营。
        $this->assign('locales', $locales_result->getItems());
        $this->assign('pagination', $locales_result->getPagination());

        // 国家列表「区域」弹窗：返回 HTML 片段 JSON，不跳整页。
        if ($this->wantsLocalesPanel()) {
            $html = (string)$this->template('Weline_I18n::templates/Backend/Countries/Locales/panel.phtml');

            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'country_code' => (string)$this->request->getParam('country_code', ''),
                'search' => (string)$this->request->getParam('search', ''),
            ]);
        }

        // The view intentionally uses getIndex.phtml to distinguish the
        // locale listing from the country listing. The implicit action view
        // resolver looks for index.phtml, so select the template explicitly.
        return $this->fetch('getIndex');
    }

    private function wantsLocalesPanel(): bool
    {
        if (trim((string)$this->request->getGet('panel', '')) === '1') {
            return true;
        }
        $header = $this->request->getHeader('X-Weline-Locales-Panel');
        if (is_array($header)) {
            $header = (string)($header[0] ?? '');
        }

        return trim((string)$header) === '1';
    }


    public function postActive()
    {
        $code = (string)$this->request->getPost('code', '');
        $isJsonRequest = $this->isJsonRequest();
        if (!$this->i18n->localeExists($code)) {
            if ($isJsonRequest) {
                return $this->jsonActionResponse(false, (string)__('地区已经不存在！'));
            }
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
            return $this->redirect('*/backend/countries/locales', $this->request->getParams());
        }
        if ($this->wantsSse()) {
            return $this->streamLifecycle(
                (string)__('正在激活区域 %{1}…', [$code]),
                function (?callable $progress) use ($code): array {
                    return $this->lifecycle->activateLocale($code, $progress);
                },
                static function (array $summary) use ($code): string {
                    return (string)__('区域已激活！区域代码：%{1}', $code);
                }
            );
        }
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
        if ($this->wantsSse()) {
            return $this->streamLifecycle(
                (string)__('正在安装区域 %{1}…', [$code]),
                function (?callable $progress) use ($code): array {
                    return $this->lifecycle->installLocale($code, $progress);
                },
                static function (array $summary) use ($code): string {
                    return (string)__('区域已安装并激活！区域代码：%{1}', $code);
                }
            );
        }
        try {
            $summary = $this->lifecycle->installLocale($code);
            $message = (string)__('区域已安装并激活！区域代码：%{1}', $code);
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

    private function wantsSse(): bool
    {
        $accept = strtolower((string)($this->request->getHeader('Accept') ?? ''));
        return str_contains($accept, 'text/event-stream')
            || (string)$this->request->getGet('sse', '') === '1'
            || (string)$this->request->getPost('sse', '') === '1';
    }

    /**
     * @param callable(?callable):array $runner
     * @param callable(array):string $doneMessage
     */
    private function streamLifecycle(string $startMessage, callable $runner, callable $doneMessage): string
    {
        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => $startMessage, 'percent' => 0]);
        try {
            $summary = $runner(static function (array $progress) use ($sse): void {
                $sse->sendEvent('progress', $progress);
            });
            $message = $doneMessage($summary);
            $sse->sendEvent('done', [
                'success' => true,
                'message' => $message,
                'percent' => 100,
                'data' => $summary,
            ]);
        } catch (\Throwable $exception) {
            $sse->sendEvent('error', [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
        $sse->close();

        return '';
    }

    private function isJsonRequest(): bool
    {
        if ($this->wantsSse()) {
            return false;
        }
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
}
