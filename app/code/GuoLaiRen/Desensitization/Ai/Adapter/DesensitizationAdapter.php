<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace GuoLaiRen\Desensitization\Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 数据脱敏场景适配器
 * 
 * 功能：
 * - 专门用于AI数据脱敏任务
 * - 提供脱敏专用提示词模板
 * - 支持多种脱敏策略
 * - 优化脱敏效果和质量
 */
class DesensitizationAdapter implements ScenarioAdapterInterface
{
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'desensitization';
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return '数据脱敏适配器';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return '专门用于AI数据脱敏任务的场景适配器。支持四种工作模式：1. 检测模式（默认）- 检测敏感信息；2. 脱敏模式 - 对敏感信息进行脱敏处理；3. 标记模式 - 标记敏感词位置；4. 润色模式 - 脱敏后对内容进行润色。能够识别并保护邮箱、手机号、身份证、银行卡等敏感信息。';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    /**
     * @inheritDoc
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $mode = $params['mode'] ?? 'detect';
        $level = $params['level'] ?? 'standard';
        
        // 根据模式生成不同的提示词
        switch ($mode) {
            case 'desensitize':
                return $this->buildDesensitizePrompt($prompt, $level);
            case 'mark':
                return $this->buildMarkPrompt($prompt, $level);
            case 'rewrite':
                return $this->buildRewritePrompt($prompt, $level, $params);
            case 'detect':
            default:
                return $this->buildDetectPrompt($prompt);
        }
    }
    
    /**
     * 构建脱敏模式提示词
     */
    private function buildDesensitizePrompt(string $content, string $level): string
    {
        $prompt = "请对以下内容进行数据脱敏处理，保护敏感信息：\n\n";
        
        // 添加脱敏级别说明
        switch ($level) {
            case 'high':
                $prompt .= "脱敏级别：高级（严格脱敏）\n";
                $prompt .= "要求：尽可能隐藏敏感信息，只保留必要的识别特征。\n";
                break;
            case 'low':
                $prompt .= "脱敏级别：低级（宽松脱敏）\n";
                $prompt .= "要求：仅对敏感信息进行部分遮挡，保留更多的识别特征。\n";
                break;
            case 'standard':
            default:
                $prompt .= "脱敏级别：标准（平衡脱敏）\n";
                $prompt .= "要求：平衡隐私保护和可读性，对敏感信息进行合理的脱敏处理。\n";
                break;
        }
        
        $prompt .= "\n";
        $prompt .= "需要识别并脱敏的敏感信息类型：\n";
        $prompt .= "- 邮箱地址（例如：user@example.com）\n";
        $prompt .= "- 手机号码（例如：13812345678）\n";
        $prompt .= "- 身份证号码（例如：370123199001011234）\n";
        $prompt .= "- 银行卡号（例如：6222021234567890123）\n";
        $prompt .= "- 真实姓名\n";
        $prompt .= "- 具体地址\n";
        $prompt .= "\n";
        $prompt .= "要求：保持文本的上下文完整性和可读性，在脱敏的同时确保内容依然流畅易懂。\n\n";
        $prompt .= "需要脱敏的内容：\n{$content}";
        
        return $prompt;
    }
    
