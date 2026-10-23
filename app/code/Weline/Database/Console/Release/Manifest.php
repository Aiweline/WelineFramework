<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Throwable;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;

/** Build and print the current declared SchemaManifest. */
final class Manifest implements CommandInterface
{
    public function __construct(private readonly SchemaManifestBuilder $builder)
    {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $modules = trim((string)($args['module'] ?? ''));
        $filter = $modules === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $modules))));

        try {
            $manifest = isset($args['actual'])
                ? $this->builder->buildActual($filter)
                : $this->builder->build($filter);
        } catch (Throwable $exception) {
            echo 'Error: ' . $exception->getMessage() . PHP_EOL;

            return '';
        }

        $output = (string)json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $path = trim((string)($args['output'] ?? ''));
        if ($path !== '') {
            if (file_put_contents($path, $output . PHP_EOL) === false) {
                echo 'Error: cannot write manifest: ' . $path . PHP_EOL;

                return '';
            }
            echo 'Manifest written: ' . $path . PHP_EOL;
        } else {
            echo $output . PHP_EOL;
        }

        return $output;
    }

    public function tip(): string
    {
        return __('生成当前代码的 RSSE SchemaManifest');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:manifest', $this->tip(), [
            '--module' => __('只生成指定模块，逗号分隔'),
            '--actual' => __('从实际库生成 baseline manifest（cutover 用）'),
            '--output' => __('写入文件；缺省输出到 stdout'),
        ], [], [
            'php bin/w release:manifest --output=/tmp/schema-manifest.json',
            'php bin/w release:manifest --module=Weline_Eav',
        ]);
    }
}
