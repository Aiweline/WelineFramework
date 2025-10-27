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
                $type = $rule->getType();
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

        return $result;
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
                prompt: $prompt,
                modelCode: $modelCode ?: null,
                scenarioCode: $adapterCode,
                params: $adapterParams
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
            
            $models = $aiModel->reset()
                ->where(AiModel::fields_IS_ACTIVE, 1)
                ->order(AiModel::fields_SUPPLIER, 'ASC')
                ->order(AiModel::fields_NAME, 'ASC')
                ->select()
                ->fetch();
            
            $result = [];
            if ($models && method_exists($models, 'getItems')) {
                foreach ($models->getItems() as $model) {
                    $result[] = [
                        'code' => $model->getModelCode(),
                        'name' => $model->getName(),
                        'supplier' => $model->getSupplier(),
                        'version' => $model->getVersion(),
                    ];
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("获取AI模型列表失败: " . $e->getMessage());
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
}

