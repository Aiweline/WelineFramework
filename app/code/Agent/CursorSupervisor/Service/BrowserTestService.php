<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * 浏览器自动化测试服务
 * 
 * 生成 MCP Browser 调用指令，用于前端功能校验
 */
class BrowserTestService
{
    private bool $verbose = false;
    private array $testResults = [];
    private string $baseUrl = 'http://localhost:8080';
    
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    public function setBaseUrl(string $url): self
    {
        $this->baseUrl = rtrim($url, '/');
        return $this;
    }
    
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
    
    /**
     * 生成测试用例的 MCP 命令序列
     */
    public function generateTestCommands(string $url, array $actions = []): array
    {
        $commands = [];
        
        // 1. 导航
        $commands[] = [
            'server' => 'cursor-ide-browser',
            'tool' => 'browser_navigate',
            'args' => ['url' => $url, 'take_screenshot_afterwards' => true],
            'description' => "导航到 {$url}",
        ];
        
        // 2. 快照
        $commands[] = [
            'server' => 'cursor-ide-browser',
            'tool' => 'browser_snapshot',
            'args' => ['interactive' => true, 'compact' => true],
            'description' => '获取页面结构快照',
        ];
        
        // 3. 自定义操作
        foreach ($actions as $action) {
            if ($cmd = $this->actionToCommand($action)) {
                $commands[] = $cmd;
            }
        }
        
        // 4. 检查控制台
        $commands[] = [
            'server' => 'cursor-ide-browser',
            'tool' => 'browser_console_messages',
            'args' => [],
            'description' => '检查控制台消息',
        ];
        
        return $commands;
    }
    
    /**
     * 转换操作为 MCP 命令
     */
    private function actionToCommand(array $action): ?array
    {
        $type = $action['type'] ?? '';
        
        return match ($type) {
            'click' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_click',
                'args' => [
                    'element' => $action['desc'] ?? $action['description'] ?? 'element',
                    'ref' => $action['ref'] ?? '',
                ],
                'description' => "点击: {$action['desc']}",
            ],
            'fill' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_fill',
                'args' => [
                    'element' => $action['desc'] ?? 'input',
                    'ref' => $action['ref'] ?? '',
                    'value' => $action['value'] ?? '',
                ],
                'description' => "填写: {$action['desc']}",
            ],
            'type' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_type',
                'args' => [
                    'element' => $action['desc'] ?? 'input',
                    'ref' => $action['ref'] ?? '',
                    'text' => $action['text'] ?? '',
                ],
                'description' => "输入: {$action['text']}",
            ],
            'scroll' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_scroll',
                'args' => ['direction' => $action['direction'] ?? 'down'],
                'description' => '滚动页面',
            ],
            'wait' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_wait_for',
                'args' => [
                    'selector' => $action['selector'] ?? '',
                    'state' => $action['state'] ?? 'visible',
                    'timeout' => $action['timeout'] ?? 5000,
                ],
                'description' => "等待元素: {$action['selector']}",
            ],
            'screenshot' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_take_screenshot',
                'args' => [],
                'description' => '截图',
            ],
            default => null,
        };
    }
    
    /**
     * 生成快速测试命令（仅导航+快照+控制台检查）
     */
    public function generateQuickTest(string $url): array
    {
        return $this->generateTestCommands($url, []);
    }
    
    /**
     * 生成表单测试命令
     */
    public function generateFormTest(string $url, array $fields, string $submitRef): array
    {
        $actions = [];
        
        foreach ($fields as $field) {
            $actions[] = [
                'type' => 'fill',
                'desc' => $field['name'] ?? 'field',
                'ref' => $field['ref'],
                'value' => $field['value'],
            ];
        }
        
        $actions[] = ['type' => 'click', 'desc' => 'submit', 'ref' => $submitRef];
        $actions[] = ['type' => 'wait', 'selector' => 'body', 'state' => 'stable', 'timeout' => 3000];
        
        return $this->generateTestCommands($url, $actions);
    }
    
    /**
     * 解析快照中的可交互元素
     */
    public function parseInteractiveElements(array $snapshot): array
    {
        $elements = [
            'buttons' => [],
            'links' => [],
            'inputs' => [],
            'forms' => [],
        ];
        
        $this->traverseSnapshot($snapshot, function ($node) use (&$elements) {
            $role = strtolower($node['role'] ?? '');
            $ref = $node['ref'] ?? '';
            $name = $node['name'] ?? $node['text'] ?? '';
            
            if (empty($ref)) return;
            
            $item = ['ref' => $ref, 'name' => $name, 'role' => $role];
            
            if ($role === 'button' || str_contains($role, 'button')) {
                $elements['buttons'][] = $item;
            } elseif ($role === 'link' || str_contains($role, 'link')) {
                $elements['links'][] = $item;
            } elseif (in_array($role, ['textbox', 'combobox', 'searchbox', 'spinbutton'])) {
                $elements['inputs'][] = $item;
            } elseif ($role === 'form') {
                $elements['forms'][] = $item;
            }
        });
        
        return $elements;
    }
    
    /**
     * 遍历快照树
     */
    private function traverseSnapshot(array $node, callable $callback): void
    {
        $callback($node);
        
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->traverseSnapshot($child, $callback);
            }
        }
    }
    
    /**
     * 检查控制台消息是否有错误
     */
    public function hasJsErrors(array $consoleMessages): bool
    {
        foreach ($consoleMessages as $msg) {
            $type = $msg['type'] ?? '';
            if (in_array($type, ['error', 'exception'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 提取 JS 错误消息
     */
    public function extractJsErrors(array $consoleMessages): array
    {
        $errors = [];
        foreach ($consoleMessages as $msg) {
            $type = $msg['type'] ?? '';
            if (in_array($type, ['error', 'exception'])) {
                $errors[] = $msg['text'] ?? $msg['message'] ?? 'Unknown error';
            }
        }
        return $errors;
    }
    
    /**
     * 格式化测试报告
     */
    public function formatReport(string $url, array $results): string
    {
        $report = "🧪 前端测试报告: {$url}\n";
        $report .= str_repeat('─', 50) . "\n";
        
        $hasErrors = false;
        
        if (isset($results['console_messages'])) {
            $errors = $this->extractJsErrors($results['console_messages']);
            if (!empty($errors)) {
                $hasErrors = true;
                $report .= "❌ JavaScript 错误:\n";
                foreach ($errors as $err) {
                    $report .= "   - {$err}\n";
                }
            } else {
                $report .= "✅ 无 JavaScript 错误\n";
            }
        }
        
        if (isset($results['snapshot'])) {
            $elements = $this->parseInteractiveElements($results['snapshot']);
            $report .= "📊 页面元素:\n";
            $report .= "   - 按钮: " . count($elements['buttons']) . "\n";
            $report .= "   - 链接: " . count($elements['links']) . "\n";
            $report .= "   - 输入框: " . count($elements['inputs']) . "\n";
            $report .= "   - 表单: " . count($elements['forms']) . "\n";
        }
        
        $report .= str_repeat('─', 50) . "\n";
        $report .= $hasErrors ? "❌ 测试失败" : "✅ 测试通过";
        
        return $report;
    }
    
    public function getResults(): array
    {
        return $this->testResults;
    }
    
    public function addResult(string $name, array $result): void
    {
        $this->testResults[$name] = $result;
    }
}
