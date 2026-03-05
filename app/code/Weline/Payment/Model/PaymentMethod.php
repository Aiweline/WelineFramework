<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫科技 编写，所有解释权归 weline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '支付方式表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_is_active', columns: ['is_active'])]
#[Index(name: 'idx_sort_order', columns: ['sort_order'])]
class PaymentMethod extends AbstractModel
{

    public const schema_table = 'weline_payment_method';
    public const schema_primary_key = 'method_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '支付方式ID')]
    public const schema_fields_ID = 'method_id';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '支付方式代号')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 100, nullable: false, comment: '支付方式名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 100, nullable: false, comment: '支付提供商模块名')]
    public const schema_fields_PROVIDER_MODULE = 'provider_module';
    #[Col('varchar', 255, nullable: false, comment: '支付提供者类名')]
    public const schema_fields_PROVIDER_CLASS = 'provider_class';
    #[Col('int', 1, default: 0, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('text', comment: '配置信息JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['method_id'];
    public array $_index_sort_keys = ['method_id', 'code', 'is_active', 'sort_order'];

    /**
     * 是否启用
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }

    /**
     * 获取配置
     *
     * @return array
     */
    public function getConfigData(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            return json_decode($config, true) ?? [];
        }
        return $config;
    }

    /**
     * 设置配置
     *
     * @param array $config
     * @return $this
     */
    public function setConfigData(array $config): static
    {
        return $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取支持的优惠方式代号列表
     *
     * @return array
     */
    public function getSupportedDiscountActions(): array
    {
        $config = $this->getConfigData();
        return $config['supported_discount_actions'] ?? [];
    }

    /**
     * 设置支持的优惠方式代号列表
     *
     * @param array $actions 优惠方式代号数组
     * @return $this
     */
    public function setSupportedDiscountActions(array $actions): static
    {
        $config = $this->getConfigData();
        $config['supported_discount_actions'] = $actions;
        return $this->setConfigData($config);
    }

    /**
     * 检查是否支持特定优惠方式
     *
     * @param string $actionCode 优惠方式代号（如：discount_fixed_amount）
     * @return bool
     */
    public function supportsDiscountAction(string $actionCode): bool
    {
        $supported = $this->getSupportedDiscountActions();

        // 如果为空数组，表示支持所有优惠方式（向后兼容）
        if (empty($supported)) {
            // 尝试从支付提供商获取默认支持
            $providerSupported = $this->getProviderSupportedActions();
            if ($providerSupported !== null) {
                // 如果提供商返回 null，表示不支持任何
                if (empty($providerSupported)) {
                    return false;
                }
                // 如果提供商返回空数组，表示支持所有
                return in_array($actionCode, $providerSupported, true);
            }
            // 如果提供商也未实现，默认支持所有（向后兼容）
            return true;
        }

        return in_array($actionCode, $supported, true);
    }

    /**
     * 从支付提供商获取支持的优惠方式
     *
     * @return array|null
     */
    private function getProviderSupportedActions(): ?array
    {
        try {
            $providerClass = $this->getData(self::schema_fields_PROVIDER_CLASS);
            if (empty($providerClass) || !class_exists($providerClass)) {
                return null;
            }

            $provider = \Weline\Framework\Manager\ObjectManager::getInstance($providerClass);
            if ($provider instanceof \Weline\Payment\Interface\PaymentProviderInterface) {
                // 检查是否实现了新方法
                if (method_exists($provider, 'getSupportedDiscountActions')) {
                    return $provider->getSupportedDiscountActions();
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }

        return null;
    }
}
