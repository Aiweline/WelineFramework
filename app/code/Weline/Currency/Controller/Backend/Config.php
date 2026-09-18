<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Controller\Backend;

use Weline\Currency\Api\ExchangeRateApi;
use Weline\Currency\Model\Config as CurrencyConfig;
use Weline\Currency\Service\CurrencyImportService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币配置控制器
 */
#[\Weline\Framework\Acl\Acl('Weline_Currency::currency_config', '货币配置', 'settings', '货币模块配置管理', 'Weline_Backend::currency_group')]
class Config extends BackendController
{
    /**
     * @var CurrencyConfig
     */
    private CurrencyConfig $config;

    /**
     * 构造函数
     */
    public function __construct(CurrencyConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 配置页面
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_config_view', '查看配置', '', '查看货币配置')]
     */
    public function index()
    {
        if ($this->request->isGet()) {
            // 获取当前配置
            $this->assign('rate_mode', $this->config->getRateMode());
            $this->assign('import_enabled', $this->config->isImportEnabled());
            $this->assign('import_provider', $this->config->getImportProvider());
            $this->assign('import_api_key', $this->config->getImportApiKey());
            $this->assign('import_cron_time', $this->config->getImportCronTime());
            $this->assign('base_currency', $this->config->getBaseCurrency());
            $this->assign('last_import_time', $this->config->getLastImportTime());
            
            // 获取所有已启用的货币列表，用于基准货币选择
            $currencyModel = ObjectManager::getInstance(\Weline\Currency\Model\Currency::class);
            $currencies = $currencyModel->clear()
                ->where(\Weline\Currency\Model\Currency::schema_fields_STATUS, 1)
                ->order('code', 'ASC')
                ->select()
                ->fetchArray();
            $this->assign('available_currencies', $currencies);
            
            // 格式化最后导入时间
            $lastImportTime = $this->config->getLastImportTime();
            $this->assign('last_import_time_formatted', $lastImportTime ? date('Y-m-d H:i:s', $lastImportTime) : '从未导入');
            
            return $this->fetch();
        }

        // POST请求：保存配置
        try {
            $rateMode = strtolower(trim((string) $this->request->getPost('rate_mode', $this->config->getRateMode())));
            if (!in_array($rateMode, [CurrencyConfig::RATE_MODE_MANUAL, CurrencyConfig::RATE_MODE_AUTO], true)) {
                $rateMode = CurrencyConfig::RATE_MODE_MANUAL;
            }

            $importEnabled = $rateMode === CurrencyConfig::RATE_MODE_AUTO
                ? (bool)$this->request->getPost('import_enabled', false)
                : false;
            $importProvider = $this->request->getPost('import_provider', 'exchangerate-api');
            $importApiKey = $this->request->getPost('import_api_key', '');
            $importCronTime = $this->request->getPost('import_cron_time', '0 2 * * *');
            $baseCurrency = strtoupper($this->request->getPost('base_currency', 'CNY'));

            // 验证基准货币代码
            if (strlen($baseCurrency) !== 3 || !ctype_upper($baseCurrency)) {
                throw new \InvalidArgumentException(__('基准货币代码必须是3位大写字母'));
            }

            // 验证Cron表达式（简单验证）
            if (empty($importCronTime)) {
                throw new \InvalidArgumentException(__('Cron执行时间不能为空'));
            }

            // 检查基准货币是否改变
            $oldBaseCurrency = $this->config->getBaseCurrency();
            $baseCurrencyChanged = ($oldBaseCurrency !== $baseCurrency);
            $applyConfirmed = in_array(
                (string) $this->request->getPost('confirm_base_currency_apply', ''),
                ['1', 'true', 'yes', 'on'],
                true
            );

            // 基准币变更必须先预览确认，未确认则保留原基准，其它配置仍可保存
            if ($baseCurrencyChanged && !$applyConfirmed) {
                if ($this->request->isAjax()) {
                    return $this->fetchJson([
                        'success' => false,
                        'needs_confirm' => true,
                        'old_base_currency' => $oldBaseCurrency,
                        'new_base_currency' => $baseCurrency,
                        'message' => __('请先确认汇率对照表后再切换基准货币'),
                    ]);
                }
                $baseCurrency = $oldBaseCurrency;
                $baseCurrencyChanged = false;
                $this->getMessageManager()->addWarning(
                    __('基准货币未变更：请先在弹窗中确认各币种汇率对照后再切换。其它配置如有修改仍会保存。')
                );
            }

            // 保存配置（先保存，因为重新计算需要新的基准货币）
            $this->config->setRateMode($rateMode);
            $this->config->setImportEnabled($importEnabled);
            $this->config->setImportProvider($importProvider);
            $this->config->setImportApiKey($importApiKey ?: null);
            $this->config->setImportCronTime($importCronTime);
            $this->config->setBaseCurrency($baseCurrency);

            // 已确认的基准币切换：手动/自动都按已有 rate 比例重算
            if ($baseCurrencyChanged) {
                // 如果是AJAX请求，返回需要更新的标识（前端再调 post-update-rates）
                if ($this->request->isAjax()) {
                    return $this->fetchJson([
                        'success' => true,
                        'base_currency_changed' => true,
                        'old_base_currency' => $oldBaseCurrency,
                        'new_base_currency' => $baseCurrency,
                        'message' => __('配置已保存，正在应用已确认的汇率换算…')
                    ]);
                }

                // 非AJAX请求，直接执行更新（可能较慢）
                try {
                    $apiKey = $this->config->getImportApiKey();
                    $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
                    $importService = new CurrencyImportService($api);

                    $result = $importService->recalculateRatesForNewBase($oldBaseCurrency, $baseCurrency);

                    $this->getMessageManager()->addSuccess(
                        __('配置保存成功！已按已有汇率换算 %{1} 个货币（成功: %{2}, 失败: %{3}）',
                        [$result['total_count'], $result['success_count'], $result['fail_count']])
                    );
                } catch (\Exception $e) {
                    $this->getMessageManager()->addWarning(
                        __('配置已保存，但换算货币汇率时出错: %{1}。请重试切换基准货币。', $e->getMessage())
                    );
                }
            } else {
                $this->getMessageManager()->addSuccess(__('配置保存成功！'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('配置保存失败: %{1}', $e->getMessage()));
        }

        return $this->redirect($this->request->getReferer());
    }

    /**
     * 测试API连接
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_config_test', '测试API', '', '测试汇率API连接')]
     */
    #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_config_test', '测试API', 'link', '测试汇率API连接')]
    public function testApi()
    {
        try {
            // 获取API密钥（可选，免费版不需要）
            $apiKey = $this->request->getPost('api_key', $this->config->getImportApiKey());
            // 如果为空字符串，转换为null
            $apiKey = $apiKey ?: null;
            
            // 创建API实例（密钥可选）
            $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
            
            // 测试连接（会抛出异常如果失败）
            $success = $api->testConnection();
            
            if ($success) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('API连接测试成功')
                ]);
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('API连接测试失败，请检查网络连接和API配置')
                ]);
            }
        } catch (\Exception $e) {
            // 返回详细的错误信息
            $errorMessage = $e->getMessage();
            
            // 如果是网络相关错误，提供更友好的提示
            if (strpos($errorMessage, 'curl') !== false || strpos($errorMessage, 'timeout') !== false) {
                $errorMessage = __('网络连接失败，请检查服务器网络连接或防火墙设置');
            } elseif (strpos($errorMessage, 'HTTP') !== false || strpos($errorMessage, '状态码') !== false) {
                $errorMessage = __('API服务暂时不可用，请稍后重试');
            }
            
            return $this->fetchJson([
                'success' => false,
                'message' => __('API连接测试失败: %{1}', $errorMessage)
            ]);
        }
    }

    /**
     * 手动触发导入
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_import', '手动导入', '', '手动触发汇率导入')]
     */
    #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_import', '手动导入', 'download', '手动触发汇率导入')]
    public function import()
    {
        try {
            if ($this->config->isManualRateMode()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('当前为手动汇率模式，无法执行自动导入。')
                ]);
            }

            $baseCurrency = $this->config->getBaseCurrency();
            
            if (empty($baseCurrency)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('基准货币未设置，无法执行导入')
                ]);
            }

            // 创建导入服务（勿经 ObjectManager 解析接口，与 Cron 一致）
            $apiKey = $this->config->getImportApiKey();
            $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
            $importService = new CurrencyImportService($api);
            
            // 执行导入
            $result = $importService->importAll($baseCurrency);
            
            // 更新最后导入时间
            $this->config->setLastImportTime(time());

            return $this->fetchJson([
                'success' => true,
                'message' => sprintf(
                    __('导入完成 - 总计: %d, 成功: %d, 失败: %d'),
                    $result['total_count'],
                    $result['success_count'],
                    $result['fail_count']
                ),
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('导入失败: %{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 预览基准货币切换后的汇率对照（只读，不写库）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_update_rates', '更新汇率', 'refresh', '更新所有货币汇率')]
    public function previewBaseCurrencyChange()
    {
        try {
            $oldBaseCurrency = strtoupper(trim((string) (
                $this->request->getPost('old_base_currency')
                ?: $this->request->getGet('old_base_currency')
                ?: $this->config->getBaseCurrency()
            )));
            $newBaseCurrency = strtoupper(trim((string) (
                $this->request->getPost('new_base_currency')
                ?: $this->request->getGet('new_base_currency')
                ?: ''
            )));

            if ($oldBaseCurrency === '' || $newBaseCurrency === '') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('参数错误：基准货币代码不能为空'),
                ]);
            }

            if ($oldBaseCurrency === $newBaseCurrency) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('新旧基准货币相同，无需换算'),
                ]);
            }

            $apiKey = $this->config->getImportApiKey();
            $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
            $importService = new CurrencyImportService($api);
            $preview = $importService->previewRatesForNewBase($oldBaseCurrency, $newBaseCurrency);

            if ($preview['errors'] !== [] && $preview['changes'] === []) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无法预览汇率换算'),
                    'data' => $preview,
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('以下为切换基准货币后的汇率对照，确认后才会写入'),
                'data' => $preview,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('预览失败: %{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * SSE：确认后保存基准币并逐币写入汇率，推送全程进度。
     */
    #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_update_rates', '更新汇率', 'refresh', '更新所有货币汇率')]
    public function applyBaseCurrencyChangeStream(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            $oldBaseCurrency = strtoupper(trim((string) $this->request->getPost('old_base_currency', '')));
            $newBaseCurrency = strtoupper(trim((string) $this->request->getPost('new_base_currency', '')));
            $applyConfirmed = in_array(
                (string) $this->request->getPost('confirm_base_currency_apply', ''),
                ['1', 'true', 'yes', 'on'],
                true
            );

            if (!$applyConfirmed) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string) __('请先确认汇率对照后再应用'),
                ]);
                $sse->close();
                return;
            }

            if ($oldBaseCurrency === '' || $newBaseCurrency === '' || $oldBaseCurrency === $newBaseCurrency) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string) __('参数错误：请提供不同的新旧基准货币'),
                ]);
                $sse->close();
                return;
            }

            if ($oldBaseCurrency !== strtoupper(trim($this->config->getBaseCurrency()))) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string) __('当前基准货币已变化，请刷新页面后重试'),
                ]);
                $sse->close();
                return;
            }

            $sse->sendEvent('start', [
                'message' => (string) __('开始切换基准货币并纠正汇率'),
                'progress' => 0,
                'old_base' => $oldBaseCurrency,
                'new_base' => $newBaseCurrency,
            ]);

            $rateMode = strtolower(trim((string) $this->request->getPost('rate_mode', $this->config->getRateMode())));
            if (!in_array($rateMode, [CurrencyConfig::RATE_MODE_MANUAL, CurrencyConfig::RATE_MODE_AUTO], true)) {
                $rateMode = CurrencyConfig::RATE_MODE_MANUAL;
            }
            $importEnabled = $rateMode === CurrencyConfig::RATE_MODE_AUTO
                ? (bool) $this->request->getPost('import_enabled', false)
                : false;
            $importProvider = (string) $this->request->getPost('import_provider', 'exchangerate-api');
            $importApiKey = (string) $this->request->getPost('import_api_key', '');
            $importCronTime = (string) $this->request->getPost('import_cron_time', '0 2 * * *');

            $sse->sendEvent('progress', [
                'step' => 'save_config',
                'message' => (string) __('正在保存货币配置…'),
                'progress' => 8,
            ]);

            $this->config->setRateMode($rateMode);
            $this->config->setImportEnabled($importEnabled);
            $this->config->setImportProvider($importProvider);
            $this->config->setImportApiKey($importApiKey !== '' ? $importApiKey : null);
            $this->config->setImportCronTime($importCronTime !== '' ? $importCronTime : '0 2 * * *');
            $this->config->setBaseCurrency($newBaseCurrency);

            $api = ObjectManager::getInstance(ExchangeRateApi::class, [
                'apiKey' => $this->config->getImportApiKey(),
            ]);
            $importService = new CurrencyImportService($api);

            $sse->sendEvent('progress', [
                'step' => 'plan',
                'message' => (string) __('正在计算汇率对照…'),
                'progress' => 15,
            ]);

            $preview = $importService->previewRatesForNewBase($oldBaseCurrency, $newBaseCurrency);
            if ($preview['errors'] !== [] && $preview['changes'] === []) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string) __('无法计算汇率对照'),
                    'errors' => $preview['errors'],
                ]);
                $sse->close();
                return;
            }

            $sse->sendEvent('preview', [
                'message' => (string) __('汇率对照已就绪，开始逐币写入'),
                'progress' => 20,
                'data' => $preview,
            ]);

            $total = max(1, (int) ($preview['total_count'] ?? count($preview['changes'])));
            $success = 0;
            $fail = 0;
            $errors = [];

            $result = $importService->recalculateRatesForNewBase(
                $oldBaseCurrency,
                $newBaseCurrency,
                static function (int $current, int $totalCount, string $currencyCode) use ($sse, $preview, &$success): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $row = null;
                    foreach ($preview['changes'] as $change) {
                        if (($change['code'] ?? '') === $currencyCode) {
                            $row = $change;
                            break;
                        }
                    }
                    $percent = 20 + (int) floor(($current / max(1, $totalCount)) * 75);
                    $message = $row
                        ? sprintf(
                            '%s: %s → %s',
                            $currencyCode,
                            (string) ($row['old_meaning'] ?? $row['old_rate']),
                            (string) ($row['new_meaning'] ?? $row['new_rate'])
                        )
                        : sprintf('%s (%d/%d)', $currencyCode, $current, $totalCount);

                    $sse->sendEvent('progress', [
                        'step' => 'currency',
                        'currency' => $currencyCode,
                        'current' => $current,
                        'total' => $totalCount,
                        'old_rate' => $row['old_rate'] ?? null,
                        'new_rate' => $row['new_rate'] ?? null,
                        'old_meaning' => $row['old_meaning'] ?? null,
                        'new_meaning' => $row['new_meaning'] ?? null,
                        'message' => $message,
                        'progress' => min(95, $percent),
                    ]);
                    $success = $current;
                }
            );

            $success = (int) ($result['success_count'] ?? $success);
            $fail = (int) ($result['fail_count'] ?? 0);
            $errors = $result['errors'] ?? [];
            try {
                $this->config->setLastImportTime(time());
            } catch (\Throwable $ignored) {
            }

            if ($fail > 0 && $success === 0) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string) __('汇率写入失败'),
                    'errors' => $errors,
                    'progress' => 100,
                ]);
                $sse->close();
                return;
            }

            $sse->sendEvent('done', [
                'success' => true,
                'message' => (string) __(
                    '基准货币已切换为 %{1}，成功纠正 %{2} 个货币（失败 %{3}）',
                    [$newBaseCurrency, $success, $fail]
                ),
                'progress' => 100,
                'old_base' => $oldBaseCurrency,
                'new_base' => $newBaseCurrency,
                'total_count' => $total,
                'success_count' => $success,
                'fail_count' => $fail,
                'errors' => $errors,
            ]);
            $sse->close();
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== ''
                        ? $e->getMessage()
                        : (string) __('切换基准货币失败'),
                ]);
                $sse->close();
            }
        }
    }

    /**
     * 更新货币汇率（当基准货币改变时）
     * 支持进度反馈
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_update_rates', '更新汇率', '', '更新所有货币汇率')]
     */
    #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_update_rates', '更新汇率', 'refresh', '更新所有货币汇率')]
    public function postUpdateRates()
    {
        try {
            // 基准币切换重算：手动/自动模式均可（依据目录里已有 rate 比例换算，不依赖 API 导入）

            $oldBaseCurrency = $this->request->getPost('old_base_currency', '');
            $newBaseCurrency = $this->request->getPost('new_base_currency', '');
            $current = (int)$this->request->getPost('current', 0);
            
            if (empty($oldBaseCurrency) || empty($newBaseCurrency)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('参数错误：基准货币代码不能为空')
                ]);
            }

            // 创建导入服务（勿经 ObjectManager 解析接口，与 Cron 一致）
            $apiKey = $this->config->getImportApiKey();
            $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
            $importService = new CurrencyImportService($api);
            
            // 获取所有货币总数
            $currencyModel = ObjectManager::getInstance(\Weline\Currency\Model\Currency::class);
            $totalCount = (int)$currencyModel->clear()->count();
            
            // 如果current为0，说明是第一次调用，开始更新
            if ($current === 0) {
                // 执行重新计算
                $result = $importService->recalculateRatesForNewBase(
                    $oldBaseCurrency, 
                    $newBaseCurrency
                );
                
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('货币汇率更新完成'),
                    'total_count' => $result['total_count'],
                    'success_count' => $result['success_count'],
                    'fail_count' => $result['fail_count'],
                    'errors' => $result['errors'],
                    'completed' => true
                ]);
            }
            
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('更新失败: %{1}', $e->getMessage())
            ]);
        }
    }
}
