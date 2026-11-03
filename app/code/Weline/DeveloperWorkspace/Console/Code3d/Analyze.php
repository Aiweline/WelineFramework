<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Code3d;

use Weline\DeveloperWorkspace\Service\Code3d\CodeGraphStore;
use Weline\Framework\Console\CommandAbstract;

/**
 * 分析 app/code 生成代码 3D 图（graph.json + 自包含 code3d.html）。
 *
 * 用法: php bin/w code3d:analyze [--src=app/code] [--out=var/code3d]
 */
class Analyze extends CommandAbstract
{
    public const dir = 'Console\\Code3d';

    public function execute(array $args = [], array $data = [])
    {
        $store = new CodeGraphStore();
        $src = isset($args['src']) ? (string)$args['src'] : null;
        $out = isset($args['out']) ? (string)$args['out'] : null;
        if ($src !== null && !str_starts_with($src, '/')) {
            $src = $store->repoRoot() . $src;
        }
        if ($out !== null && !str_starts_with($out, '/')) {
            $out = $store->repoRoot() . $out;
        }

        $this->printer->printing('分析中: ' . ($src ?: $store->repoRoot() . 'app/code'));
        $meta = $store->build($src, $out);

        $this->printer->success('完成');
        $this->printer->printing(sprintf(
            '  文件 %d（解析失败 %d）· 节点 %d（类 %d/接口 %d/trait %d/enum %d/函数 %d）',
            $meta['files_scanned'],
            $meta['parse_errors'],
            $meta['nodes'],
            $meta['kinds']['c'],
            $meta['kinds']['i'],
            $meta['kinds']['t'],
            $meta['kinds']['n'],
            $meta['kinds']['f']
        ));
        $this->printer->printing(sprintf(
            '  方法 %d · 边 %d（继承 %d/依赖 %d/静态调用 %d/实例调用 %d/接口分发 %d）',
            $meta['methods'],
            $meta['edges'],
            $meta['edge_kinds']['e'] ?? 0,
            $meta['edge_kinds']['d'] ?? 0,
            $meta['edge_kinds']['s'] ?? 0,
            $meta['edge_kinds']['i'] ?? 0,
            $meta['edge_kinds']['u'] ?? 0
        ));
        $this->printer->printing(sprintf(
            '  动态调用：已解析落边 %d · 类型未知 %d · 模块 %d · 耗时 %ss',
            $meta['dyn_calls_resolved'] ?? 0,
            $meta['dyn_calls_unknown'] ?? 0,
            $meta['modules'],
            $meta['runtime_s']
        ));
        foreach ($meta['parse_error_samples'] as $pe) {
            $this->printer->warning('  跳过: ' . $pe[0]);
        }
        $this->printer->printing('  产物: ' . $store->htmlPath());
        $this->printer->printing('  查询: php bin/w code3d:query --stats');
    }

    public function tip(): string
    {
        return '分析 app/code 生成代码 3D 图（可在浏览器按层级查看）';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'code3d:analyze [--src=app/code] [--out=var/code3d]',
            $this->tip(),
            [
                '--src' => '扫描目录（默认 app/code）',
                '--out' => '输出目录（默认 var/code3d）',
            ],
            [],
            []
        );
    }
}
