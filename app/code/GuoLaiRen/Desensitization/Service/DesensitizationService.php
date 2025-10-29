<?php

declare(strict_types=1);

/*
 * 脱敏服务类
 * 提供多种脱敏方法，包括规则脱敏和AI智能脱敏
 */

namespace GuoLaiRen\Desensitization\Service;

use GuoLaiRen\Desensitization\Model\DesensitizationLog;
use GuoLaiRen\Desensitization\Model\DesensitizationRule;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\SystemConfig\Model\SystemConfig;

class DesensitizationService
{
    private DesensitizationRule $ruleModel;
    private DesensitizationLog $logModel;
    private array $config;

    public function __construct(
        DesensitizationRule $ruleModel,
        DesensitizationLog $logModel
    ) {
        $this->ruleModel = $ruleModel;
        $this->logModel = $logModel;
        $this->config = $this->getConfig();
    }

    /**
     * 执行脱敏处理
     *
     * @param string $content 原始内容
     * @param string $method 脱敏方法：regex（正则）, ai（AI智能）, custom（自定义）
     * @param array $options 选项：['rule_type' => 'email', 'use_ai' => true, ...]
     * @return string 脱敏后的内容
     * @throws Exception
     */
    public function desensitize(string $content, string $method = 'regex', array $options = []): string
    {
        if (empty($content)) {
            return $content;
        }

        $startTime = microtime(true);
        $desensitized = $content;
        $ruleId = 0;

        try {
            switch ($method) {
                case 'ai':
                    $desensitized = $this->desensitizeWithAI($content, $options);
                    $ruleId = $options['rule_id'] ?? 0;
                    break;
                case 'custom':
                    $desensitized = $this->desensitizeWithCustomRules($content, $options);
                    $ruleId = $options['rule_id'] ?? 0;
                    break;
                case 'regex':
                default:
                    $desensitized = $this->desensitizeWithRegex($content, $options);
                    $ruleId = $options['rule_id'] ?? 0;
                    break;
            }

            // 记录脱敏日志
            if ($this->config['logging']['enabled']) {
                $executionTime = microtime(true) - $startTime;
                $this->logModel->reset();
                $this->logModel->logOperation(
                    $content,
                    $desensitized,
                    $ruleId,
                    $method,
                    $executionTime
                );
            }

            return $desensitized;
        } catch (\Exception $e) {
            error_log("脱敏处理失败: " . $e->getMessage());
            throw new Exception("脱敏处理失败: " . $e->getMessage());
        }
    }

