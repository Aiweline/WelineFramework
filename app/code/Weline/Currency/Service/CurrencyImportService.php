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
            // 获取汇率数据
            $rates = $this->api->getExchangeRates($baseCurrency, $targetCurrencies);
            
            $result['total_count'] = count($rates);
            
            // 遍历更新汇率
            foreach ($rates as $currencyCode => $rate) {
                try {
                    $this->updateCurrencyRate($currencyCode, $rate, $baseCurrency);
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
            ->where(Currency::fields_CODE, $currencyCode)
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

        // 如果基准货币没有改变，直接返回
        if ($oldBaseCurrency === $newBaseCurrency) {
            return $result;
        }

        // 获取所有货币
        $currencies = $this->currencyModel->clear()
            ->select()
            ->fetchOrigin();

        // 如果查询失败或没有数据，返回空数组
        if ($currencies === false || !is_array($currencies)) {
            $currencies = [];
        }

        $result['total_count'] = count($currencies);

        // 获取新旧基准货币的汇率（相对于旧基准货币）
        $oldBaseRate = 1.0; // 旧基准货币的汇率总是1
        $newBaseRate = 1.0; // 新基准货币的汇率（需要查找）

        // 查找新基准货币在旧基准货币下的汇率
        foreach ($currencies as $currency) {
            if ($currency['code'] === $newBaseCurrency) {
                $newBaseRate = (float)$currency['rate'];
                break;
            }
        }

        // 如果找不到新基准货币，尝试从API获取
        if ($newBaseRate === 1.0 && $newBaseCurrency !== $oldBaseCurrency) {
            try {
                $newBaseRate = $this->api->getExchangeRate($oldBaseCurrency, $newBaseCurrency);
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'currency' => $newBaseCurrency,
                    'error' => __('无法获取新基准货币的汇率: %{1}', $e->getMessage())
                ];
                return $result;
            }
        }

        // 如果新基准货币的汇率是0，无法计算
        if ($newBaseRate <= 0) {
            throw new \Exception(__('新基准货币的汇率无效，无法重新计算'));
        }

        // 重新计算所有货币的汇率
        $current = 0;
        foreach ($currencies as $currency) {
            $current++;
            $currencyCode = $currency['code'];
            
            try {
                // 调用进度回调
                if ($progressCallback) {
                    $progressCallback($current, $result['total_count'], $currencyCode);
                }

                // 如果是新基准货币，汇率设为1
                if ($currencyCode === $newBaseCurrency) {
                    $newRate = 1.0;
                } else {
                    // 计算新汇率：新汇率 = 旧汇率 / 新基准货币在旧基准下的汇率
                    $oldRate = (float)$currency['rate'];
                    if ($oldRate <= 0) {
                        throw new \Exception(__('货币汇率无效'));
                    }
                    $newRate = $oldRate / $newBaseRate;
                }

                // 更新货币汇率和基准货币
                $currencyModel = $this->currencyModel->clear()
                    ->load($currency['currency_id']);
                
                $currencyModel->setRate($newRate)
                    ->setBaseCurrency($newBaseCurrency)
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
            ->where(Currency::fields_CODE, $currencyCode)
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

