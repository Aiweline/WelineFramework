<?php
declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI模块安装脚本
 */
class Install
{
    public function install(Context $context): void
    {
        // 安装所有AI模块相关的数据表
        $this->installAiModels($context);
        $this->installTenantModels($context);
        $this->installI18nModels($context);
        $this->installMobileModels($context);
        $this->installBillingModels($context);
        $this->installExtensionModels($context);
    }

    private function installAiModels(Context $context): void
    {
        // AI模型相关表
        $models = [
            'Weline\Ai\Model\AiModel',
            'Weline\Ai\Model\AiApiKey',
            'Weline\Ai\Model\AiAssistant',
            'Weline\Ai\Model\AiScenarioAdapter',
            'Weline\Ai\Model\AiDefaultModel'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }

    private function installTenantModels(Context $context): void
    {
        // 租户相关表
        $models = [
            'Weline\Ai\Model\AiTenant',
            'Weline\Ai\Model\AiTenantUser'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }

    private function installI18nModels(Context $context): void
    {
        // 国际化相关表
        $models = [
            'Weline\Ai\Model\AiI18nContent'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }

    private function installMobileModels(Context $context): void
    {
        // 移动端相关表
        $models = [
            'Weline\Ai\Model\AiMobileDevice',
            'Weline\Ai\Model\AiMobileNotification'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }

    private function installBillingModels(Context $context): void
    {
        // 计费相关表
        $models = [
            'Weline\Ai\Model\AiBillingPlan',
            'Weline\Ai\Model\AiBillingInvoice'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }

    private function installExtensionModels(Context $context): void
    {
        // 扩展功能相关表
        $models = [
            'Weline\Ai\Model\AiAbTest',
            'Weline\Ai\Model\AiSecurityScan',
            'Weline\Ai\Model\AiThirdPartyIntegration',
            'Weline\Ai\Model\AiDeveloperTool',
            'Weline\Ai\Model\AiSupportTicket',
            'Weline\Ai\Model\AiMarketingCampaign',
            'Weline\Ai\Model\AiModelVersion',
            'Weline\Ai\Model\AiTrainingData',
            'Weline\Ai\Model\AiModelDeployment',
            'Weline\Ai\Model\AiModelBenchmark',
            'Weline\Ai\Model\AiContentSafety'
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            if (method_exists($model, 'install')) {
                $setup = new ModelSetup($model);
                $model->install($setup, $context);
            }
        }
    }
}
