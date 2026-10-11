<?php
declare(strict_types=1);

/**
 * Weline Framework
 *
 * @DESC         | head 资源顺序编译通道
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler\Pass
 */

namespace Weline\Framework\View\Taglib\Compiler\Pass;

use Weline\Framework\View\Taglib\Ast\Node;
use Weline\Framework\View\Taglib\Ast\ProgramNode;
use Weline\Framework\View\Taglib\Ast\TagNode;

/**
 * 按标签自身的顺序属性，对 head 资源标签做稳定排序。
 *
 * 参与排序的标签：`css` / `js` / `theme:css` / `theme:js`。
 *
 * 规则：
 * - 只对**带顺序属性**（`data-order`，或简写 `order`）的资源标签排序；
 *   不带该属性的节点一律原地不动（保持 no-op 语义）。
 * - 可排序标签的**占位集合不变**：排序只重排这些标签彼此之间的内容，
 *   不会把资源标签搬到别的区域（例如越过 `<title>` 或移到 `</head>` 之外）。
 * - 同 order 值按文档序稳定排列。
 * - 本通道运行于 CodeGenerator 执行标签回调**之前**，此时标签仍是 TagNode，
 *   属性完整可读；回调烘焙出的最终 `<link>` / `<script>` 会带上原属性。
 *
 * 无任何 `data-order` 时本通道是 no-op —— 输出与未启用时逐字节相同。
 *
 * 示例：
 * <theme:css data-order="40">frontend/assets/css/theme.css</theme:css>
 * <theme:js data-order="60" defer>frontend/js/app.js</theme:js>
 */
final class HeadAssetOrderPass implements CompilePassInterface
{
    /**
     * 参与排序的标签名
     */
    private const ASSET_TAGS = ['css', 'js', 'theme:css', 'theme:js'];

    /**
     * 顺序属性名。`data-order` 是原生 HTML 自定义数据属性；`order` 为简写。
     */
    private const ORDER_ATTRS = ['data-order', 'order'];

    public function process(ProgramNode $ast): ProgramNode
    {
        $children = $ast->children;
        if (count($children) < 2) {
            return $ast;
        }

        // 收集可排序标签：记录它在子节点列表中的位置、顺序值、文档序。
        $slots = [];
        $seq = 0;
        foreach ($children as $index => $node) {
            $order = $this->resolveOrder($node);
            if ($order === null) {
                continue;
            }
            $slots[$index] = ['order' => $order, 'seq' => $seq++, 'node' => $node];
        }

        // 0 或 1 个可排序标签：无可重排，保持原样（no-op）。
        if (count($slots) < 2) {
            return $ast;
        }

        $sorted = $slots;
        uasort(
            $sorted,
            static fn(array $a, array $b): int
                => $a['order'] <=> $b['order'] ?: $a['seq'] <=> $b['seq']
        );

        // 占位集合不变，只把排好序的节点按原位置依次回填。
        $ordered = array_values($sorted);
        $offset = 0;
        foreach ($slots as $position => $_) {
            $children[$position] = $ordered[$offset]['node'];
            $offset++;
        }

        return $ast->withChildren(array_values($children));
    }

    /**
     * 读取节点的顺序值；非资源标签或未声明顺序时返回 null。
     */
    private function resolveOrder(Node $node): ?int
    {
        if (!$node instanceof TagNode) {
            return null;
        }
        if (!in_array($node->name, self::ASSET_TAGS, true)) {
            return null;
        }

        foreach (self::ORDER_ATTRS as $attrName) {
            $raw = $node->getAttr($attrName);
            if ($raw === null) {
                continue;
            }
            $value = trim((string)$raw);
            if ($value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
                continue;
            }
            return (int)$value;
        }

        return null;
    }

    public function getName(): string
    {
        return 'head-asset-order';
    }

    /**
     * 在死代码消除（20）之后、内联优化（60）之前执行。
     */
    public function getPriority(): int
    {
        return 40;
    }
}
