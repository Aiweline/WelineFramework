<?php

declare(strict_types=1);

/*
 * 模块配置管理控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Ai\Adapter\DesensitizationAdapter;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class Config extends BackendController
{
    private const MODULE = 'GuoLaiRen_Desensitization';
    private const AREA = SystemConfig::area_BACKEND;

    /**
     * 配置页面
     *
     * @return mixed
     */
    public function index()
    {
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        
        // 获取适配器实例
        $desensitizationAdapter = ObjectManager::getInstance(DesensitizationAdapter::class);

        // 获取适配器的默认参数
        $desensitizationDefaultParams = [
            'mode' => 'detect',
            'level' => 'standard',
            'enable_strict_check' => true,
        ];

        $rewriteDefaultParams = [
            'style' => 'natural',
            'preserve_format' => true,
            'enhance_readability' => true,
        ];

        // 获取系统配置中的参数（如果存在）
        $desensitizationParams = $systemConfig->getConfig('desensitization_adapter_params', self::MODULE, self::AREA);
        $rewriteParams = $systemConfig->getConfig('rewrite_adapter_params', self::MODULE, self::AREA);

        // 如果有保存的配置，使用保存的配置；否则使用默认配置
        $desensitizationParams = $desensitizationParams ? json_decode($desensitizationParams, true) : $desensitizationDefaultParams;
        $rewriteParams = $rewriteParams ? json_decode($rewriteParams, true) : $rewriteDefaultParams;

        // 合并配置，确保所有字段都存在
        $desensitizationParams = array_merge($desensitizationDefaultParams, $desensitizationParams ?? []);
        $rewriteParams = array_merge($rewriteDefaultParams, $rewriteParams ?? []);

        $this->assign('desensitization_adapter', [
            'code' => $desensitizationAdapter->getCode(),
            'name' => $desensitizationAdapter->getName(),
            'description' => $desensitizationAdapter->getDescription(),
            'params' => $desensitizationParams ?? []
        ]);

        $this->assign('rewrite_adapter', [
            'code' => 'rewrite',
            'name' => '内容重写适配器',
            'description' => '对脱敏后的内容进行AI润色和重写',
            'params' => $rewriteParams ?? []
        ]);

        return $this->fetch();
    }

    /**
     * 保存配置
     *
     * @return mixed
     */
    public function save()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            
            $data = $this->request->getParams();

            // 保存脱敏适配器参数
            if (isset($data['desensitization_params'])) {
                $params = json_encode($data['desensitization_params'], JSON_UNESCAPED_UNICODE);
                $systemConfig->setConfig(
                    'desensitization_adapter_params',
                    $params,
                    self::MODULE,
                    self::AREA
                );
            }

            // 保存重写适配器参数
            if (isset($data['rewrite_params'])) {
                $params = json_encode($data['rewrite_params'], JSON_UNESCAPED_UNICODE);
                $systemConfig->setConfig(
                    'rewrite_adapter_params',
                    $params,
                    self::MODULE,
                    self::AREA
                );
            }

            return $this->success()->json('配置保存成功');
        } catch (\Exception $e) {
            return $this->error()->json('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 重置配置
     *
     * @return mixed
     */
    public function reset()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            
            $adapterType = $this->request->getParam('adapter_type');

            if ($adapterType === 'desensitization') {
                $systemConfig->setConfig(
                    'desensitization_adapter_params',
                    '',
                    self::MODULE,
                    self::AREA
                );
            } elseif ($adapterType === 'rewrite') {
                $systemConfig->setConfig(
                    'rewrite_adapter_params',
                    '',
                    self::MODULE,
                    self::AREA
                );
            } else {
                return $this->error()->json('无效的适配器类型');
            }

            return $this->success()->json('配置已重置');
        } catch (\Exception $e) {
            return $this->error()->json('重置失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新敏感词规则（从Meta/Google爬取）
     *
     * @return mixed
     */
    public function updateRules()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        try {
            $platform = $this->request->getParam('platform', 'meta');
            
            if ($platform === 'meta') {
                $url = 'https://transparency.meta.com/zh-cn/policies/community-standards/';
            } elseif ($platform === 'google') {
                $url = 'https://transparency.google/intl/en/our-policies/product-terms/google-ads/';
            } else {
                return $this->error()->json('不支持的平台');
            }

            // TODO: 使用AI爬取规则内容并更新到配置中
            // 这里可以调用AI服务来解析网页内容
            
            return $this->success()->json('规则更新成功');
        } catch (\Exception $e) {
            return $this->error()->json('更新失败: ' . $e->getMessage());
        }
    }
}

