<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * Rule Analyzer Service
 * 
 * Responsibilities:
 * 1. Scan dev/ai/rules/*.mdc rule files
 * 2. Scan dev/ai/skills/SKILL.md skill files
 * 3. Extract rule keywords, triggers, constraints
 * 4. Build rule index for compliance checking
 */
class RuleAnalyzerService
{
    private string $rulesDir;
    private string $skillsDir;
    private array $rules = [];
    private array $skills = [];
    private array $ruleIndex = [];
    private bool $loaded = false;
    
    public function __construct()
    {
        $this->rulesDir = BP . 'dev' . DS . 'ai' . DS . 'rules' . DS;
        $this->skillsDir = BP . 'dev' . DS . 'ai' . DS . 'skills' . DS;
    }
    
    /**
     * 加载所有规则和技能
     */
    public function load(): self
    {
        if ($this->loaded) {
            return $this;
        }
        
        $this->loadRules();
        $this->loadSkills();
        $this->buildIndex();
        
        $this->loaded = true;
        return $this;
    }
    
    /**
     * 加载规则文件
     */
    private function loadRules(): void
    {
        if (!is_dir($this->rulesDir)) {
            return;
        }
        
        $files = glob($this->rulesDir . '*.mdc');
        
        foreach ($files as $file) {
            $name = basename($file, '.mdc');
            $content = file_get_contents($file);
            
            $this->rules[$name] = [
                'name' => $name,
                'file' => $file,
                'content' => $content,
                'keywords' => $this->extractKeywords($content),
                'constraints' => $this->extractConstraints($content),
                'triggers' => $this->extractTriggers($content),
            ];
        }
    }
    
    /**
     * 加载技能文件
     */
    private function loadSkills(): void
    {
        if (!is_dir($this->skillsDir)) {
            return;
        }
        
        $dirs = glob($this->skillsDir . '*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $skillFile = $dir . DS . 'SKILL.md';
            if (!file_exists($skillFile)) {
                continue;
            }
            
            $name = basename($dir);
            $content = file_get_contents($skillFile);
            
            $this->skills[$name] = [
                'name' => $name,
                'file' => $skillFile,
                'content' => $content,
                'keywords' => $this->extractKeywords($content),
                'patterns' => $this->extractFilePatterns($content),
                'triggers' => $this->extractTriggers($content),
            ];
        }
    }
    
    /**
     * 构建规则索引（按文件类型/关键词）
     */
    private function buildIndex(): void
    {
        $this->ruleIndex = [
            'by_extension' => [],
            'by_keyword' => [],
            'by_path' => [],
        ];
        
        // 索引规则
        foreach ($this->rules as $name => $rule) {
            foreach ($rule['keywords'] as $keyword) {
                $keyword = strtolower($keyword);
                $this->ruleIndex['by_keyword'][$keyword][] = [
                    'type' => 'rule',
                    'name' => $name,
                ];
            }
        }
        
        // 索引技能
        foreach ($this->skills as $name => $skill) {
            // 按关键词索引
            foreach ($skill['keywords'] as $keyword) {
                $keyword = strtolower($keyword);
                $this->ruleIndex['by_keyword'][$keyword][] = [
                    'type' => 'skill',
                    'name' => $name,
                ];
            }
            
            // 按文件模式索引
            foreach ($skill['patterns'] as $pattern) {
                $ext = $this->getExtensionFromPattern($pattern);
                if ($ext) {
                    $this->ruleIndex['by_extension'][$ext][] = [
                        'type' => 'skill',
                        'name' => $name,
                        'pattern' => $pattern,
                    ];
                }
            }
        }
        
        // 预定义的文件类型映射
        $this->addPredefinedMappings();
    }
    
    /**
     * 添加预定义的文件类型映射
     */
    private function addPredefinedMappings(): void
    {
        $mappings = [
            'css' => ['theme-development'],
            'js' => ['theme-development'],
            'phtml' => ['theme-development', 'i18n-internationalization'],
            'php' => ['code-generation-standards', 'database-model-standards'],
        ];
        
        foreach ($mappings as $ext => $skills) {
            foreach ($skills as $skillName) {
                if (isset($this->skills[$skillName])) {
                    $this->ruleIndex['by_extension'][$ext][] = [
                        'type' => 'skill',
                        'name' => $skillName,
                        'pattern' => "*.$ext",
                    ];
                }
            }
        }
    }
    
    /**
     * 获取适用于文件的规则
     */
    public function getRulesForFile(string $filePath): array
    {
        $this->load();
        
        $applicable = [];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // 按扩展名查找
        if (isset($this->ruleIndex['by_extension'][$ext])) {
            foreach ($this->ruleIndex['by_extension'][$ext] as $ref) {
                $applicable[] = $this->getFullRule($ref);
            }
        }
        
        // 按路径模式查找
        foreach ($this->skills as $name => $skill) {
            foreach ($skill['patterns'] as $pattern) {
                if ($this->matchPattern($filePath, $pattern)) {
                    $applicable[] = [
                        'type' => 'skill',
                        'name' => $name,
                        'skill' => $skill,
                    ];
                }
            }
        }
        
        // 去重
        $seen = [];
        $unique = [];
        foreach ($applicable as $rule) {
            $key = $rule['type'] . ':' . $rule['name'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $rule;
            }
        }
        
        return $unique;
    }
    
