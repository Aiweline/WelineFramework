<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderAdminAmountAdjustmentPolicyInterface;

/**
 * 后台金额调整类型策略 Registry：extends 扫描；无策略 = 不可调。
 */
final class OrderAdminAmountAdjustmentPolicyRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_order/adminamountadjustmentpolicy/';

    /** @var array<string, OrderAdminAmountAdjustmentPolicyInterface> */
    private array $byCode = [];

    private bool $booted = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<OrderAdminAmountAdjustmentPolicyInterface> $policies
     */
    public static function forTesting(array $policies = []): self
    {
        $reg = new self(autoLoadExtends: false);
        foreach ($policies as $policy) {
            $reg->register($policy);
        }
        $reg->booted = true;

        return $reg;
    }

    public function register(OrderAdminAmountAdjustmentPolicyInterface $policy): void
    {
        $code = $this->normalize($policy->getOrderTypeCode());
        if ($code === '') {
            throw new OrderConflictException(
                'order_admin_amount_policy_code_empty',
                (string)__('金额调整策略 order_type 不能为空'),
            );
        }
        if (isset($this->byCode[$code])) {
            throw new OrderConflictException(
                'order_admin_amount_policy_duplicate',
                (string)__('重复的金额调整策略：%{1}', [$code]),
                ['code' => $code],
            );
        }
        $this->byCode[$code] = $policy;
    }

    public function get(string $orderType): ?OrderAdminAmountAdjustmentPolicyInterface
    {
        $this->boot();

        return $this->byCode[$this->normalize($orderType)] ?? null;
    }

    public function requireSupporting(string $orderType): OrderAdminAmountAdjustmentPolicyInterface
    {
        $policy = $this->get($orderType);
        if ($policy === null || !$policy->supports()) {
            throw new OrderConflictException(
                'order_admin_amount_policy_unsupported',
                (string)__('该订单类型不支持后台金额调整'),
                ['order_type' => $this->normalize($orderType)],
            );
        }

        return $policy;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if (!$this->autoLoadExtends || !class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Order') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!\is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, OrderAdminAmountAdjustmentPolicyInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof OrderAdminAmountAdjustmentPolicyInterface) {
                        $this->register($instance);
                    }
                } catch (OrderConflictException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if (\function_exists('w_log_error')) {
                        w_log_error('Order admin amount policy load failed: ' . $className . ' ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $extension */
    private function extensionClass(string $sourceModule, array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $className = trim((string)($extension[$key] ?? ''));
            if ($className !== '') {
                return ltrim($className, '\\');
            }
        }
        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        if (!str_starts_with(strtolower($relativePath), 'extends/module/')) {
            return '';
        }
        $classPath = substr($relativePath, strlen('extends/module/'));
        if (!str_ends_with(strtolower($classPath), '.php')) {
            return '';
        }
        $classPath = substr($classPath, 0, -4);
        $moduleNamespace = str_replace('_', '\\', trim($sourceModule));
        if ($moduleNamespace === '' || $classPath === '') {
            return '';
        }

        return $moduleNamespace . '\\Extends\\Module\\' . str_replace('/', '\\', $classPath);
    }

    private function normalize(string $code): string
    {
        return strtolower(trim($code));
    }
}
