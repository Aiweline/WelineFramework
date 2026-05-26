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
use Weline\Payment\Model\PaymentMethodConfig;
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
    private ?PaymentScopeConfigService $scopeConfigService;

    public function __construct(
        PaymentProviderScanner $providerScanner,
        ObjectManager $objectManager,
        ?PaymentScopeConfigService $scopeConfigService = null
    ) {
        $this->providerScanner = $providerScanner;
        $this->objectManager = $objectManager;
        $this->scopeConfigService = $scopeConfigService;
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
                w_log_error("注册支付提供商失败: " . $e->getMessage());
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
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            // 新建
            $paymentMethod->setData(PaymentMethod::schema_fields_CODE, $code)
                ->setData(PaymentMethod::schema_fields_NAME, $provider->getName())
                ->setData(PaymentMethod::schema_fields_PROVIDER_MODULE, $moduleName)
                ->setData(PaymentMethod::schema_fields_PROVIDER_CLASS, $reflection->getName())
                ->setData(PaymentMethod::schema_fields_IS_ACTIVE, 0)
                ->setData(PaymentMethod::schema_fields_SORT_ORDER, 0)
                ->setData(PaymentMethod::schema_fields_CONFIG, json_encode([], JSON_UNESCAPED_UNICODE))
                ->save();
        } else {
            // 更新
            $paymentMethod->setData(PaymentMethod::schema_fields_NAME, $provider->getName())
                ->setData(PaymentMethod::schema_fields_PROVIDER_MODULE, $moduleName)
                ->setData(PaymentMethod::schema_fields_PROVIDER_CLASS, $reflection->getName())
                ->save();
        }

        return $paymentMethod;
    }

    /**
     * 获取所有启用的支付方式
     * 
     * @return PaymentMethod[]
     */
    public function getActiveMethods(array $context = []): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $methods = $paymentMethod->where(PaymentMethod::schema_fields_IS_ACTIVE, 1)
            ->order(PaymentMethod::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();

        if (\is_object($methods) && method_exists($methods, 'getItems')) {
            $methods = $methods->getItems();
        }
        if (!\is_array($methods) || $context === []) {
            return $methods;
        }

        return array_values(array_filter($methods, function (mixed $method) use ($context): bool {
            return $method instanceof PaymentMethod && $this->isMethodActiveForScope($method, $context);
        }));
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
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
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
    public function getProviderInstance(PaymentMethod $paymentMethod, array $context = []): ?PaymentProviderInterface
    {
        $className = $paymentMethod->getData(PaymentMethod::schema_fields_PROVIDER_CLASS);
        if (empty($className) || !class_exists($className)) {
            return null;
        }

        try {
            $provider = $this->objectManager->getInstance($className);
            if ($provider instanceof PaymentProviderInterface) {
                // 设置配置
                $profile = $this->getScopeProfile($paymentMethod, $context);
                $config = $profile ? $profile->getConfigData() : $paymentMethod->getConfigData();
                $provider->setConfig($config);
                return $provider;
            }
        } catch (\Throwable $e) {
            w_log_error("获取支付提供商实例失败: {$className}, 错误: " . $e->getMessage());
        }

        return null;
    }

    public function isMethodActiveForScope(PaymentMethod $paymentMethod, array $context = []): bool
    {
        if ($context === []) {
            return $paymentMethod->isActive();
        }

        $profile = $this->getScopeProfile($paymentMethod, $context);
        if (!$profile) {
            return $paymentMethod->isActive();
        }

        return $profile->isEnabled()
            && (string) $profile->getData(PaymentMethodConfig::schema_fields_TEST_STATUS) === PaymentMethodConfig::TEST_STATUS_PASSED;
    }

    public function getScopeProfile(PaymentMethod $paymentMethod, array $context = []): ?PaymentMethodConfig
    {
        $methodCode = (string) $paymentMethod->getData(PaymentMethod::schema_fields_CODE);
        if ($methodCode === '') {
            return null;
        }

        $scope = $this->getScopeConfigService()->resolveScope($context);
        try {
            return $this->getScopeConfigService()->getProfile(
                $methodCode,
                $scope['scope_type'],
                $scope['scope_code'],
                $scope['environment']
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        if ($this->scopeConfigService === null) {
            $this->scopeConfigService = new PaymentScopeConfigService();
        }

        return $this->scopeConfigService;
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