    /**
     * 构建标记模式提示词
     */
    private function buildMarkPrompt(string $content, string $level): string
    {
        $prompt = "请对以下内容进行敏感词检测和标记，不要在原文上进行修改：\n\n";
        
        // 添加检测级别说明
        switch ($level) {
            case 'high':
                $prompt .= "检测级别：严格检测\n";
                $prompt .= "要求：尽可能严格地识别所有可能的敏感信息，包括不确定的内容。\n";
                break;
            case 'low':
                $prompt .= "检测级别：宽松检测\n";
                $prompt .= "要求：仅识别明确的敏感信息，不确定的内容不标记。\n";
                break;
            case 'standard':
            default:
                $prompt .= "检测级别：标准检测\n";
                $prompt .= "要求：使用合理的标准识别敏感信息。\n";
                break;
        }
        
        $prompt .= "\n";
        $prompt .= "需要识别的敏感信息类型：\n";
        $prompt .= "- 邮箱地址\n";
        $prompt .= "- 手机号码\n";
        $prompt .= "- 身份证号码\n";
        $prompt .= "- 银行卡号\n";
        $prompt .= "- 真实姓名\n";
        $prompt .= "- 具体地址\n";
        $prompt .= "\n";
        $prompt .= "标记格式要求：\n";
        $prompt .= "请按照以下格式输出标记结果，不要修改原文内容：\n";
        $prompt .= "[开始位置:结束位置:类型:敏感词] [开始位置:结束位置:类型:敏感词]\n";
        $prompt .= "例如：[0:15:邮箱:user@example.com][30:42:手机:13812345678]\n\n";
        $prompt .= "需要标记的内容：\n{$content}";
        
        return $prompt;
    }
    
    /**
     * 构建润色模式提示词
     */
    private function buildRewritePrompt(string $content, string $level, array $params): string
    {
        $style = $params['style'] ?? 'natural';
        
        $prompt = "请对以下内容进行脱敏润色处理：\n\n";
        
        // 添加脱敏级别说明
        switch ($level) {
            case 'high':
                $prompt .= "脱敏级别：高级（严格脱敏）\n";
                $prompt .= "要求：尽可能隐藏敏感信息，只保留必要的识别特征。\n";
                break;
            case 'low':
                $prompt .= "脱敏级别：低级（宽松脱敏）\n";
                $prompt .= "要求：仅对敏感信息进行部分遮挡，保留更多的识别特征。\n";
                break;
            case 'standard':
            default:
                $prompt .= "脱敏级别：标准（平衡脱敏）\n";
                $prompt .= "要求：平衡隐私保护和可读性，对敏感信息进行合理的脱敏处理。\n";
                break;
        }
        
        $prompt .= "\n";
        
        // 添加润色风格说明
        switch ($style) {
            case 'formal':
                $prompt .= "润色风格：正式专业\n";
                $prompt .= "要求：使用正式、专业的语言进行润色。\n";
                break;
            case 'casual':
                $prompt .= "润色风格：轻松随意\n";
                $prompt .= "要求：使用轻松、随意的语言进行润色。\n";
                break;
            case 'professional':
                $prompt .= "润色风格：专业严谨\n";
                $prompt .= "要求：使用专业、严谨的语言进行润色。\n";
                break;
            case 'concise':
                $prompt .= "润色风格：简洁精炼\n";
                $prompt .= "要求：使用简洁、精炼的语言进行润色。\n";
                break;
            case 'natural':
            default:
                $prompt .= "润色风格：自然流畅\n";
                $prompt .= "要求：使用自然、流畅的语言进行润色。\n";
                break;
        }
        
        $prompt .= "\n";
        $prompt .= "需要识别并脱敏的敏感信息类型：\n";
        $prompt .= "- 邮箱地址\n";
        $prompt .= "- 手机号码\n";
        $prompt .= "- 身份证号码\n";
        $prompt .= "- 银行卡号\n";
        $prompt .= "- 真实姓名\n";
        $prompt .= "- 具体地址\n";
        $prompt .= "\n";
        $prompt .= "要求：\n";
        $prompt .= "1. 识别并脱敏所有敏感信息\n";
        $prompt .= "2. 对脱敏后的内容进行润色，使其更自然、流畅、可读\n";
        $prompt .= "3. 保持文本的整体意思和上下文完整性\n\n";
        $prompt .= "需要处理的内容：\n{$content}";
        
        return $prompt;
    }
    
