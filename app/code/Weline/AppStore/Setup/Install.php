<?php
declare(strict_types=1);

namespace Weline\AppStore\Setup;

use Weline\Framework\App\Env;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * AppStore 模块安装器
 *
 * 添加 EAV 属性和初始化配置
 */
class Install implements InstallInterface
{
    /**
     * 安装时执行
     *
     * @param Setup $setup
     * @param Context $context
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->addInstallableModuleAttribute();
    }

    /**
     * 添加「可安装模块」属性到产品实体
     *
     * 该属性用于标识产品是否为可自动安装的模块
     */
    private function addInstallableModuleAttribute(): void
    {
        // 检查 WeShop_Product 模块是否存在
        $productClass = '\WeShop\Product\Model\Product';
        if (!class_exists($productClass)) {
            // 如果 WeShop_Product 不存在，跳过 EAV 属性添加
            // 后续可以在有产品模块时手动添加
            return;
        }

        try {
            // 获取产品 EAV 模型
            $productModel = ObjectManager::getInstance($productClass);

            // 检查属性是否已存在
            $existingAttribute = $productModel->getAttribute('installable_module');
            if ($existingAttribute) {
                return;
            }

            // 添加「可安装模块」属性
            $productModel->addAttribute(
                'installable_module',      // 属性代码
                __('可安装模块'),          // 属性名
                'select',                  // 类型：下拉选择
                false,                     // 非多值
                true,                      // 有选项
                false,                     // 非系统属性
                true,                      // 启用
                'appstore',                // 属性组：应用商店
                'default'                  // 属性集
            );

            // 添加选项：是/否
            $attribute = $productModel->getAttribute('installable_module');
            if ($attribute) {
                // 添加选项
                $options = [
                    ['code' => 'no', 'value' => __('否')],
                    ['code' => 'yes', 'value' => __('是')],
                ];

                foreach ($options as $optionData) {
                    $option = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Option::class);
                    $option->setAttributeId($attribute->getId());
                    $option->setCode($optionData['code']);
                    $option->setValue($optionData['value']);
                    $option->save();
                }
            }
        } catch (\Exception $e) {
            // 记录错误但不中断安装
            Env::log_error(
                'appstore_install',
                'AppStore Install: Failed to add EAV attribute: ' . $e->getMessage()
            );
        }
    }
}
