<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Server\Service;

/**
 * 内存缓存表达式解析器
 * 
 * 解析类 Cloudflare 规则表达式，用于判断请求是否应该缓存
 * 
 * 支持的字段：
 * - http.request.uri.path - URI 路径
 * - http.request.uri.query - 查询字符串
 * - http.request.method - HTTP 方法
 * - http.host - 主机名
 * - http.request.headers["X-Header-Name"] - 请求头
 * 
 * 支持的运算符：
 * - eq - 等于
 * - ne - 不等于
 * - matches - 正则匹配
 * - contains - 包含
 * - starts_with - 开头匹配
 * - ends_with - 结尾匹配
 * - in - 在列表中
 * 
 * 支持的逻辑运算符：
 * - and - 与
 * - or - 或
 * - not - 非
 * 
 * @package Weline_Server
 */
class MemoryCacheExpressionParser
{
    /**
     * 请求上下文
     * 
     * @var array
     */
    private array $context = [];

    /**
     * 设置请求上下文
     * 
     * @param array $context 请求上下文
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * 从原始请求构建上下文
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return self
     */
    public function buildContextFromRequest(string $rawRequest): self
    {
        $lines = explode("\r\n", $rawRequest);
        $firstLine = $lines[0] ?? '';
        
        // 解析请求行
        $parts = explode(' ', $firstLine);
        $method = $parts[0] ?? 'GET';
        $fullUri = $parts[1] ?? '/';
        
        // 分离 URI 和查询字符串
        $uriParts = parse_url($fullUri);
        $uri = $uriParts['path'] ?? '/';
        $queryString = $uriParts['query'] ?? '';
        
        // 解析请求头
        $headers = [];
        $host = '';
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (empty($line)) {
                break;
            }
            
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $headerName = strtolower(trim(substr($line, 0, $colonPos)));
                $headerValue = trim(substr($line, $colonPos + 1));
                $headers[$headerName] = $headerValue;
                
                if ($headerName === 'host') {
                    $host = $headerValue;
                }
            }
        }

        $this->context = [
            'http.request.uri.path' => $uri,
            'http.request.uri.query' => $queryString,
            'http.request.method' => strtoupper($method),
            'http.host' => $host,
            'http.request.headers' => $headers,
            'http.request.full_uri' => $fullUri,
        ];

        return $this;
    }

    /**
     * 评估表达式
     * 
     * @param string $expression 表达式字符串
     * @return bool
     */
    public function evaluate(string $expression): bool
    {
        $expression = trim($expression);
        
        if (empty($expression)) {
            return true;
        }

        try {
            return $this->parseExpression($expression);
        } catch (\Exception $e) {
            w_log_error("表达式解析错误: {$expression}, 错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 解析表达式
     * 
     * @param string $expression 表达式
     * @return bool
     */
    private function parseExpression(string $expression): bool
    {
        $expression = trim($expression);
        
        // 处理括号表达式
        if (str_starts_with($expression, '(') && $this->findMatchingParen($expression, 0) === strlen($expression) - 1) {
            return $this->parseExpression(substr($expression, 1, -1));
        }

        // 处理 not 运算符
        if (str_starts_with($expression, 'not ')) {
            return !$this->parseExpression(substr($expression, 4));
        }

        // 查找 or 运算符（优先级最低）
        $orPos = $this->findLogicalOperator($expression, ' or ');
        if ($orPos !== false) {
            $left = substr($expression, 0, $orPos);
            $right = substr($expression, $orPos + 4);
            return $this->parseExpression($left) || $this->parseExpression($right);
        }

        // 查找 and 运算符
        $andPos = $this->findLogicalOperator($expression, ' and ');
        if ($andPos !== false) {
            $left = substr($expression, 0, $andPos);
            $right = substr($expression, $andPos + 5);
            return $this->parseExpression($left) && $this->parseExpression($right);
        }

        // 解析基本比较表达式
        return $this->parseComparison($expression);
    }

    /**
     * 查找逻辑运算符位置（忽略括号内的）
     * 
     * @param string $expression 表达式
     * @param string $operator 运算符
     * @return int|false
     */
    private function findLogicalOperator(string $expression, string $operator): int|false
    {
        $depth = 0;
        $len = strlen($expression);
        $opLen = strlen($operator);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $expression[$i];
            
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0 && substr($expression, $i, $opLen) === $operator) {
                return $i;
            }
        }

        return false;
    }

    /**
     * 查找匹配的右括号
     * 
     * @param string $expression 表达式
     * @param int $start 起始位置
     * @return int
     */
    private function findMatchingParen(string $expression, int $start): int
    {
        $depth = 0;
        $len = strlen($expression);
        
        for ($i = $start; $i < $len; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * 解析比较表达式
     * 
     * @param string $expression 比较表达式
     * @return bool
     */
    private function parseComparison(string $expression): bool
    {
        $expression = trim($expression);
        
        // 支持的比较运算符
        $operators = [
            ' matches ' => 'matches',
            ' contains ' => 'contains',
            ' starts_with ' => 'starts_with',
            ' ends_with ' => 'ends_with',
            ' eq ' => 'eq',
            ' ne ' => 'ne',
            ' in ' => 'in',
        ];

        foreach ($operators as $opPattern => $opName) {
            $pos = strpos($expression, $opPattern);
            if ($pos !== false) {
                $field = trim(substr($expression, 0, $pos));
                $value = trim(substr($expression, $pos + strlen($opPattern)));
                
                $fieldValue = $this->getFieldValue($field);
                $compareValue = $this->parseValue($value);
                
                return $this->compare($fieldValue, $opName, $compareValue);
            }
        }

        // 如果没有运算符，尝试作为布尔字段处理
        $fieldValue = $this->getFieldValue($expression);
        return !empty($fieldValue);
    }

    /**
     * 获取字段值
     * 
     * @param string $field 字段名
     * @return mixed
     */
    private function getFieldValue(string $field): mixed
    {
        $field = trim($field);
        
        // 处理请求头字段 http.request.headers["X-Header-Name"]
        if (preg_match('/^http\.request\.headers\["([^"]+)"\]$/', $field, $matches)) {
            $headerName = strtolower($matches[1]);
            return $this->context['http.request.headers'][$headerName] ?? '';
        }

        // 处理标准字段
        return $this->context[$field] ?? '';
    }

    /**
     * 解析值
     * 
     * @param string $value 值字符串
     * @return mixed
     */
    private function parseValue(string $value): mixed
    {
        $value = trim($value);
        
        // 字符串值（双引号）
        if (preg_match('/^"([^"]*)"$/', $value, $matches)) {
            return $matches[1];
        }
        
        // 字符串值（单引号）
        if (preg_match("/^'([^']*)'$/", $value, $matches)) {
            return $matches[1];
        }

        // 数组值
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $arrayContent = substr($value, 1, -1);
            $items = array_map('trim', explode(',', $arrayContent));
            return array_map(fn($item) => $this->parseValue($item), $items);
        }

        // 数字
        if (is_numeric($value)) {
            return (float)$value;
        }

        // 布尔值
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * 执行比较
     * 
     * @param mixed $fieldValue 字段值
     * @param string $operator 运算符
     * @param mixed $compareValue 比较值
     * @return bool
     */
    private function compare(mixed $fieldValue, string $operator, mixed $compareValue): bool
    {
        $fieldValue = (string)$fieldValue;
        
        switch ($operator) {
            case 'eq':
                return $fieldValue === (string)$compareValue;
                
            case 'ne':
                return $fieldValue !== (string)$compareValue;
                
            case 'matches':
                // 正则匹配
                $pattern = (string)$compareValue;
                // 如果没有分隔符，添加
                if (!preg_match('/^[\/\#\~]/', $pattern)) {
                    $pattern = '/' . $pattern . '/';
                }
                return (bool)preg_match($pattern, $fieldValue);
                
            case 'contains':
                return str_contains($fieldValue, (string)$compareValue);
                
            case 'starts_with':
                return str_starts_with($fieldValue, (string)$compareValue);
                
            case 'ends_with':
                return str_ends_with($fieldValue, (string)$compareValue);
                
            case 'in':
                if (!is_array($compareValue)) {
                    $compareValue = [$compareValue];
                }
                return in_array($fieldValue, array_map('strval', $compareValue), true);
                
            default:
                return false;
        }
    }

    /**
     * 获取当前上下文
     * 
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
