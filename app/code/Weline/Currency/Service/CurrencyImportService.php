<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Service;

use Weline\Currency\Api\ExchangeRateApiInterface;
use Weline\Currency\Model\Currency;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;

/**
 * 货币导入服务
 * 
 * 从第三方API导入汇率和货币信息
 */
class CurrencyImportService
{
    /**
     * @var ExchangeRateApiInterface
     */
    private ExchangeRateApiInterface $api;

    /**
     * @var Currency
     */
    private Currency $currencyModel;

    /**
     * 构造函数
     * 
     * @param ExchangeRateApiInterface $api 汇率API接口
     */
    public function __construct(ExchangeRateApiInterface $api)
    {
        $this->api = $api;
        $this->currencyModel = ObjectManager::getInstance(Currency::class);
    }

    /**
     * 导入汇率
     * 
     * @param string $baseCurrency 基准货币代码
     * @param array $targetCurrencies 目标货币代码数组，为空则导入所有支持的货币
     * @return array 导入结果，包含：total_count, success_count, fail_count, errors
     */
    public function importExchangeRates(string $baseCurrency, array $targetCurrencies = []): array
    {
        $result = [
            'total_count' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'errors' => []
        ];

        try {
            // 未指定目标时，只刷新目录里已有的币种，避免把 API 全量币种灌进本站目录。
            if ($targetCurrencies === []) {
                $existing = $this->currencyModel->clear()
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($existing as $row) {
                    $code = strtoupper(trim((string) $row->getCode()));
                    if ($code !== '') {
                        $targetCurrencies[] = $code;
                    }
                }
                $targetCurrencies = array_values(array_unique($targetCurrencies));
            }

            // 获取汇率数据（API：1 base = rate target；本站存储：1 target = rate base）
            $rates = $this->api->getExchangeRates($baseCurrency, $targetCurrencies);
            $baseCurrency = strtoupper(trim($baseCurrency));

            $result['total_count'] = count($rates);

            // 遍历更新汇率
            foreach ($rates as $currencyCode => $rate) {
                try {
                    $currencyCode = strtoupper(trim((string) $currencyCode));
                    $apiRate = (float) $rate;
                    if ($currencyCode === $baseCurrency) {
                        $storedRate = 1.0;
                    } elseif ($apiRate <= 0) {
                        throw new Exception(__('货币 %{1} 的 API 汇率无效: %{2}', [$currencyCode, $apiRate]));
                    } else {
                        $storedRate = 1.0 / $apiRate;
                    }
                    $this->updateCurrencyRate($currencyCode, $storedRate, $baseCurrency);
                    $result['success_count']++;
                } catch (\Exception $e) {
                    $result['fail_count']++;
                    $result['errors'][] = [
                        'currency' => $currencyCode,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = [
                'currency' => 'ALL',
                'error' => $e->getMessage()
            ];
        }
        
        return $result;
    }

    /**
     * 导入货币信息（包括格式化信息）
     * 
     * @param array $currencyCodes 货币代码数组，为空则导入所有支持的货币
     * @return array 导入结果
     */
    public function importCurrencyInfo(array $currencyCodes = []): array
    {
        $result = [
            'total_count' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'errors' => []
        ];

        // 如果没有指定货币代码，使用API支持的所有货币
        if (empty($currencyCodes)) {
            $currencyCodes = $this->api->getSupportedCurrencies();
        }

        $result['total_count'] = count($currencyCodes);

        foreach ($currencyCodes as $currencyCode) {
            try {
                $currencyInfo = $this->api->getCurrencyInfo($currencyCode);
                
                if ($currencyInfo === null) {
                    // API不支持该货币的详细信息，跳过
                    continue;
                }
                
                // 更新或创建货币信息
                $this->updateCurrencyInfo($currencyCode, $currencyInfo);
                $result['success_count']++;
                
            } catch (\Exception $e) {
                $result['fail_count']++;
                $result['errors'][] = [
                    'currency' => $currencyCode,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $result;
    }

    /**
     * 完整导入（汇率 + 货币信息）
     * 
     * @param string $baseCurrency 基准货币代码
     * @param array $targetCurrencies 目标货币代码数组
     * @return array 导入结果
     */
    public function importAll(string $baseCurrency, array $targetCurrencies = []): array
    {
        $result = [
            'rates' => [],
            'info' => [],
            'total_count' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'errors' => []
        ];

        // 导入汇率
        $ratesResult = $this->importExchangeRates($baseCurrency, $targetCurrencies);
        $result['rates'] = $ratesResult;
        
        // 导入货币信息
        $infoResult = $this->importCurrencyInfo($targetCurrencies);
        $result['info'] = $infoResult;
        
        // 汇总结果
        $result['total_count'] = $ratesResult['total_count'] + $infoResult['total_count'];
        $result['success_count'] = $ratesResult['success_count'] + $infoResult['success_count'];
        $result['fail_count'] = $ratesResult['fail_count'] + $infoResult['fail_count'];
        $result['errors'] = array_merge($ratesResult['errors'], $infoResult['errors']);
        
        return $result;
    }

    /**
     * 更新货币汇率
     * 
     * @param string $currencyCode 货币代码
     * @param float $rate 汇率
     * @param string $baseCurrency 基准货币代码
     * @return Currency
     * @throws \Exception
     */
    private function updateCurrencyRate(string $currencyCode, float $rate, string $baseCurrency): Currency
    {
        $currencyCode = strtoupper($currencyCode);
        
        // 查找货币
        $currency = $this->currencyModel->clear()
            ->where(Currency::schema_fields_CODE, $currencyCode)
            ->find()
            ->fetch();
        
        // 如果货币不存在，创建新货币
        if (!$currency->getId()) {
            // 尝试获取货币信息
            $currencyInfo = $this->api->getCurrencyInfo($currencyCode);
            
            $currency->setCode($currencyCode)
                ->setName($currencyCode) // 默认使用代码作为名称
                ->setRate($rate)
                ->setSymbol($currencyInfo['symbol'] ?? $currencyCode)
                ->setPosition($currencyInfo['position'] ?? 'left')
                ->setFormat('2,0') // 默认格式
                ->setStatus(true)
                ->setIcon($currencyInfo['icon'] ?? $currencyInfo['symbol'] ?? $currencyCode)
                ->setThousandSeparator($currencyInfo['thousand_separator'] ?? ',')
                ->setDecimalSeparator($currencyInfo['decimal_separator'] ?? '.')
                ->setBaseCurrency($baseCurrency);
        } else {
            // 更新汇率和基准货币
            $currency->setRate($rate)
                ->setBaseCurrency($baseCurrency);
        }
        
        $currency->save();
        
        return $currency;
    }

    /**
     * 预览切换基准货币后的汇率对照（只算不写库）
     *
     * @return array{
     *   old_base:string,
     *   new_base:string,
     *   formula:string,
     *   changes:list<array{code:string,name:string,old_rate:float,new_rate:float,old_meaning:string,new_meaning:string,is_new_base:bool,changed:bool}>,
     *   changed_count:int,
     *   total_count:int,
     *   errors:list<array{currency:string,error:string}>
     * }
     */
    public function previewRatesForNewBase(string $oldBaseCurrency, string $newBaseCurrency): array
    {
        $plan = $this->buildBaseCurrencyRatePlan($oldBaseCurrency, $newBaseCurrency);
        $changes = [];
        foreach ($plan['rows'] as $row) {
            $changes[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'old_rate' => $row['old_rate'],
                'new_rate' => $row['new_rate'],
                'old_meaning' => sprintf(
                    '1 %s = %s %s',
                    $row['code'],
                    $this->formatRateDisplay($row['old_rate']),
                    $plan['old_base']
                ),
                'new_meaning' => sprintf(
                    '1 %s = %s %s',
                    $row['code'],
                    $this->formatRateDisplay($row['new_rate']),
                    $plan['new_base']
                ),
                'is_new_base' => $row['is_new_base'],
                'changed' => $row['changed'],
            ];
        }

        return [
            'old_base' => $plan['old_base'],
            'new_base' => $plan['new_base'],
            'formula' => (string) __('新汇率 = 旧汇率 ÷ 新基准币在旧基准下的汇率'),
            'changes' => $changes,
            'changed_count' => (int) $plan['changed_count'],
            'total_count' => (int) $plan['total_count'],
            'errors' => $plan['errors'],
        ];
    }

    /**
     * 重新计算所有货币汇率（当基准货币改变时）
     * 
     * @param string $oldBaseCurrency 旧的基准货币代码
     * @param string $newBaseCurrency 新的基准货币代码
     * @param callable|null $progressCallback 进度回调函数，参数：($current, $total, $currencyCode)
     * @return array 更新结果，包含：total_count, success_count, fail_count, errors
     * @throws \Exception
     */
    public function recalculateRatesForNewBase(
        string $oldBaseCurrency, 
        string $newBaseCurrency,
        ?callable $progressCallback = null
    ): array {
        $result = [
            'total_count' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'errors' => []
        ];

        $plan = $this->buildBaseCurrencyRatePlan($oldBaseCurrency, $newBaseCurrency);
        $result['total_count'] = (int) $plan['total_count'];
        $result['errors'] = $plan['errors'];

        if ($plan['errors'] !== [] && $plan['rows'] === []) {
            return $result;
        }

        $current = 0;
        foreach ($plan['rows'] as $row) {
            $current++;
            $currencyCode = $row['code'];

            try {
                if ($progressCallback) {
                    $progressCallback($current, $result['total_count'], $currencyCode);
                }

                $currencyModel = $this->currencyModel->clear()
                    ->load($row['currency_id']);

                $currencyModel->setRate((float) $row['new_rate'])
                    ->setBaseCurrency($plan['new_base'])
                    ->save();

                $result['success_count']++;
            } catch (\Exception $e) {
                $result['fail_count']++;
                $result['errors'][] = [
                    'currency' => $currencyCode,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   old_base:string,
     *   new_base:string,
     *   total_count:int,
     *   changed_count:int,
     *   rows:list<array{currency_id:int,code:string,name:string,old_rate:float,new_rate:float,is_new_base:bool,changed:bool}>,
     *   errors:list<array{currency:string,error:string}>
     * }
     */
    private function buildBaseCurrencyRatePlan(string $oldBaseCurrency, string $newBaseCurrency): array
    {
        $oldBaseCurrency = strtoupper(trim($oldBaseCurrency));
        $newBaseCurrency = strtoupper(trim($newBaseCurrency));

        $plan = [
            'old_base' => $oldBaseCurrency,
            'new_base' => $newBaseCurrency,
            'total_count' => 0,
            'changed_count' => 0,
            'rows' => [],
            'errors' => [],
        ];

        if ($oldBaseCurrency === '' || $newBaseCurrency === '' || $oldBaseCurrency === $newBaseCurrency) {
            return $plan;
        }

        $currencies = $this->currencyModel->clear()
            ->select()
            ->fetchArray();

        if ($currencies === false || !is_array($currencies)) {
            $currencies = [];
        }

        $plan['total_count'] = count($currencies);

        $newBaseRate = 0.0;
        $foundNewBase = false;
        foreach ($currencies as $currency) {
            if (strtoupper((string) ($currency['code'] ?? '')) === $newBaseCurrency) {
                $newBaseRate = (float) ($currency['rate'] ?? 0);
                $foundNewBase = true;
                break;
            }
        }

        if (!$foundNewBase || $newBaseRate <= 0) {
            try {
                $apiRate = (float) $this->api->getExchangeRate($oldBaseCurrency, $newBaseCurrency);
                if ($apiRate <= 0) {
                    throw new \Exception(__('新基准货币的 API 汇率无效'));
                }
                $newBaseRate = 1.0 / $apiRate;
            } catch (\Exception $e) {
                $plan['errors'][] = [
                    'currency' => $newBaseCurrency,
                    'error' => __('无法获取新基准货币的汇率: %{1}', $e->getMessage())
                ];
                return $plan;
            }
        }

        if ($newBaseRate <= 0) {
            $plan['errors'][] = [
                'currency' => $newBaseCurrency,
                'error' => (string) __('新基准货币的汇率无效，无法重新计算'),
            ];
            return $plan;
        }

        foreach ($currencies as $currency) {
            $currencyCode = strtoupper((string) ($currency['code'] ?? ''));
            $oldRate = (float) ($currency['rate'] ?? 0);
            $isNewBase = $currencyCode === $newBaseCurrency;

            if ($isNewBase) {
                $newRate = 1.0;
            } elseif ($oldRate <= 0) {
                $plan['errors'][] = [
                    'currency' => $currencyCode,
                    'error' => (string) __('货币汇率无效'),
                ];
                continue;
            } else {
                $newRate = $oldRate / $newBaseRate;
            }

            // Persist with same decimal(10,4) rounding the DB column uses.
            $newRateRounded = round($newRate, 4);
            $oldRateRounded = round($oldRate, 4);
            $changed = abs($newRateRounded - $oldRateRounded) > 0.00005
                || $isNewBase
                || strtoupper((string) ($currency['base_currency'] ?? '')) !== $newBaseCurrency;

            if ($changed) {
                $plan['changed_count']++;
            }

            $plan['rows'][] = [
                'currency_id' => (int) ($currency['currency_id'] ?? 0),
                'code' => $currencyCode,
                'name' => (string) ($currency['name'] ?? $currencyCode),
                'old_rate' => $oldRateRounded,
                'new_rate' => $newRateRounded,
                'is_new_base' => $isNewBase,
                'changed' => $changed,
            ];
        }

        return $plan;
    }

    private function formatRateDisplay(float $rate): string
    {
        $formatted = number_format($rate, 4, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * 更新货币信息
     * 
     * @param string $currencyCode 货币代码
     * @param array $currencyInfo 货币信息数组
     * @return Currency
     * @throws \Exception
     */
    private function updateCurrencyInfo(string $currencyCode, array $currencyInfo): Currency
    {
        $currencyCode = strtoupper($currencyCode);
        
        // 查找货币
        $currency = $this->currencyModel->clear()
            ->where(Currency::schema_fields_CODE, $currencyCode)
            ->find()
            ->fetch();
        
        // 如果货币不存在，创建新货币
        if (!$currency->getId()) {
            $currency->setCode($currencyCode)
                ->setName($currencyCode)
                ->setRate(1.0) // 默认汇率
                ->setStatus(true);
        }
        
        // 更新货币信息
        if (isset($currencyInfo['symbol'])) {
            $currency->setSymbol($currencyInfo['symbol']);
        }
        
        if (isset($currencyInfo['position'])) {
            $currency->setPosition($currencyInfo['position']);
        }
        
        if (isset($currencyInfo['icon'])) {
            $currency->setIcon($currencyInfo['icon']);
        }
        
        if (isset($currencyInfo['thousand_separator'])) {
            $currency->setThousandSeparator($currencyInfo['thousand_separator']);
        }
        
        if (isset($currencyInfo['decimal_separator'])) {
            $currency->setDecimalSeparator($currencyInfo['decimal_separator']);
        }
        
        $currency->save();
        
        return $currency;
    }
}

