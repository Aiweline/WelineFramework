<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Code3d;

use Weline\DeveloperWorkspace\Service\Code3d\CodeGraphStore;
use Weline\Framework\Console\CommandAbstract;

/**
 * 快速启动：确保图数据存在（缺则先分析），然后用系统浏览器打开。
 *
 * 用法: php bin/w code3d:open [--rebuild] [--no-open]
 */
class Open extends CommandAbstract
{
    public const dir = 'Console\\Code3d';

    public function execute(array $args = [], array $data = [])
    {
        $store = new CodeGraphStore();
        $rebuild = isset($args['rebuild']);
        if (!$store->isBuilt() || $rebuild) {
            $this->printer->printing($store->isBuilt() ? '重建中...' : '首次使用，先分析 app/code（约 30s）...');
            $meta = $store->build();
            $this->printer->success(sprintf(
                '已生成: %d 节点 / %d 边 / %d 模块',
                $meta['nodes'],
                $meta['edges'],
                $meta['modules']
            ));
        }

        $path = $store->htmlPath();
        if (!is_file($path)) {
            throw new \RuntimeException('产物不存在: ' . $path);
        }
        $this->printer->printing('页面: ' . $path);

        if (isset($args['no-open'])) {
            return;
        }
        $err = '';
        if (PHP_OS_FAMILY === 'Darwin') {
            exec('open ' . escapeshellarg($path) . ' 2>&1', $o, $code);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            exec('start "" ' . escapeshellarg($path), $o, $code);
        } else {
            exec('xdg-open ' . escapeshellarg($path) . ' 2>&1', $o, $code);
        }
        if (($code ?? 1) !== 0) {
            $this->printer->warning('自动打开失败，请手动打开上面的路径');
            return;
        }
        $this->printer->success('已在浏览器打开');
    }

    public function tip(): string
    {
        return '打开代码 3D 图（缺数据时自动分析后打开）';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'code3d:open [--rebuild] [--no-open]',
            $this->tip(),
            [
                '--rebuild' => '强制重新分析后再打开',
                '--no-open' => '只生成并打印路径，不打开浏览器',
            ],
            [],
            []
        );
    }
}
