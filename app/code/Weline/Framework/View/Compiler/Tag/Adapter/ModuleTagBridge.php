<?php

declare(strict_types=1);

/**
 * 模块标签回调桥：把旧 `callback()` 的编译期调用**逐字**还原。
 *
 * 为什么不能「包一层别的协议」：86 个模块标签的回调语义并不统一——
 * 有的返回 PHP 源码（`<?php ... ?>`），有的直接在编译期返回 HTML（例如 `Acl`
 * 在编译期做权限判断后返回注释或内容）。旧编译器对两者是同一处理：
 * **把返回值原样拼进编译产物**。因此桥接的唯一忠实做法是同样的三步：
 *
 * 1. `$tagKey` 按旧顺序判定（`resolveTagKey()` 与 `Taglib::resolveTagKey()` 逐条对齐）；
 * 2. `$tagData = [开标签原文, 属性原文, 子节点编译结果]`；
 * 3. 返回值原样包成 `RawPhpStmt`（它的「raw」含义是「原样写入产物」，不是「一定是 PHP」）。
 *
 * 配对且声明了 `tag-end` 时，旧编译器会分别调 `tag-start` / `tag-end` 并拼成
 * `$start . $content . $end`，这里保持一致。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Adapter;

use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Parser\TagNode;

final class ModuleTagBridge
{
    /**
     * @param class-string<TaglibInterface> $taglibClass
     */
    public function __construct(
        private readonly string $taglibClass,
    ) {
    }

    /**
     * @param string $childrenOutput 子节点已经 Emit 出来的产物（旧协议里的 `$content`）
     */
    public function lower(TagNode $node, string $childrenOutput): RawPhpStmt
    {
        $class = $this->taglibClass;
        $config = $this->config($class);
        $tagKey = $this->resolveTagKey($node, $config);
        $tagName = $node->name();
        if (\str_starts_with(\strtolower($tagName), 'w:')) {
            $tagName = \substr($tagName, 2);
        }
        // 旧 registerTagCallbacks 的 $tag_data = ['<tagName>', rawContent, innerContent]；
        // 不是 openRaw/attributesRaw。模块回调会直接读这两个槽位。
        $rawContent = '';
        if (!$node->isSelfClosing()) {
            $whole = $node->raw();
            $openLength = \strlen($node->openRaw());
            $closeAt = \strrpos($whole, '</');
            if ($closeAt !== false && $closeAt >= $openLength) {
                $rawContent = \substr($whole, $openLength, $closeAt - $openLength);
            }
        }
        $tagData = ['<' . $tagName . '>', $rawContent, $childrenOutput];
        $attributes = $node->attributes();
        $callback = $class::callback();

        if ($tagKey === 'tag-start' && !empty($config['tag-end'])) {
            $start = (string)$callback('tag-start', $config, $tagData, $attributes);
            $end = (string)$callback('tag-end', $config, $tagData, $attributes);

            return new RawPhpStmt($start . $childrenOutput . $end, $node->span());
        }

        return new RawPhpStmt((string)$callback($tagKey, $config, $tagData, $attributes), $node->span());
    }

    /**
     * @param class-string<TaglibInterface> $class
     * @return array<string, mixed>
     */
    private function config(string $class): array
    {
        $config = [
            'class' => $class,
            'is_custom' => true,
            'attr' => $class::attr(),
        ];
        if ($class::tag()) {
            $config['tag'] = true;
        }
        if ($class::tag_start()) {
            $config['tag-start'] = true;
        }
        if ($class::tag_end()) {
            $config['tag-end'] = true;
        }
        if ($class::tag_self_close()) {
            $config['tag-self-close'] = true;
        }
        if ($class::tag_self_close_with_attrs()) {
            $config['tag-self-close-with-attrs'] = true;
        }

        return $config;
    }

    /**
     * 与 `Taglib::resolveTagKey()` 逐条对齐（顺序不能改）。
     *
     * @param array<string, mixed> $config
     */
    private function resolveTagKey(TagNode $node, array $config): string
    {
        if ($node->isSelfClosing()) {
            if (!empty($config['tag-self-close-with-attrs']) && \trim($node->attributesRaw()) !== '') {
                return 'tag-self-close-with-attrs';
            }
            if (!empty($config['tag-self-close'])) {
                return 'tag-self-close';
            }
        }
        if (!empty($config['tag'])) {
            return 'tag';
        }
        if (!empty($config['tag-start']) && !empty($config['tag-end'])) {
            return 'tag-start';
        }
        if (!empty($config['tag-self-close'])) {
            return 'tag-self-close';
        }

        return 'tag';
    }
}