    /**
     * 构建检测模式提示词（默认模式）
     */
    private function buildDetectPrompt(string $content): string
    {
        $prompt = "请检测以下内容是否包含敏感信息和违禁内容，并列出所有发现的问题：\n\n";
        
        $prompt .= "需要检测的敏感信息类型：\n";
        $prompt .= "- 邮箱地址\n";
        $prompt .= "- 手机号码\n";
        $prompt .= "- 身份证号码\n";
        $prompt .= "- 银行卡号\n";
        $prompt .= "- 真实姓名\n";
        $prompt .= "- 具体地址\n";
        
        $prompt .= "\n需要检测的违禁内容（根据社群守则）：\n";
        $prompt .= $this->getProhibitedContentRules();
        
        $prompt .= "\n输出格式要求：\n";
        $prompt .= "如果发现敏感信息或违禁内容，请按以下格式输出：\n";
        $prompt .= "类型 | 位置 | 内容 | 严重程度\n";
        $prompt .= "例如：\n";
        $prompt .= "邮箱 | 0-15 | user@example.com | 敏感信息\n";
        $prompt .= "暴力 | 30-45 | 威胁性言论 | 严重违规\n";
        $prompt .= "仇恨 | 50-65 | 歧视性言论 | 严重违规\n\n";
        $prompt .= "如果未发现敏感信息或违禁内容，请输出：未发现敏感信息或违禁内容\n\n";
        $prompt .= "需要检测的内容：\n{$content}";
        
        return $prompt;
    }

