<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace GuoLaiRen\Desensitization\extends\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Framework\Manager\ObjectManager;

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
        return '专门用于AI数据脱敏任务的场景适配器。支持六种工作模式：1. 检测模式（默认）- 检测敏感信息；2. 脱敏模式 - 对敏感信息进行脱敏处理；3. 标记模式 - 标记敏感词位置；4. 检测+标记模式 - 同时输出问题点与位置信息；5. 润色模式 - 脱敏后对内容进行润色；6. 提取模式 - 从网页内容中提取敏感词规则。能够识别并保护邮箱、手机号、身份证、银行卡等敏感信息。';
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
                return $this->buildMarkPrompt($prompt, $level, $params);
            case 'detect_mark':
                return $this->buildDetectMarkPrompt($prompt, $level, $params);
            case 'rewrite':
                return $this->buildRewritePrompt($prompt, $level, $params);
            case 'extract':
                return $this->buildExtractPrompt($prompt, $params);
            case 'detect':
            default:
                return $this->buildDetectPrompt($prompt, $params);
        }
    }

    /**
     * 标记规范，统一复用，便于下游解析
     */
    private function getMarkingSpecification(): string
    {
        $spec = "标记规范（严格遵守）：\n";
        $spec .= "- 输出标题：标记列表（四个字）后紧随所有标记；\n";
        $spec .= "- 标记格式：[开始位置:结束位置:类型:片段:置信度:来源]，无空格；\n";
        $spec .= "- 位置为基于原文UTF-8的字符索引（首字符=0），结束位置为不包含自身的闭区间右端；\n";
        $spec .= "- ‘片段’必须严格为原文中的逐字符子串：不允许改写/规范化/大小写变更，也不允许增删任何空白、换行或标点；\n";
        $spec .= "- ‘片段’与给定位置必须一致：原文.substring(开始位置, 结束位置) === 片段；若无法保证，请不要猜测，直接返回‘未发现’或缩小到可严格匹配的最小范围；\n";
        $spec .= "- 类型必须使用以下配置的类型名称之一（直接从配置中读取，保持原样，不进行映射转换）：\n";
        $spec .= $this->getCategoryTaxonomy();
        $spec .= "- 检测到敏感内容时，直接归类到上述类型之一，使用配置中的原始类型名称（中文或英文均可）；\n";
        $spec .= "- 置信度取0~1两位小数；来源取：AI/规则/合并；\n";
        $spec .= "- 对同一语义的相邻/重叠片段应合并成更完整的句子范围；\n";
        $spec .= "- 同时包含直接命中与‘可疑/描述涉及禁止范围’的片段；\n";
        $spec .= "- 标记必须覆盖完整的违规表达或上下文关键句，避免只截取词根；\n";
        return $spec;
    }

    /**
     * 词清单规范：用于前端基于原文做二次匹配与标注
     */
    private function getTermListSpecification(): string
    {
        $spec = "词清单规范（严格遵守）：\n";
        $spec .= "- 输出标题：词清单（四个字）；\n";
        $spec .= "- 每行一个词；\n";
        $spec .= "- 该词必须是原文中的逐字符子串：保持原始大小写、空格、标点与换行位置；\n";
        $spec .= "- 不要位置、不要解释、不要任何前后缀；\n";
        $spec .= "- 若无任何可疑/敏感词，输出：词清单：无\n";
        return $spec;
    }

    /**
     * 分类体系说明，用于要求模型统一归类
     * 从配置中读取类型列表
     */
    private function getCategoryTaxonomy(): string
    {
        // 基础个人信息类型（始终支持）
        $lines = [];
        $lines[] = "邮箱";
        $lines[] = "手机号";
        $lines[] = "身份证";
        $lines[] = "银行卡";
        $lines[] = "真实姓名";
        $lines[] = "具体地址";
        
        // 从配置中读取类型列表
        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            $metaRules = (string)($systemConfig->getConfig('meta_rules', 'GuoLaiRen_Desensitization', SystemConfig::area_BACKEND) ?? '');
            $googleRules = (string)($systemConfig->getConfig('google_rules', 'GuoLaiRen_Desensitization', SystemConfig::area_BACKEND) ?? '');
            
            // 合并所有类型
            $allTypes = [];
            if (trim($metaRules) !== '') {
                $metaLines = array_filter(array_map('trim', explode("\n", $metaRules)));
                $allTypes = array_merge($allTypes, $metaLines);
            }
            if (trim($googleRules) !== '') {
                $googleLines = array_filter(array_map('trim', explode("\n", $googleRules)));
                $allTypes = array_merge($allTypes, $googleLines);
            }
            
            // 去重并添加到类型列表
            $allTypes = array_values(array_unique($allTypes));
            foreach ($allTypes as $type) {
                $type = trim($type);
                if ($type !== '' && !in_array($type, $lines)) {
                    $lines[] = $type;
                }
            }
        } catch (\Throwable $e) {
            // 配置读取失败时继续使用基础类型
        }
        
        return "  - " . implode("\n  - ", $lines) . "\n";
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
    private function buildMarkPrompt(string $content, string $level, array $params = []): string
    {
        $prompt = "请对以下内容进行敏感范围检测与标记（包含直接敏感信息、疑似项，以及带有明显情绪/煽动/辱骂/威胁/仇恨/攻击性/极化倾向的表达；同时识别涉及禁止范围的描述性文本），不要修改原文：\n\n";
        
        // 添加检测级别说明
        switch ($level) {
            case 'high':
                $prompt .= "检测级别：严格检测\n";
                $prompt .= "要求：\n";
                $prompt .= "- 识别所有明确与模糊可疑项（即使仅为描述/意图/变体/暗示）；对含强烈情绪色彩或引导性、煽动性的表达一并识别；\n";
                $prompt .= "- 对出现频次高或上下文加重风险的表达提高权重；\n";
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
        
        // 获取配置的敏感词规则并增强检测
        $enhancedRules = $this->getEnhancedSensitiveRules();
        if (!empty($enhancedRules)) {
            $prompt .= "\n【强化检测规则】以下是从Meta和Google平台规则中提取并分类的敏感词清单，请严格按照这些规则进行强力检测：\n";
            $prompt .= $enhancedRules;
            $prompt .= "\n注意：检测时必须强力判断文本是否属于上述任一类别，即使只是暗示、描述、变体或规避表达也要识别。\n";
        }
        
        $prompt .= "- 与以下\"禁止/敏感范围\"相关或引申、隐喻、规避表达（仅描述/策划/意图也算）：\n";
        $prompt .= $this->getProhibitedContentRules();
        $prompt .= "\n";
        $prompt .= $this->getMarkingSpecification();
        $prompt .= "\n";
        $prompt .= $this->getTermListSpecification();
        $prompt .= "\n";
        $prompt .= "需要标记的内容：\n{$content}";
        
        return $prompt;
    }

    /**
     * 构建检测+标记组合模式提示词
     */
    private function buildDetectMarkPrompt(string $content, string $level, array $params = []): string
    {
        $prompt = "请先检测以下内容中的敏感信息与\"禁止/敏感范围\"（包含疑似项、带有明显情绪/煽动/辱骂/威胁/仇恨/攻击性的表达，以及描述性/引导性/意图类文本），然后进行位置标记；要求：\n";
        $prompt .= "1) 输出问题清单（类型/原因/摘要，含直接/可疑两类，标明严重程度）；\n";
        $prompt .= "2) 给出\"标记列表\"（详见下方标记规范），索引基于原文UTF-8字符序；\n";
        $prompt .= "3) 不要修改原文；\n\n";

        // 获取配置的敏感词规则并增强检测
        $enhancedRules = $this->getEnhancedSensitiveRules();
        if (!empty($enhancedRules)) {
            $prompt .= "【强化检测规则】以下是从Meta和Google平台规则中提取并分类的敏感词清单，请严格按照这些规则进行强力检测：\n";
            $prompt .= $enhancedRules;
            $prompt .= "\n注意：检测时必须强力判断文本是否属于上述任一类别，即使只是暗示、描述、变体或规避表达也要识别。\n\n";
        }

        // 结合检测关注点
        $prompt .= "需要重点关注：\n";
        $prompt .= $this->getProhibitedContentRules();
        $prompt .= "\n";
        $prompt .= $this->getMarkingSpecification();
        $prompt .= "\n";
        $prompt .= $this->getTermListSpecification();
        $prompt .= "\n\n原文：\n{$content}";
        return $prompt;
    }
    
    /**
     * 构建润色模式提示词
     */
    private function buildRewritePrompt(string $content, string $level, array $params): string
    {
        $style = $params['style'] ?? 'natural';
        $lang  = $params['lang']  ?? 'auto'; // 由前端传入的主语言：en / zh-CN / auto

        $prompt = "请对以下内容进行‘合规润色’：\n\n";
        // 语言锁定：保持原文主语言输出，禁止跨语言翻译
        $prompt .= "语言要求：\n";
        $prompt .= "- 若传入 lang=en，则必须输出英文；\n";
        $prompt .= "- 若传入 lang=zh-CN，则必须输出简体中文；\n";
        $prompt .= "- 若传入 lang=auto，则以原文中占比最高的语言为准；\n";
        $prompt .= "- 禁止擅自翻译到其它语言。\n\n";
        
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
        // 注入平台规则（Meta/Google）与‘禁止/敏感范围’，用于替换敏感表达
        $enhancedRules = $this->getEnhancedSensitiveRules();
        if (!empty($enhancedRules)) {
            $prompt .= "【平台敏感规则】以下为从 Meta/Google 平台整理的敏感术语/类别，请据此评估与替换：\n";
            $prompt .= $enhancedRules . "\n";
        }
        $prompt .= "【禁止/敏感范围】需避免或改写以下相关表达（包含描述/策划/意图）：\n";
        $prompt .= $this->getProhibitedContentRules() . "\n";

        $prompt .= "合规润色要求：\n";
        $prompt .= "1. 仅对触犯上述规则的片段进行替换，非敏感部分尽量保持原句结构与含义；\n";
        $prompt .= "2. 用中性、合规的替代表达替换敏感词（如涉证券/投资承诺/成人/仇恨/煽动等）；\n";
        $prompt .= "3. 保持原文主语言（见参数 lang）与语气风格，不得改变语言；\n";
        $prompt .= "4. 输出内容需完整可读，不得出现占位符或省略号代替；\n";
        $prompt .= "5. 禁止出现股票/证券代码、收益承诺、投资建议等平台禁用信息；\n\n";
        $prompt .= "需要处理的内容（lang=" . $lang . "):\n{$content}";
        
        return $prompt;
    }
    
    /**
     * 构建提取模式提示词
     */
    private function buildExtractPrompt(string $input, array $params = []): string
    {
        // 若传入为URL，则内部抓取页面并提纯为纯文本；支持 crawl=true 进行站内聚合
        $text = $input;
        if (preg_match('/^https?:\/\//i', $input)) {
            $crawl = (bool)($params['crawl'] ?? false);
            $maxDepth = (int)($params['depth'] ?? 1);
            if ($maxDepth < 0) { $maxDepth = 0; }
            $maxPages = (int)($params['max_pages'] ?? 30);
            if ($maxPages < 1) { $maxPages = 1; }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36\r\n"
                ]
            ]);

            // 渲染源列表：params.renderers 可覆盖
            $rendererBases = [];
            if (isset($params['renderers']) && is_array($params['renderers']) && $params['renderers']) {
                $rendererBases = $params['renderers'];
            } else {
                $rendererBases = [ 'https://r.jina.ai/' ];
            }

            if ($crawl) {
                $aggregated = $this->crawlAndAggregate($input, $context, $rendererBases, $maxDepth, $maxPages);
                $text = $aggregated !== '' ? $aggregated : $this->fetchRenderedFirst($input, $context, $rendererBases);
            } else {
                $text = $this->fetchRenderedFirst($input, $context, $rendererBases);
            }
        }

        // 规范换行并限制长度，避免提示过长
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        if (mb_strlen($text, 'UTF-8') > 120000) {
            $text = mb_substr($text, 0, 120000, 'UTF-8');
        }

        $prompt = "你是政策规则解析助手。请从以下网页内容中提取与广告/内容合规相关的'敏感范围术语或类别'，每行一个短语，只输出结果清单，不要编号/解释。每个词详细说明有哪些单词可能会被认为是这个词汇，并尽可能也列举出来当作敏感词（比如具体的股票）。\n\n";
        
        $prompt .= "示例格式：\n";
        $prompt .= "暴力内容\n";
        $prompt .= "仇恨言论\n";
        $prompt .= "虚假信息\n";
        $prompt .= "骚扰\n";
        $prompt .= "自残\n";
        $prompt .= "性剥削\n";
        $prompt .= "恐怖主义\n";
        $prompt .= "成人性内容\n";
        $prompt .= "儿童性剥削\n";
        $prompt .= "诈骗\n";
        $prompt .= "知识产权侵权\n";
        $prompt .= "隐私侵犯\n";
        $prompt .= "人工智能生成的性内容\n\n";
        
        $prompt .= "网页内容：\n";
        $prompt .= $text;
        
        return $prompt;
    }

    // 抓取并优先使用渲染源
    private function fetchRenderedFirst(string $url, $context, array $rendererBases): string
    {
        foreach ($rendererBases as $base) {
            $base = rtrim((string)$base, '/') . '/';
            $renderUrl = $base . $url;
            $rendered = @file_get_contents($renderUrl, false, $context);
            if ($rendered !== false && $rendered !== '') {
                return strip_tags($rendered);
            }
        }
        $html = @file_get_contents($url, false, $context);
        return $html !== false ? strip_tags($html) : '';
    }

    // 简单的同源/前缀限制
    private function isAllowedUrl(string $startUrl, string $candidate): bool
    {
        $s = parse_url($startUrl);
        $c = parse_url($candidate);
        if (!$s || !$c) return false;
        if (($s['scheme'] ?? '') !== ($c['scheme'] ?? '')) return false;
        if (($s['host'] ?? '') !== ($c['host'] ?? '')) return false;
        $startPath = rtrim($s['path'] ?? '/', '/');
        $candPath = $c['path'] ?? '/';
        return strpos($candPath, $startPath) === 0; // 下级路径
    }

    // 提取站内链接（非常简化，足够应对政策页）
    private function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];
        if (preg_match_all('/<a[^>]+href=\"([^\"]+)\"/i', $html, $m)) {
            foreach ($m[1] as $href) {
                if (strpos($href, 'javascript:') === 0) continue;
                if (strpos($href, '#') === 0) continue;
                // 绝对/相对
                if (preg_match('/^https?:\/\//i', $href)) {
                    $links[] = $href;
                } else {
                    $links[] = rtrim($this->getBaseOrigin($baseUrl), '/') . '/' . ltrim($href, '/');
                }
            }
        }
        return array_values(array_unique($links));
    }

    private function getBaseOrigin(string $url): string
    {
        $u = parse_url($url);
        $origin = ($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '');
        if (isset($u['port'])) $origin .= ':' . $u['port'];
        return $origin;
    }

    private function extractMainText(string $html): string
    {
        // 优先 main/article，再退回全文去标签
        $html = (string)$html;
        if (preg_match('/<(main|article)[^>]*>([\s\S]*?)<\/\1>/i', $html, $m)) {
            return strip_tags($m[2]);
        }
        // 退一步：提取标题+段落
        $buf = [];
        if (preg_match_all('/<(h1|h2|h3)[^>]*>([\s\S]*?)<\/\1>/i', $html, $hm)) {
            foreach ($hm[2] as $t) $buf[] = trim(strip_tags($t));
        }
        if (preg_match_all('/<(p|li)[^>]*>([\s\S]*?)<\/\1>/i', $html, $pm)) {
            foreach ($pm[2] as $p) $buf[] = trim(strip_tags($p));
        }
        $txt = trim(implode("\n", array_filter($buf)));
        return $txt !== '' ? $txt : strip_tags($html);
    }

    private function crawlAndAggregate(string $startUrl, $context, array $rendererBases, int $maxDepth, int $maxPages): string
    {
        $visited = [];
        $queue = [[ $startUrl, 0 ]];
        $pages = 0;
        $allText = [];

        while ($queue) {
            list($url, $depth) = array_shift($queue);
            if (isset($visited[$url])) continue;
            $visited[$url] = true;
            if ($pages >= $maxPages) break;

            // 渲染抓取
            $html = '';
            foreach ($rendererBases as $base) {
                $base = rtrim((string)$base, '/') . '/';
                $renderUrl = $base . $url;
                $r = @file_get_contents($renderUrl, false, $context);
                if ($r !== false && $r !== '') { $html = $r; break; }
            }
            if ($html === '') {
                $r = @file_get_contents($url, false, $context);
                if ($r !== false) $html = $r;
            }
            if ($html === '') continue;
            $pages++;

            // 聚合正文
            $allText[] = $this->extractMainText($html);

            // 扩展链接
            if ($depth < $maxDepth) {
                foreach ($this->extractLinks($html, $url) as $lnk) {
                    if ($this->isAllowedUrl($startUrl, $lnk) && !isset($visited[$lnk])) {
                        $queue[] = [ $lnk, $depth + 1 ];
                    }
                }
            }
        }

        $joined = trim(implode("\n\n", array_filter($allText)));
        return $joined;
    }
    
    /**
     * 构建检测模式提示词（默认模式）
     */
    private function buildDetectPrompt(string $content, array $params = []): string
    {
        $prompt = "请检测以下内容是否包含敏感信息与\"禁止/敏感范围\"，并全面识别：\n" 
                . "- 疑似/模糊匹配项（即使仅为描述、意图、暗示、变体、规避表达）；\n"
                . "- 带有明显情绪化、煽动性、辱骂、威胁、仇恨、攻击性、极化倾向的表达；\n"
                . "- 描述涉及禁止范围的文本；\n\n"
                . "请列出所有问题。\n\n";
        
        $prompt .= "需要检测的敏感信息类型：\n";
        $prompt .= "- 邮箱地址\n";
        $prompt .= "- 手机号码\n";
        $prompt .= "- 身份证号码\n";
        $prompt .= "- 银行卡号\n";
        $prompt .= "- 真实姓名\n";
        $prompt .= "- 具体地址\n";
        
        // 获取配置的敏感词规则并增强检测
        $enhancedRules = $this->getEnhancedSensitiveRules();
        if (!empty($enhancedRules)) {
            $prompt .= "\n【强化检测规则】以下是从Meta和Google平台规则中提取并分类的敏感词清单，请严格按照这些规则进行强力检测：\n";
            $prompt .= $enhancedRules;
            $prompt .= "\n注意：检测时必须强力判断文本是否属于上述任一类别，即使只是暗示、描述、变体或规避表达也要识别。\n";
        }
        
        $prompt .= "\n需要检测的\"禁止/敏感范围\"（根据社群守则）：\n";
        $prompt .= $this->getProhibitedContentRules();
        
        $prompt .= "\n输出要求：\n";
        $prompt .= "A) 问题清单：每行 格式= 类型 | 位置 | 内容 | 严重程度 | 直接/可疑/情绪化\n";
        $prompt .= "B) 紧接输出‘标记列表’（详见下方标记规范）；\n";
        $prompt .= "C) 再输出‘词清单’（详见下方词清单规范）；\n";
        $prompt .= "C) 若未发现，输出：未发现敏感信息或违禁内容\n\n";
        $prompt .= $this->getMarkingSpecification();
        $prompt .= "\n";
        $prompt .= $this->getTermListSpecification();
        $prompt .= "\n";
        $prompt .= "需要检测的内容：\n{$content}";
        
        return $prompt;
    }

    /**
     * @inheritDoc
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 保留原文空白与片段空白，避免片段被“连起来”
        $response = (string)$response;
        // 仅规范方括号形态，避免多语言符号混用
        $response = str_replace(['【','】'], ['[',']'], $response);

        // 保持AI返回的原始类型，不进行映射转换
        // 直接使用AI返回的类型标记，以便保持原文分类的准确性

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
                w_log_warning("脱敏后仍发现未处理的{$type}信息");
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
            $validModes = ['detect', 'desensitize', 'mark', 'detect_mark', 'rewrite', 'extract'];
            if (!in_array($params['mode'], $validModes)) {
                $errors[] = __('无效的工作模式: %{mode}，有效值为: %{valid} (detect=检测, desensitize=脱敏, mark=标记, detect_mark=检测+标记, rewrite=润色, extract=提取)', [
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
                'description' => __('工作模式（detect=检测, desensitize=脱敏, mark=标记, detect_mark=检测+标记, rewrite=润色, extract=提取）'),
                'options' => ['detect', 'desensitize', 'mark', 'detect_mark', 'rewrite', 'extract']
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
            [
                'title' => '提取模式',
                'description' => '从网页内容中提取敏感词规则',
                'input' => 'Google Ads禁止推广危险商品、不当内容、敏感事件、受监管的内容、抄袭内容、非法活动、限制内容、欺诈行为等。',
                'params' => ['mode' => 'extract'],
                'expected_output' => '危险商品\n不当内容\n敏感事件\n受监管的内容\n抄袭内容\n非法活动\n限制内容\n欺诈行为',
            ],
            [
                'title' => '检测+标记模式',
                'description' => '先列出问题清单，再给出位置标记',
                'input' => '联系我：me@company.com 或致电 13987654321',
                'params' => ['mode' => 'detect_mark', 'level' => 'standard'],
                'expected_output' => "问题：邮箱、手机\n标记：[6:21:邮箱:me@company.com][25:36:手机:13987654321]",
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
     * 获取增强的敏感词规则（从配置中读取并合并Meta和Google规则）
     * 
     * @return string 增强的敏感词规则描述
     */
    private function getEnhancedSensitiveRules(): string
    {
        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            $metaRules = (string)($systemConfig->getConfig('meta_rules', 'GuoLaiRen_Desensitization', SystemConfig::area_BACKEND) ?? '');
            $googleRules = (string)($systemConfig->getConfig('google_rules', 'GuoLaiRen_Desensitization', SystemConfig::area_BACKEND) ?? '');
            
            if (trim($metaRules) === '' && trim($googleRules) === '') {
                return '';
            }
            
            // 合并Meta和Google的敏感词
            $allWords = [];
            if (trim($metaRules) !== '') {
                $metaLines = array_filter(array_map('trim', explode("\n", $metaRules)));
                $allWords = array_merge($allWords, $metaLines);
            }
            if (trim($googleRules) !== '') {
                $googleLines = array_filter(array_map('trim', explode("\n", $googleRules)));
                $allWords = array_merge($allWords, $googleLines);
            }
            
            // 去重并排序
            $allWords = array_values(array_unique($allWords));
            sort($allWords);
            
            if (empty($allWords)) {
                return '';
            }
            
            // 构建分类提示，告诉AI这些就是可用的类型分类
            $rulesText = "平台敏感词类型分类列表（以下就是可用的类型名称，检测到时直接使用这些类型）：\n";
            foreach ($allWords as $word) {
                $rulesText .= "- " . trim($word) . "\n";
            }
            $rulesText .= "\n重要：检测到文本涉及上述任一类型时，标记中的'类型'字段必须直接使用上述列表中的类型名称（保持原样，不转换不映射）。\n";
            $rulesText .= "例如：如果检测到'暴力内容'相关文本，类型就标记为'暴力内容'；如果检测到'股票'相关内容，类型就标记为'股票'。";
            
            return $rulesText;
        } catch (\Throwable $e) {
            // 配置读取失败时返回空字符串
            return '';
        }
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

