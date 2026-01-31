<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 运行期标签渲染器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Runtime
 */

namespace Weline\Framework\View\Taglib\Runtime;

use Weline\Framework\View\Template;

/**
 * 运行期标签渲染器
 * 
 * 处理需要在运行时执行的标签
 */
final class RuntimeTag
{
    /**
     * 运行期标签回调
     * @var array<string, callable>
     */
    private array $callbacks = [];

    /**
     * 当前模板
     */
    private ?Template $template = null;

    /**
     * 设置当前模板
     */
    public function setTemplate(Template $template): void
    {
        $this->template = $template;
    }

    /**
     * 获取当前模板
     */
    public function getTemplate(): ?Template
    {
        return $this->template;
    }

    /**
     * 注册运行期标签回调
     */
    public function registerCallback(string $tagName, callable $callback): void
    {
        $this->callbacks[$tagName] = $callback;
    }

    /**
     * 批量注册回调
     */
    public function registerCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $name => $callback) {
            $this->callbacks[$name] = $callback;
        }
    }

    /**
     * 渲染运行期标签
     * 
     * @param string $tagName 标签名
     * @param array $attributes 属性
     * @param string $content 子内容
     * @return string 渲染结果
     */
    public function render(string $tagName, array $attributes, string $content = ''): string
    {
        $callback = $this->callbacks[$tagName] ?? null;

        if ($callback === null) {
            // 尝试查找命名空间标签
            $callback = $this->resolveNamespacedTag($tagName);
        }

        if ($callback === null) {
            // 无回调，返回内容
            return $content;
        }

        // 准备参数
        $params = array_merge($attributes, [
            '__tagName' => $tagName,
            '__content' => $content,
            '__template' => $this->template,
        ]);

        // 执行回调
        $result = $callback($params);

        return is_string($result) ? $result : '';
    }

    /**
     * 解析命名空间标签
     * 
     * 例如：w:seo:account:select => Weline\Seo\Taglib\AccountSelect
     */
    private function resolveNamespacedTag(string $tagName): ?callable
    {
        if (!str_contains($tagName, ':')) {
            return null;
        }

        $parts = explode(':', $tagName);
        
        // 至少需要 namespace:tag
        if (count($parts) < 2) {
            return null;
        }

        // 构建类名
        // w:seo:account:select => Weline\Seo\Taglib\AccountSelect
        $namespace = array_shift($parts);
        $className = $this->buildTagClassName($namespace, $parts);

        if (!class_exists($className)) {
            return null;
        }

        // 检查是否有 callback 方法
        if (!method_exists($className, 'callback')) {
            return null;
        }

        // 缓存回调
        $this->callbacks[$tagName] = [$className, 'callback'];

        return [$className, 'callback'];
    }

    /**
     * 构建标签类名
     */
    private function buildTagClassName(string $namespace, array $parts): string
    {
        // 处理命名空间映射
        $vendorMap = [
            'w' => 'Weline',
        ];

        $vendor = $vendorMap[$namespace] ?? ucfirst($namespace);

        // 最后一部分是标签名，其他是模块路径
        $tagName = array_pop($parts);
        $modulePath = implode('\\', array_map('ucfirst', $parts));

        // 构建完整类名
        $className = "{$vendor}\\{$modulePath}\\Taglib\\" . $this->toClassName($tagName);

        return $className;
    }

    /**
     * 转换为类名格式
     */
    private function toClassName(string $name): string
    {
        // kebab-case 或 snake_case 转 PascalCase
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);
        return str_replace(' ', '', $name);
    }

    /**
     * 检查标签是否已注册
     */
    public function hasCallback(string $tagName): bool
    {
        return isset($this->callbacks[$tagName]);
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        return [
            'callbackCount' => count($this->callbacks),
        ];
    }
}