    /**
     * @inheritDoc
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 后处理：检查是否还有遗漏的敏感信息
        $patterns = [
            'email' => '/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/',
            'phone' => '/([1-9]\d{1})\d{4}(\d{4})/',
            'id_card' => '/(\d{6})\d{8}(\d{4})/',
            'bank_card' => '/(\d{4})\d{12}(\d{4})/',
        ];
        
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $response)) {
                // 如果发现未脱敏的敏感信息，记录日志
                error_log("警告：脱敏后仍发现未处理的{$type}信息");
            }
        }
        
        return trim($response);
    }

    /**
     * @inheritDoc
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];
        
        // 验证模式参数
        if (isset($params['mode'])) {
            $validModes = ['detect', 'desensitize', 'mark', 'rewrite'];
            if (!in_array($params['mode'], $validModes)) {
                $errors[] = __('无效的工作模式: %{mode}，有效值为: %{valid} (detect=检测, desensitize=脱敏, mark=标记, rewrite=润色)', [
                    'mode' => $params['mode'],
                    'valid' => implode(', ', $validModes)
                ]);
            }
        }
        
        // 验证级别参数
        if (isset($params['level'])) {
            $validLevels = ['high', 'standard', 'low'];
            if (!in_array($params['level'], $validLevels)) {
                $errors[] = __('无效的级别: %{level}，有效值为: %{valid}', [
                    'level' => $params['level'],
                    'valid' => implode(', ', $validLevels)
                ]);
            }
        }
        
        // 验证润色风格参数
        if (isset($params['style'])) {
            $validStyles = ['natural', 'formal', 'casual', 'professional', 'concise'];
            if (!in_array($params['style'], $validStyles)) {
                $errors[] = __('无效的润色风格: %{style}，有效值为: %{valid}', [
                    'style' => $params['style'],
                    'valid' => implode(', ', $validStyles)
                ]);
            }
        }
        
        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getParamTemplate(): array
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => false,
                'default' => 'detect',
                'description' => __('工作模式（detect=检测, desensitize=脱敏, mark=标记, rewrite=润色）'),
                'options' => ['detect', 'desensitize', 'mark', 'rewrite']
            ],
            'level' => [
                'type' => 'string',
                'required' => false,
                'default' => 'standard',
                'description' => __('脱敏/检测级别（high=高级, standard=标准, low=低级）'),
                'options' => ['high', 'standard', 'low']
            ],
            'style' => [
                'type' => 'string',
                'required' => false,
                'default' => 'natural',
                'description' => __('润色风格（仅在mode=rewrite时有效，natural=自然, formal=正式, casual=轻松, professional=专业, concise=简洁）'),
                'options' => ['natural', 'formal', 'casual', 'professional', 'concise']
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => '检测模式（默认）',
                'description' => '检测内容中的敏感信息',
                'input' => '用户信息：张三，邮箱zhangsan@example.com，电话13812345678',
                'params' => ['mode' => 'detect'],
                'expected_output' => '邮箱 | 10-31 | zhangsan@example.com\n手机 | 34-45 | 13812345678',
            ],
            [
                'title' => '脱敏模式',
                'description' => '对内容进行脱敏处理',
                'input' => '用户信息：张三，邮箱zhangsan@example.com，电话13812345678',
                'params' => ['mode' => 'desensitize', 'level' => 'standard'],
                'expected_output' => '用户信息：张*，邮箱zh******n@example.com，电话138****5678',
            ],
            [
                'title' => '标记模式',
                'description' => '标记敏感词位置但不修改原文',
                'input' => '联系我：me@company.com 或致电 13987654321',
                'params' => ['mode' => 'mark', 'level' => 'standard'],
                'expected_output' => '[6:21:邮箱:me@company.com][25:36:手机:13987654321]',
            ],
            [
                'title' => '润色模式',
                'description' => '脱敏后对内容进行润色',
                'input' => '我的银行卡号是6222021234567890123',
                'params' => ['mode' => 'rewrite', 'level' => 'standard', 'style' => 'natural'],
                'expected_output' => '相关金融信息已进行脱敏处理',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
    
    /**
     * 获取违禁内容规则（基于Meta社群守则）
     * 
     * @return string 违禁内容规则描述
     */
    private function getProhibitedContentRules(): string
    {
        $rules = "【注：以下规则基于Meta社群守则（https://transparency.meta.com/zh-cn/policies/community-standards/），检测时不区分平台，统一应用】\n\n";
        $rules .= "- 配合实施伤害和宣扬犯罪行为：教唆、协助犯罪活动的内容\n";
        $rules .= "- 危险组织和人物：宣扬恐怖主义、极端主义的内容\n";
        $rules .= "- 欺诈、诈骗和欺骗行为：虚假信息、诈骗内容\n";
        $rules .= "- 管制商品及服务：违禁品交易、非法服务推广\n";
        $rules .= "- 暴力与煽动暴力：暴力威胁、伤害他人、煽动暴乱的内容\n";
        $rules .= "- 成人性剥削：性交易、性剥削相关内容\n";
        $rules .= "- 欺凌和骚扰：霸凌、骚扰、恐吓他人的内容\n";
        $rules .= "- 儿童性剥削、虐待和裸露内容：涉及未成年人的不适当内容\n";
        $rules .= "- 剥削：人口贩卖、强制劳动等剥削内容\n";
        $rules .= "- 自杀、自残和饮食失调：鼓励自我伤害、自杀的内容\n";
        $rules .= "- 成人裸露和性行为内容：过于露骨的性内容\n";
        $rules .= "- 成人性引诱和色情语言：色情引诱、性暗示内容\n";
        $rules .= "- 仇恨行为：针对种族、民族、宗教、性别等的仇恨言论\n";
        $rules .= "- 侵犯隐私：未经授权的个人信息泄露\n";
        $rules .= "- 暴力和血腥内容：血腥、暴力场面描述\n";
        $rules .= "- 虚假行为：虚假身份、欺骗行为\n";
        $rules .= "- 错误信息：谣言、虚假新闻、误导信息\n";
        $rules .= "- 垃圾信息：骚扰性推广、恶意营销内容\n";
        $rules .= "- 侵犯第三方知识产权：盗版、侵权内容\n";
        $rules .= "- 违法内容：违反当地法律的内容、商品或服务\n";
        
        return $rules;
    }
}

