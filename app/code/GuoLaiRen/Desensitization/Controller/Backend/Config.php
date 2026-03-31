<?php

declare(strict_types=1);

/*
 * 模块配置管理控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\extends\Weline_Ai\Adapter\DesensitizationAdapter;
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class Config extends BackendController
{
    private const MODULE = 'GuoLaiRen_Desensitization';
    private const AREA = SystemConfig::area_BACKEND;

    /**
     * 获取AI模型列表
     *
     * @return mixed
     */
    public function getModels()
    {
        try {
            /** @var DesensitizationService $service */
            $service = ObjectManager::getInstance(DesensitizationService::class);
            $models = $service->getAvailableModels();
            return $this->jsonResponse([
                'success' => true,
                'message' => '获取成功',
                'data' => $models
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '获取模型列表失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

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
            'default_model' => '',
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

        // 获取可用的AI模型列表（需早于默认模型显示名计算）
        /** @var DesensitizationService $service */
        $service = ObjectManager::getInstance(DesensitizationService::class);
        $models = $service->getAvailableModels();
        $this->assign('models', $models);

        // 设置默认模型选择器的初始值
        $defaultModelCode = $desensitizationParams['default_model'] ?? '';
        $defaultModelDisplay = '使用系统默认模型';

        if (!empty($defaultModelCode)) {
            // 从模型列表中查找对应的显示名称
            $found = false;
            foreach ($models as $model) {
                if (($model['code'] ?? '') === $defaultModelCode) {
                    $supplier = $model['supplier'] ?? '';
                    $version = $model['version'] ?? '';
                    $name = $model['name'] ?? $defaultModelCode;
                    $defaultModelDisplay = trim($name . ' (' . $supplier . ') - ' . $version);
                    $found = true;
                    break;
                }
            }
            // 若模型表中未找到对应记录，则直接显示 code
            if (!$found) {
                $defaultModelDisplay = $defaultModelCode;
            }
        }

        $this->assign('default_model', $defaultModelCode);
        $this->assign('default_model_display', $defaultModelDisplay);

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

        // 获取敏感词规则（带默认值）
        $metaRules = $systemConfig->getConfig('meta_rules', self::MODULE, self::AREA) ?? '';
        $googleRules = $systemConfig->getConfig('google_rules', self::MODULE, self::AREA) ?? '';

        $defaultMeta = "暴力内容\n仇恨言论\n虚假信息\n骚扰\n自残\n性剥削\n恐怖主义\n成人性内容\n儿童性剥削\n诈骗\n知识产权侵权\n隐私侵犯\n人工智能生成的性内容";
        $defaultGoogle = "危险商品或服务\n不当内容\n敏感事件\n受监管的内容\n抄袭内容\n非法活动\n限制内容\n欺诈行为";

        if (!is_string($metaRules) || trim($metaRules) === '') {
            $metaRules = $defaultMeta;
        }
        if (!is_string($googleRules) || trim($googleRules) === '') {
            $googleRules = $defaultGoogle;
        }

        $this->assign('meta_rules', $metaRules);
        $this->assign('google_rules', $googleRules);

        return $this->fetch();
    }

    /**
     * 保存配置
     *
     * @return mixed
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '仅支持POST请求'
            ]);
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

            // 保存Meta敏感词规则
            if (isset($data['meta_rules'])) {
                $systemConfig->setConfig(
                    'meta_rules',
                    $data['meta_rules'],
                    self::MODULE,
                    self::AREA
                );
            }

            // 保存Google敏感词规则
            if (isset($data['google_rules'])) {
                $systemConfig->setConfig(
                    'google_rules',
                    $data['google_rules'],
                    self::MODULE,
                    self::AREA
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => '配置保存成功'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '保存失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 重置配置
     *
     * @return mixed
     */
    public function reset()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '仅支持POST请求'
            ]);
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
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '无效的适配器类型'
                ]);
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => '配置已重置'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '重置失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 更新敏感词规则（从Meta/Google爬取）
     *
     * @return mixed
     */
    public function updateRules()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '仅支持POST请求'
            ]);
        }

        // 开启输出缓冲，防止任何意外的输出
        ob_start();

        try {
            $platform = $this->request->getParam('platform', 'meta');

            if ($platform === 'meta') {
                $url = 'https://transparency.meta.com/zh-cn/policies/community-standards/';
            } elseif ($platform === 'google') {
                $url = 'https://transparency.google/intl/en/our-policies/product-terms/google-ads/';
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '不支持的平台'
                ]);
            }

            // 读取默认模型配置（必须配置）
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            $desParamsJson = $systemConfig->getConfig('desensitization_adapter_params', self::MODULE, self::AREA);
            $desParams = $desParamsJson ? json_decode($desParamsJson, true) : [];
            $modelCode = (string)($desParams['default_model'] ?? '');
            if ($modelCode === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '请先在“默认AI模型”中配置模型后再更新规则'
                ]);
            }

            // 使用AI提取敏感词清单（直接传URL给适配器处理抓取与解析）
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);

            $adapterCode = ObjectManager::getInstance(DesensitizationAdapter::class)->getCode();
            
            try {
                $rulesText = $aiService->generate(
                    $url, // 直接传URL，适配器extract模式内部负责抓取与提纯
                    $modelCode,
                    $adapterCode,
                    'zh_Hans_CN',
                    ['mode' => 'extract']
                );
            } catch (\Throwable $aiError) {
                throw new \Exception('AI调用失败: ' . $aiError->getMessage());
            }

            // 检查AI返回结果
            if (!is_string($rulesText) || trim($rulesText) === '') {
                throw new \Exception('AI未返回有效规则文本');
            }

            // 保存到配置（增量插入、去重）
            $configKey = $platform === 'meta' ? 'meta_rules' : 'google_rules';
            $existingText = (string)($systemConfig->getConfig($configKey, self::MODULE, self::AREA) ?? '');

            // 统一换行符并拆分为行
            $splitToSet = function (string $text): array {
                $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
                $lines = array_map('trim', array_filter(explode("\n", $normalized), function ($v) {
                    return $v !== '';
                }));
                // 同时把逗号分隔的情况也拆开（兼容用户粘贴的逗号列表）
                $expanded = [];
                foreach ($lines as $line) {
                    $parts = array_map('trim', preg_split('/[，,]/u', $line));
                    foreach ($parts as $p) {
                        if ($p !== '') $expanded[] = $p;
                    }
                }
                // 去重（不区分大小写）
                $set = [];
                foreach ($expanded as $item) {
                    $key = mb_strtolower($item, 'UTF-8');
                    $set[$key] = $item; // 保留原大小写
                }
                return array_values($set);
            };

            $oldSet = $splitToSet($existingText);
            $newSet = $splitToSet($rulesText);

            // 合并去重
            $merged = [];
            $map = [];
            foreach ([$oldSet, $newSet] as $arr) {
                foreach ($arr as $it) {
                    $k = mb_strtolower($it, 'UTF-8');
                    if (!isset($map[$k])) {
                        $map[$k] = true;
                        $merged[] = $it;
                    }
                }
            }

            // 还原为换行文本
            $mergedText = implode("\n", $merged);
            $systemConfig->setConfig($configKey, $mergedText, self::MODULE, self::AREA);

            return $this->jsonResponse([
                'success' => true,
                'message' => '规则已更新（AI提取并合并）',
                'data' => $mergedText
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '更新失败: ' . $e->getMessage()
            ]);
        } finally {
            // 清理输出缓冲
            ob_end_clean();
        }
    }

    /**
     * 兼容POST的规则更新入口：/backend/config/saveRules
     */
    public function saveRules()
    {
        // 直接复用 updateRules 的实现
        return $this->updateRules();
    }
}