    /**
     * 使用正则表达式脱敏
     *
     * @param string $content
     * @param array $options
     * @return string
     */
    private function desensitizeWithRegex(string $content, array $options): string
    {
        $result = $content;

        // 如果指定了规则类型，使用该类型的规则
        if (!empty($options['rule_type'])) {
            $rules = $this->ruleModel->reset()->getByType($options['rule_type'])->select()->fetch();
            
            foreach ($rules as $rule) {
                $result = preg_replace(
                    $rule->getData('pattern'),
                    $rule->getData('replacement'),
                    $result
                );
            }
        } else {
            // 使用所有激活的规则
            $rules = $this->ruleModel->reset()->getActiveRules()->select()->fetch();
            
            foreach ($rules as $rule) {
                try {
                    $pattern = $rule->getData('pattern');
                    $replacement = $rule->getData('replacement');
                    
                    // 检查是否是回调函数
                    if (is_string($replacement) && preg_match('/^function\s*\(/', $replacement)) {
                        $result = preg_replace_callback($pattern, eval('return ' . $replacement . ';'), $result);
                    } else {
                        $result = preg_replace($pattern, $replacement, $result);
                    }
                } catch (\Exception $e) {
                    // 跳过无效的规则
                    error_log("规则执行失败 - Rule ID: {$rule->getRuleId()}, Error: " . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * 使用AI智能脱敏
     *
     * @param string $content
     * @param array $options
     * @return string
     * @throws Exception
     */
    private function desensitizeWithAI(string $content, array $options): string
    {
        // 检查AI是否启用
        if (!($this->config['ai']['enabled'] ?? true)) {
            throw new Exception('AI脱敏功能未启用');
        }

        try {
            // 构建脱敏提示词
            $prompt = str_replace(
                '{content}',
                $content,
                $options['prompt_template'] ?? $this->config['ai']['prompt_template']
            );

            // 添加额外指令
            $prompt .= "\n\n请遵守以下脱敏规则：\n";
            $prompt .= "1. 保护所有敏感信息（邮箱、手机号、身份证号、银行卡号等）\n";
            $prompt .= "2. 保持内容可读性，不要完全删除信息\n";
            $prompt .= "3. 使用合适的替换符号（如***或****）\n";
            $prompt .= "4. 保持原文格式和语义\n";

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $modelCode = $options['model_code'] ?? $this->config['ai']['model_code'] ?? '';
            $adapterCode = $options['adapter_code'] ?? $this->config['ai']['desensitization_adapter'] ?? 'desensitization';
            
            // 获取适配器参数配置
            $adapterParams = $this->getAdapterParams($adapterCode, 'desensitization');
            
            // 调用AI服务生成文本，传递适配器参数
            $result = $aiService->generate(
                prompt: $prompt,
                modelCode: $modelCode ?: null,
                scenarioCode: $adapterCode,
                params: $adapterParams
            );

            return $result;
        } catch (\Exception $e) {
            error_log("AI脱敏失败: " . $e->getMessage());
            throw new Exception("AI脱敏失败: " . $e->getMessage());
        }
    }

    /**
     * 使用自定义规则脱敏
     *
     * @param string $content
     * @param array $options
     * @return string
     */
    private function desensitizeWithCustomRules(string $content, array $options): string
    {
        if (empty($options['rules']) || !is_array($options['rules'])) {
            throw new Exception('自定义规则不能为空');
        }

        $result = $content;

        foreach ($options['rules'] as $rule) {
            if (empty($rule['pattern'])) {
                continue;
            }

            $replacement = $rule['replacement'] ?? '***';
            
            try {
                if (is_callable($replacement)) {
                    $result = preg_replace_callback($rule['pattern'], $replacement, $result);
                } else {
                    $result = preg_replace($rule['pattern'], $replacement, $result);
                }
            } catch (\Exception $e) {
                error_log("自定义规则执行失败: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * 批量脱敏处理
     *
     * @param array $contents 内容数组
     * @param string $method 脱敏方法
     * @param array $options 选项
     * @return array 脱敏后的内容数组
     * @throws Exception
     */
    public function desensitizeBatch(array $contents, string $method = 'regex', array $options = []): array
    {
        $maxRecords = $this->config['batch']['max_records'] ?? 1000;
        
        if (count($contents) > $maxRecords) {
            throw new Exception("批量处理超过最大记录数限制: {$maxRecords}");
        }

        $results = [];
        $delay = $this->config['batch']['delay'] ?? 0;

        foreach ($contents as $key => $content) {
            $results[$key] = $this->desensitize($content, $method, $options);
            
            // 添加延迟
            if ($delay > 0) {
                usleep((int)($delay * 1000000));
            }
        }

        return $results;
    }

    /**
     * 获取可用的脱敏方法列表
     *
     * @return array
     */
    public function getAvailableMethods(): array
    {
        return $this->config['strategies'] ?? [];
    }

    /**
     * 获取脱敏规则列表
     *
     * @param string|null $type 规则类型
     * @return array
     */
    public function getRules(?string $type = null): array
    {
        if ($type) {
            $rules = $this->ruleModel->reset()->getByType($type)->select()->fetch();
        } else {
            $rules = $this->ruleModel->reset()->getActiveRules()->select()->fetch();
        }

        // 确保 $rules 是数组
        if (!is_array($rules)) {
            $rules = [];
        }

        return array_map(function($rule) {
            // 如果 $rule 是模型对象，需要转换为数组
            if (is_object($rule)) {
                return [
                    'rule_id' => $rule->getRuleId(),
                    'name' => $rule->getName(),
                    'type' => $rule->getType(),
                    'pattern' => $rule->getPattern(),
                    'replacement' => $rule->getReplacement(),
                    'description' => $rule->getDescription(),
                    'priority' => $rule->getPriority(),
                    'is_active' => $rule->getIsActive()
                ];
            }
            return $rule;
        }, $rules);
    }

    /**
     * 测试规则
     *
     * @param string $pattern 正则表达式
     * @param string $replacement 替换内容
     * @param string $testContent 测试内容
     * @return string 测试结果
     */
    public function testRule(string $pattern, string $replacement, string $testContent): string
    {
        try {
            return preg_replace($pattern, $replacement, $testContent);
        } catch (\Exception $e) {
            throw new Exception("规则测试失败: " . $e->getMessage());
        }
    }

    /**
     * 检测敏感内容
     *
     * @param string $content
     * @param array $options 检测选项 ['rule_types' => [], 'return_positions' => true]
     * @return array 检测结果 ['has_sensitive' => bool, 'sensitive_types' => [], 'positions' => []]
     */
    public function detectSensitive(string $content, array $options = []): array
    {
        if (empty($content)) {
            return [
                'has_sensitive' => false,
                'sensitive_types' => [],
                'positions' => []
            ];
        }

        $result = [
            'has_sensitive' => false,
            'sensitive_types' => [],
            'positions' => []
        ];

        // 获取要检测的规则类型
        $ruleTypes = $options['rule_types'] ?? [];
        $returnPositions = $options['return_positions'] ?? true;

        // 如果指定了类型，只检测这些类型
        if (!empty($ruleTypes)) {
            foreach ($ruleTypes as $type) {
                $rules = $this->ruleModel->reset()->getByType($type)->select()->fetch();
                foreach ($rules as $rule) {
                    if (preg_match($rule->getPattern(), $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $result['has_sensitive'] = true;
                        if (!in_array($type, $result['sensitive_types'])) {
                            $result['sensitive_types'][] = $type;
                        }
                        if ($returnPositions) {
                            $result['positions'][] = [
                                'type' => $type,
                                'match' => $matches[0][0],
                                'start' => $matches[0][1],
                                'end' => $matches[0][1] + strlen($matches[0][0])
                            ];
                        }
                    }
                }
            }
        } else {
            // 检测所有规则
            $rules = $this->ruleModel->reset()->getActiveRules()->select()->fetch();
            foreach ($rules as $rule) {
                // 兼容不同返回结构（对象/数组/数据行）
                $type = null;
                $pattern = null;
                if (is_object($rule)) {
                    if (method_exists($rule, 'getType')) {
                        $type = $rule->getType();
                    } elseif (property_exists($rule, 'type')) {
                        $type = $rule->type;
                    }
                    if (method_exists($rule, 'getPattern')) {
                        $pattern = $rule->getPattern();
                    } elseif (property_exists($rule, 'pattern')) {
                        $pattern = $rule->pattern;
                    }
                } elseif (is_array($rule)) {
                    $type = $rule['type'] ?? ($rule['rule_type'] ?? null);
                    $pattern = $rule['pattern'] ?? ($rule['rule_pattern'] ?? null);
                } else {
                    // 非法结构跳过
                    continue;
                }

                if (!$pattern) {
                    continue;
                }

                if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $result['has_sensitive'] = true;
                    if (!in_array($type, $result['sensitive_types'])) {
                        $result['sensitive_types'][] = $type;
                    }
                    if ($returnPositions) {
                        $result['positions'][] = [
                            'type' => $type,
                            'match' => $matches[0][0],
                            'start' => $matches[0][1],
                            'end' => $matches[0][1] + strlen($matches[0][0])
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 使用AI检测敏感内容
     *
     * @param string $content 待检测内容
     * @param array $options 检测选项 ['rule_types' => [], 'model_code' => '']
     * @return array 检测结果 ['has_sensitive' => bool, 'sensitive_types' => [], 'positions' => [], 'ai_analysis' => '']
     * @throws Exception
     */
    public function detectSensitiveWithAI(string $content, array $options = []): array
    {
        if (empty($content)) {
            return [
                'has_sensitive' => false,
                'sensitive_types' => [],
                'positions' => [],
                'ai_analysis' => ''
            ];
        }

        // 检查AI是否启用
        if (!($this->config['ai']['enabled'] ?? true)) {
            throw new Exception('AI功能未启用');
        }

        try {
            // 首先使用正则检测作为基础
            $regexResult = $this->detectSensitive($content, ['return_positions' => true]);
            
            // 从系统配置加载平台敏感词作为参考
            try {
                /** @var \Weline\SystemConfig\Model\SystemConfig $sysCfg */
                $sysCfg = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\SystemConfig\Model\SystemConfig::class);
                $metaRules = (string)($sysCfg->getConfig('meta_rules', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND) ?? '');
                $googleRules = (string)($sysCfg->getConfig('google_rules', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND) ?? '');
            } catch (\Throwable $e) {
                $metaRules = '';
                $googleRules = '';
            }

            // 默认参考链接（可被适配器忽略，仅作语义提示）
            $metaUrl = 'https://transparency.meta.com/zh-cn/policies/community-standards/';
            $googleUrl = 'https://transparency.google/intl/en/our-policies/product-terms/google-ads/';

            // 构建AI检测提示词（加入平台规则与参考链接）
            $prompt = "你是一名合规审核与敏感信息检测助手。请参考下面的平台规则，对给定文本进行合规性判断并检测敏感信息（如电话、自杀、自残、性剥削、恐怖主义、成人性内容、儿童性剥削、诈骗、知识产权侵权、隐私侵犯、人工智能生成的性内容等）。\n\n";
            if (trim($metaRules) !== '' || trim($googleRules) !== '') {
                $prompt .= "【平台敏感词参考】\n";
                if (trim($metaRules) !== '') {
                    $prompt .= "- Meta 规则（换行分隔）：\n{$metaRules}\n\n";
                }
                if (trim($googleRules) !== '') {
                    $prompt .= "- Google 规则（换行分隔）：\n{$googleRules}\n\n";
                }
            }
            $prompt .= "【参考链接（可用于理解政策范围，无需抓取页面）】\n- Meta: {$metaUrl}\n- Google: {$googleUrl}\n\n";
            $prompt .= "【待检测内容】\n{$content}\n\n";
            $prompt .= "请以JSON格式返回结果，格式如下：\n";
            $prompt .= "{\n";
            $prompt .= '  "has_sensitive": true/false,' . "\n";
            $prompt .= '  "sensitive_types": ["类型1", "类型2"],' . "\n";
            $prompt .= '  "positions": [' . "\n";
            $prompt .= '    {"type": "类型", "match": "匹配内容", "start": 位置, "end": 位置}' . "\n";
            $prompt .= '  ],' . "\n";
            $prompt .= '  "analysis": "结合平台规则给出合规性结论与说明"' . "\n";
            $prompt .= "}\n\n";
            
            // 如果有正则检测结果，在提示词中提及
            if ($regexResult['has_sensitive']) {
                $prompt .= "注意：使用正则规则已检测到以下敏感信息：\n";
                foreach ($regexResult['positions'] as $pos) {
                    $prompt .= "- {$pos['type']}: {$pos['match']}\n";
                }
                $prompt .= "\n";
            }
            
            $prompt .= "请确保检测准确，并补充可能遗漏的敏感信息。";

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $modelCode = $options['model_code'] ?? $this->config['ai']['model_code'] ?? '';
            $adapterCode = $options['adapter_code'] ?? $this->config['ai']['desensitization_adapter'] ?? 'desensitization';
            
            // 获取适配器参数配置
            $adapterParams = $this->getAdapterParams($adapterCode, 'desensitization');
            
            $aiResponse = $aiService->generate(
                $prompt,
                $modelCode ?: null,
                $adapterCode,
                'zh_Hans_CN',
                $adapterParams
            );

            // 解析AI返回的JSON结果
            $aiResult = [
                'has_sensitive' => false,
                'sensitive_types' => [],
                'positions' => [],
                'analysis' => ''
            ];

            try {
                // 尝试提取JSON
                if (preg_match('/\{[\s\S]*\}/', $aiResponse, $matches)) {
                    $jsonResult = json_decode($matches[0], true);
                    if ($jsonResult) {
                        $aiResult['has_sensitive'] = $jsonResult['has_sensitive'] ?? false;
                        $aiResult['sensitive_types'] = $jsonResult['sensitive_types'] ?? [];
                        $aiResult['positions'] = $jsonResult['positions'] ?? [];
                        $aiResult['analysis'] = $jsonResult['analysis'] ?? '';
                    }
                }
            } catch (\Exception $e) {
                error_log("AI检测结果解析失败: " . $e->getMessage());
            }

            // 合并正则检测和AI检测结果
            $finalResult = [
                'has_sensitive' => $regexResult['has_sensitive'] || $aiResult['has_sensitive'],
                'sensitive_types' => array_unique(array_merge($regexResult['sensitive_types'], $aiResult['sensitive_types'])),
                'positions' => array_merge($regexResult['positions'], $aiResult['positions']),
                'ai_analysis' => $aiResult['analysis'] ?? ''
            ];

            return $finalResult;
        } catch (\Exception $e) {
            error_log("AI检测失败: " . $e->getMessage());
            // AI检测失败时，返回正则检测结果
            return $this->detectSensitive($content, $options);
        }
    }

    /**
     * 脱敏并使用AI重写润色
     *
     * @param string $content
     * @param array $options 重写选项 ['model_code' => '', 'rewrite_style' => '', 'preserve_format' => true]
     * @return string 重写后的内容
     * @throws Exception
     */
    public function desensitizeAndRewrite(string $content, array $options = []): string
    {
        if (empty($content)) {
            return $content;
        }

        // 检查AI是否启用
        if (!($this->config['ai']['enabled'] ?? true)) {
            throw new Exception('AI功能未启用');
        }

        try {
            // 先进行脱敏处理
            $desensitized = $this->desensitize($content, 'regex');

            // 构建重写提示词
            $modelCode = $options['model_code'] ?? $this->config['ai']['model_code'] ?? null;
            $rewriteStyle = $options['rewrite_style'] ?? 'natural';
            $preserveFormat = $options['preserve_format'] ?? true;

            $prompt = $this->buildRewritePrompt($desensitized, $rewriteStyle, $preserveFormat);

            // 调用AI服务重写
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $adapterCode = $options['adapter_code'] ?? $this->config['ai']['rewrite_adapter'] ?? 'rewrite';
            
            // 获取适配器参数配置
            $adapterParams = $this->getAdapterParams($adapterCode, 'rewrite');
            
            $rewritten = $aiService->generate(
                $prompt,
                $modelCode ?: null,
                $adapterCode,
                'zh_Hans_CN',
                $adapterParams
            );

            return $rewritten;
        } catch (\Exception $e) {
            error_log("AI重写润色失败: " . $e->getMessage());
            throw new Exception("AI重写润色失败: " . $e->getMessage());
        }
    }

    /**
     * 构建重写提示词
     *
     * @param string $content
     * @param string $style
     * @param bool $preserveFormat
     * @return string
     */
    private function buildRewritePrompt(string $content, string $style, bool $preserveFormat): string
    {
        $prompt = "请对以下已脱敏的内容进行重写润色，使其更加自然流畅：\n\n";
        $prompt .= $content;
        $prompt .= "\n\n";

        // 根据风格添加要求
        $styleMap = [
            'natural' => '使用自然、流畅的语言风格',
            'formal' => '使用正式、专业的语言风格',
            'casual' => '使用轻松、随意的语言风格',
            'professional' => '使用专业、严谨的语言风格',
            'concise' => '使用简洁、精炼的语言风格'
        ];

        $styleText = $styleMap[$style] ?? '使用自然、流畅的语言风格';
        $prompt .= "要求：\n";
        $prompt .= "1. {$styleText}\n";
        $prompt .= "2. 保持原意不变，只进行语言润色\n";

        if ($preserveFormat) {
            $prompt .= "3. 保持原有格式（段落、换行等）\n";
        }

        $prompt .= "4. 避免使用过于复杂的句式\n";
        $prompt .= "5. 确保内容通顺、易懂\n";

        return $prompt;
    }

    /**
     * 获取可用的AI模型列表
     *
     * @return array 模型列表
     */
    public function getAvailableModels(): array
    {
        try {
            /** @var AiModel $aiModel */
            $aiModel = ObjectManager::getInstance(AiModel::class);
            
            // 先尝试获取激活的模型
            $collection = $aiModel->reset()
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->order(AiModel::fields_SUPPLIER, 'ASC')
                ->order(AiModel::fields_NAME, 'ASC')
                ->select();
            
            $models = $collection->fetch();
            
            // 如果激活的模型为空，获取所有模型
            if (!$models || (method_exists($models, 'getItems') && count($models->getItems()) == 0)) {
                error_log("激活的AI模型为空，尝试获取所有模型");
                $collection = $aiModel->reset()
                    ->order(AiModel::fields_SUPPLIER, 'ASC')
                    ->order(AiModel::fields_NAME, 'ASC')
                    ->select();
                
                $models = $collection->fetch();
            }
            
            $result = [];
            
            // 处理查询结果
            if ($models) {
                // 如果是集合对象
                if (method_exists($models, 'getItems')) {
                    $items = $models->getItems();
                    if (is_array($items)) {
                        foreach ($items as $model) {
                            if ($model && method_exists($model, 'getModelCode')) {
                                $result[] = [
                                    'code' => $model->getModelCode(),
                                    'name' => $model->getName(),
                                    'supplier' => $model->getSupplier(),
                                    'version' => $model->getVersion(),
                                    'max_tokens' => $model->getMaxTokens() ?: 0,
                                ];
                            }
                        }
                    }
                } 
                // 如果是单个模型对象数组
                elseif (is_array($models)) {
                    foreach ($models as $model) {
                        if ($model && method_exists($model, 'getModelCode')) {
                            $result[] = [
                                'code' => $model->getModelCode(),
                                'name' => $model->getName(),
                                'supplier' => $model->getSupplier(),
                                'version' => $model->getVersion(),
                                'max_tokens' => $model->getMaxTokens() ?: 0,
                            ];
                        }
                    }
                }
                // 如果是单个模型对象
                elseif (method_exists($models, 'getModelCode')) {
                    $result[] = [
                        'code' => $models->getModelCode(),
                        'name' => $models->getName(),
                        'supplier' => $models->getSupplier(),
                        'version' => $models->getVersion(),
                        'max_tokens' => $models->getMaxTokens() ?: 0,
                    ];
                }
            }
            
            // 调试日志
            error_log("获取到 " . count($result) . " 个AI模型");
            
            return $result;
        } catch (\Exception $e) {
            error_log("获取AI模型列表失败: " . $e->getMessage());
            error_log("错误堆栈: " . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * 获取配置
     *
     * @return array
     */
    private function getConfig(): array
    {
        $config = require __DIR__ . '/../etc/env.php';
        return $config['desensitization'] ?? [];
    }

    /**
     * 获取适配器参数配置
     * 如果系统配置中有保存的参数，使用保存的参数；否则使用适配器默认参数
     *
     * @param string $adapterCode 适配器代码
     * @param string $adapterType 适配器类型：desensitization 或 rewrite
     * @return array
     */
    private function getAdapterParams(string $adapterCode, string $adapterType): array
    {
        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            
            $configKey = $adapterType === 'desensitization' 
                ? 'desensitization_adapter_params' 
                : 'rewrite_adapter_params';
            
            // 获取系统配置中的参数
            $paramsJson = $systemConfig->getConfig($configKey, 'GuoLaiRen_Desensitization', SystemConfig::area_BACKEND);
            
            if ($paramsJson) {
                $params = json_decode($paramsJson, true);
                if (is_array($params)) {
                    return $params;
                }
            }
        } catch (\Exception $e) {
            error_log("获取适配器参数配置失败: " . $e->getMessage());
        }
        
        // 返回空数组，让适配器使用默认参数
        return [];
    }

    /**
     * 润色敏感内容
     *
     * @param string $content 原始内容
     * @param array $positions 敏感内容位置信息
     * @param array $options 选项
     * @return array 返回润色后的内容和相关信息
     * @throws Exception
     */
    public function rewriteSensitiveContent(string $content, array $positions, array $options = []): array
    {
        if (empty($content)) {
            throw new Exception('内容不能为空');
        }

        if (empty($positions)) {
            throw new Exception('没有需要润色的敏感内容');
        }

        try {
            // 获取AI模型代码
            $modelCode = $options['model_code'] ?? '';
            
            // 构建润色提示词
            $sensitiveInfo = [];
            foreach ($positions as $pos) {
                $sensitiveInfo[] = sprintf(
                    '- 类型: %s, 内容: %s (位置: %d-%d)',
                    $pos['type'] ?? '未知',
                    $pos['match'] ?? '',
                    $pos['start'] ?? 0,
                    $pos['end'] ?? 0
                );
            }
            
            // 引入平台规则，指导改写为合规表达
            try {
                /** @var \Weline\SystemConfig\Model\SystemConfig $sysCfg */
                $sysCfg = ObjectManager::getInstance(\Weline\SystemConfig\Model\SystemConfig::class);
                $metaRules = (string)($sysCfg->getConfig('meta_rules', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND) ?? '');
                $googleRules = (string)($sysCfg->getConfig('google_rules', 'GuoLaiRen_Desensitization', \Weline\SystemConfig\Model\SystemConfig::area_BACKEND) ?? '');
            } catch (\Throwable $e) {
                $metaRules = '';
                $googleRules = '';
            }

            $policySection = '';
            if (trim($metaRules) !== '' || trim($googleRules) !== '') {
                $policySection .= "【平台合规要点（参考）】\n";
                if (trim($metaRules) !== '') {
                    $policySection .= "- Meta：\n{$metaRules}\n\n";
                }
                if (trim($googleRules) !== '') {
                    $policySection .= "- Google：\n{$googleRules}\n\n";
                }
            }

            // 对内容进行预处理，给敏感内容添加特殊标记
            $markedContent = $this->addMarkersToSensitiveContent($content, $positions);
            
            $prompt = sprintf(
                "你是一名内容合规改写助手。请在不改变核心信息与可读性的前提下，将原文改写为符合平台政策、避免违规/煽动/恐吓/成人/违法等的合规表达。

重要规则：
1) 文中用【SENSITIVE_X】标记的内容是敏感词汇，必须进行改写或替换
2) 改写时保持【SENSITIVE_X】标记的原有位置，只替换其内容
3) 保留原意，删除或替换敏感片段
4) 输出与原文同语言
5) 只输出改写后的完整文本，不要说明
6) 保持文本的整体结构和格式

%s原始内容（已标记敏感内容）：
%s

标记的敏感信息详情：
%s

",
                $policySection,
                $markedContent,
                implode("\n", $sensitiveInfo)
            );
            
            // 获取AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            // 使用本模块场景适配器 + 模式=rewrite，避免找不到“rewrite”场景
            $adapterCode = ObjectManager::getInstance(\GuoLaiRen\Desensitization\Ai\Adapter\DesensitizationAdapter::class)->getCode();
            
            // 调用AI润色（指定场景适配器并传 mode=rewrite）
            $rewrittenWithMarkers = $aiService->generate(
                $prompt,
                $modelCode ?: null,
                $adapterCode,
                'zh_Hans_CN',
                ['mode' => 'rewrite']
            );
            
            // 移除标记，恢复正常文本
            $rewrittenContent = $this->removeMarkersFromContent($rewrittenWithMarkers);
            
            return [
                'content' => $rewrittenContent,
                'original_length' => mb_strlen($content),
                'rewritten_length' => mb_strlen($rewrittenContent),
                'positions_count' => count($positions)
            ];
        } catch (\Exception $e) {
            error_log("润色失败: " . $e->getMessage());
            throw new Exception("润色失败: " . $e->getMessage());
        }
    }
    
    /**
     * 为敏感内容添加标记
     * 
     * @param string $content 原始内容
     * @param array $positions 敏感位置信息
     * @return string 标记后的内容
     */
    private function addMarkersToSensitiveContent(string $content, array $positions): string
    {
        // 按位置从后往前排序，避免插入标记时位置偏移
        $sortedPositions = $positions;
        usort($sortedPositions, function($a, $b) {
            return $b['start'] - $a['start'];
        });
        
        $markedContent = $content;
        foreach ($sortedPositions as $index => $pos) {
            if (isset($pos['start']) && isset($pos['end']) && $pos['start'] < $pos['end']) {
                $before = mb_substr($markedContent, 0, $pos['start']);
                $sensitive = mb_substr($markedContent, $pos['start'], $pos['end'] - $pos['start']);
                $after = mb_substr($markedContent, $pos['end']);
                
                // 使用索引作为标记ID
                $markedContent = $before . '【SENSITIVE_' . $index . '】' . $after;
            }
        }
        
        return $markedContent;
    }
    
    /**
     * 移除内容中的标记
     * 
     * @param string $content 带标记的内容
     * @return string 移除标记后的内容
     */
    private function removeMarkersFromContent(string $content): string
    {
        // 移除所有【SENSITIVE_X】标记
        return preg_replace('/【SENSITIVE_\d+】/', '', $content);
    }
}

