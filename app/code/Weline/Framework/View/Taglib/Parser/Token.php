<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 词法 Token
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Parser
 */

namespace Weline\Framework\View\Taglib\Parser;

/**
 * Token 类型枚举
 */
enum TokenType: string
{
    case Text = 'text';           // 纯文本
    case OpenTag = 'open';        // 开始标签 <tag>
    case CloseTag = 'close';      // 结束标签 </tag>
    case SelfCloseTag = 'self';   // 自闭合标签 <tag/>
    case InlineTag = 'inline';    // 内联标签 @tag(...)
    case Placeholder = 'php';     // PHP 占位符
}

/**
 * 词法 Token
 * 
 * 表示模板解析的基本单元
 */
class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $line,
        public readonly array $meta = [],
    ) {}

    /**
     * 获取元数据
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * 是否为文本类型
     */
    public function isText(): bool
    {
        return $this->type === TokenType::Text;
    }

    /**
     * 是否为标签类型
     */
    public function isTag(): bool
    {
        return match ($this->type) {
            TokenType::OpenTag, TokenType::CloseTag, TokenType::SelfCloseTag, TokenType::InlineTag => true,
            default => false,
        };
    }

    /**
     * 是否为内联标签
     */
    public function isInlineTag(): bool
    {
        return $this->type === TokenType::InlineTag;
    }

    /**
     * 是否为占位符
     */
    public function isPlaceholder(): bool
    {
        return $this->type === TokenType::Placeholder;
    }

    /**
     * 转换为调试字符串
     */
    public function toDebugString(): string
    {
        $preview = strlen($this->value) > 30 
            ? substr($this->value, 0, 30) . '...' 
            : $this->value;
        $preview = str_replace(["\n", "\r"], ['\\n', '\\r'], $preview);
        return sprintf('%s[line=%d](%s)', $this->type->value, $this->line, $preview);
    }
}
