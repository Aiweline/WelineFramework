<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Service;

use Weline\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\Framework\Manager\ObjectManager;

/**
 * 支付方式管理器
 * 
 * 管理支付方式的注册、启用、禁用等操作
 */
class PaymentMethodManager
{
    private PaymentProviderScanner $providerScanner;
    private ObjectManager $objectManager;

    public function __construct(
        PaymentProviderScanner $providerScanner,
        ObjectManager $objectManager
    ) {
        $this->providerScanner = $providerScanner;
        $this->objectManager = $objectManager;
    }

    /**
     * 注册所有支付提供商
     * 
     * 扫描所有支付提供商并注册到数据库
     * 
     * @return int 注册的支付方式数量
     */
    public function registerAllProviders(): int
    {
        $providers = $this->providerScanner->getProviderInstances(true);
        $count = 0;

        foreach ($providers as $provider) {
            try {
                $this->registerProvider($provider);
                $count++;
            } catch (\Throwable $e) {
                error_log("注册支付提供商失败: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 注册单个支付提供商
     * 
     * @param PaymentProviderInterface $provider
     * @return PaymentMethod
     */
    public function registerProvider(PaymentProviderInterface $provider): PaymentMethod
    {
        $code = $provider->getCode();
        $reflection = new \ReflectionClass($provider);
        $moduleName = $this->getModuleNameFromClass($reflection->getName());
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load($code, PaymentMethod::fields_CODE);
        
        if (!$paymentMethod->getId()) {
            // 新建
            $paymentMethod->setData(PaymentMethod::fields_CODE, $code)
                ->setData(PaymentMethod::fields_NAME, $provider->getName())
                ->setData(PaymentMethod::fields_PROVIDER_MODULE, $moduleName)
                ->setData(PaymentMethod::fields_PROVIDER_CLASS, $reflection->getName())
                ->setData(PaymentMethod::fields_IS_ACTIVE, 0)
                ->setData(PaymentMethod::fields_SORT_ORDER, 0)
                ->setData(PaymentMethod::fields_CONFIG, json_encode([], JSON_UNESCAPED_UNICODE))
                ->save();
        } else {
            // 更新
            $paymentMethod->setData(PaymentMethod::fields_NAME, $provider->getName())
                ->setData(PaymentMethod::fields_PROVIDER_MODULE, $moduleName)
                ->setData(PaymentMethod::fields_PROVIDER_CLASS, $reflection->getName())
                ->save();
        }

        return $paymentMethod;
    }

    /**
     * 获取所有启用的支付方式
     * 
     * @return PaymentMethod[]
     */
    public function getActiveMethods(): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        return $paymentMethod->where(PaymentMethod::fields_IS_ACTIVE, 1)
            ->order(PaymentMethod::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
    }

    /**
     * 根据代码获取支付方式
     * 
     * @param string $code
     * @return PaymentMethod|null
     */
    public function getMethodByCode(string $code): ?PaymentMethod
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load($code, PaymentMethod::fields_CODE);
        
        if (!$paymentMethod->getId()) {
            return null;
        }

        return $paymentMethod;
    }

    /**
     * 获取支付提供商实例
     * 
     * @param PaymentMethod $paymentMethod
     * @return PaymentProviderInterface|null
     */
    public function getProviderInstance(PaymentMethod $paymentMethod): ?PaymentProviderInterface
    {
        $className = $paymentMethod->getData(PaymentMethod::fields_PROVIDER_CLASS);
        if (empty($className) || !class_exists($className)) {
            return null;
        }

        try {
            $provider = $this->objectManager->getInstance($className);
            if ($provider instanceof PaymentProviderInterface) {
                // 设置配置
                $config = $paymentMethod->getConfigData();
                $provider->setConfig($config);
                return $provider;
            }
        } catch (\Throwable $e) {
            error_log("获取支付提供商实例失败: {$className}, 错误: " . $e->getMessage());
        }

        return null;
    }

    /**
     * 从类名获取模块名
     * 
     * @param string $className
     * @return string
     */
    private function getModuleNameFromClass(string $className): string
    {
        // 从类名推断模块名
        // 例如: Weline\Payment\Alipay\Provider\AlipayProvider -> Weline_Payment_Alipay
        $parts = explode('\\', $className);
        $moduleParts = [];
        
        foreach ($parts as $part) {
            if (empty($moduleParts) && preg_match('/^[A-Z][a-zA-Z0-9_]*$/', $part)) {
                $moduleParts[] = $part;
            } elseif (!empty($moduleParts)) {
                // 如果遇到 Extends、PaymentProvider 等关键词，停止
                if (in_array($part, ['Extends', 'PaymentProvider', 'Provider'])) {
                    break;
                }
                $moduleParts[] = $part;
            }
        }
        
        return implode('_', $moduleParts);
    }
}