    /**
     * 获取适用于内容的规则
     */
    public function getRulesForContent(string $content): array
    {
        $this->load();
        
        $applicable = [];
        $contentLower = strtolower($content);
        
        // 检查关键词
        foreach ($this->ruleIndex['by_keyword'] as $keyword => $refs) {
            if (str_contains($contentLower, $keyword)) {
                foreach ($refs as $ref) {
                    $applicable[] = $this->getFullRule($ref);
                }
            }
        }
        
        return $applicable;
    }
    
    /**
     * 获取完整规则
     */
    private function getFullRule(array $ref): array
    {
        if ($ref['type'] === 'rule') {
            return [
                'type' => 'rule',
                'name' => $ref['name'],
                'rule' => $this->rules[$ref['name']] ?? null,
            ];
        } else {
            return [
                'type' => 'skill',
                'name' => $ref['name'],
                'skill' => $this->skills[$ref['name']] ?? null,
            ];
        }
    }
    
    /**
     * 提取关键词
     */
    private function extractKeywords(string $content): array
    {
        $keywords = [];
        
        // 从 Keywords: 行提取
        if (preg_match('/Keywords?:\s*(.+)/i', $content, $match)) {
            $line = $match[1];
            $parts = preg_split('/[,，、\s]+/', $line);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part && strlen($part) > 1) {
                    $keywords[] = $part;
                }
            }
        }
        
        // 从 Use when: 行提取
        if (preg_match('/Use when:\s*(.+)/i', $content, $match)) {
            $line = $match[1];
            $parts = preg_split('/[,，、\s]+/', $line);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part && strlen($part) > 2) {
                    $keywords[] = $part;
                }
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * 提取约束
     */
    private function extractConstraints(string $content): array
    {
        $constraints = [];
        
        // 查找禁止/必须模式
        $patterns = [
            '/禁止[^。\n]+/u',
            '/必须[^。\n]+/u',
            '/不得[^。\n]+/u',
            '/MUST[^.\n]+/i',
            '/NEVER[^.\n]+/i',
            '/DO NOT[^.\n]+/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $constraints[] = trim($match);
                }
            }
        }
        
        return $constraints;
    }
    
    /**
     * 提取触发条件
     */
    private function extractTriggers(string $content): array
    {
        $triggers = [];
        
        // 查找触发词
        if (preg_match('/触发词[：:]\s*(.+)/u', $content, $match)) {
            $parts = preg_split('/[,，、\s]+/', $match[1]);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part) {
                    $triggers[] = $part;
                }
            }
        }
        
        // 查找 MUST trigger/use when
        if (preg_match_all('/MUST (?:trigger|use) when[:\s]+([^\n]+)/i', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $triggers[] = trim($match);
            }
        }
        
        return $triggers;
    }
    
    /**
     * 提取文件模式
     */
    private function extractFilePatterns(string $content): array
    {
        $patterns = [];
        
        // 查找文件模式
        if (preg_match_all('/\*\.[a-z]+|\*\*\/[^,\s\)]+/i', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $patterns[] = trim($match);
            }
        }
        
        // 查找路径模式
        if (preg_match_all('/(?:Model|Controller|Service|view|templates)[\/\\\\][^\s,\)]+/i', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $patterns[] = trim($match);
            }
        }
        
        return array_unique($patterns);
    }
    
    /**
     * 从模式获取扩展名
     */
    private function getExtensionFromPattern(string $pattern): ?string
    {
        if (preg_match('/\.([a-z]+)$/i', $pattern, $match)) {
            return strtolower($match[1]);
        }
        return null;
    }
    
    /**
     * 匹配文件路径
     */
    private function matchPattern(string $filePath, string $pattern): bool
    {
        $filePath = str_replace('\\', '/', $filePath);
        $pattern = str_replace('\\', '/', $pattern);
        
        // 先替换通配符为占位符，再 quote，最后换回正则
        $placeholders = [
            '**/' => "\x00DOUBLE_STAR\x00",
            '*' => "\x00SINGLE_STAR\x00",
        ];
        
        $temp = str_replace(array_keys($placeholders), array_values($placeholders), $pattern);
        $quoted = preg_quote($temp, '/');
        $regex = str_replace(
            ["\x00DOUBLE_STAR\x00", "\x00SINGLE_STAR\x00"],
            ['.*', '[^/]*'],
            $quoted
        );
        
        return (bool)@preg_match('/' . $regex . '/i', $filePath);
    }
    
    /**
     * 获取所有规则
     */
    public function getRules(): array
    {
        $this->load();
        return $this->rules;
    }
    
    /**
     * 获取所有技能
     */
    public function getSkills(): array
    {
        $this->load();
        return $this->skills;
    }
    
    /**
     * 获取规则摘要
     */
    public function getSummary(): array
    {
        $this->load();
        
        return [
            'rules_count' => count($this->rules),
            'skills_count' => count($this->skills),
            'rules' => array_keys($this->rules),
            'skills' => array_keys($this->skills),
            'indexed_extensions' => array_keys($this->ruleIndex['by_extension']),
            'indexed_keywords' => count($this->ruleIndex['by_keyword']),
        ];
    }
}
