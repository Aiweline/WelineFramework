<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Shell;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Shell\ShellAliasManager;

/**
 * 项目快捷命令：在任意目录输入命令名即可进入项目并执行 php bin/w。
 *
 * 同一个项目只保留一个别名，改名时旧别名会被移除。
 */
final class Alias extends CommandAbstract
{
    public function __construct(
        private readonly Printing $printing
    ) {
    }

    public function execute(array $args = [], array $data = []): int
    {
        $manager = new ShellAliasManager();
        if (!$manager->isSupported()) {
            $this->printing->warning('当前系统不支持 shell 快捷命令（仅支持 macOS / Linux）。');

            return 1;
        }

        if (isset($args['ask'])) {
            return $manager->promptInstall();
        }

        if (isset($args['remove'])) {
            $result = $manager->remove();
            foreach ($result['errors'] as $error) {
                $this->printing->error($error);
            }
            if ($result['errors'] !== []) {
                return 1;
            }
            if ($result['files'] === [] && $result['scripts'] === []) {
                $this->printing->note('没有找到本项目的快捷命令。');

                return 0;
            }
            $removed = array_merge($result['files'], $result['scripts']);
            $this->printing->success('已删除项目快捷命令（' . implode('、', $removed) . '）。');

            return 0;
        }

        $rawName = $args['name'] ?? null;
        if ($rawName === null) {
            $rawName = $this->positionalName($args);
        }
        if ($rawName !== null) {
            if (!is_string($rawName) || trim($rawName) === '') {
                $this->printing->error('请提供命令名，例如：php bin/w shell:alias weline');

                return 1;
            }
            $name = trim($rawName);
            $error = ShellAliasManager::validateName($name);
            if ($error !== null) {
                $this->printing->error($error);

                return 1;
            }
            $result = $manager->install($name);
            foreach ($result['errors'] as $error) {
                $this->printing->error($error);
            }
            if ($result['errors'] !== []) {
                return 1;
            }
            $this->printing->success('项目快捷命令已设置：' . $name);
            foreach ($result['files'] as $file) {
                $this->printing->note('已写入：' . $file);
            }
            if ($result['script'] !== null) {
                $this->printing->note('立即生效（无需 source）：' . $result['script']);
            }
            foreach ($result['warnings'] as $warning) {
                $this->printing->note('提示：' . $warning);
            }
            $this->printing->note('用法：' . $name . '  → 进入 ' . $manager->getRoot() . ' 并执行 php bin/w');
            $this->printing->note('      ' . $name . ' cache:clear  → 等价于 php bin/w cache:clear');
            if ($result['script'] === null) {
                $this->printing->note('当前终端需 source ~/.zshrc 或新终端后生效。');
            } else {
                $this->printing->note('新终端会自动加载同名 shell 函数，执行后保持项目目录。');
            }

            return 0;
        }

        $found = $manager->find();
        $scripts = $manager->findScripts();
        if ($found === [] && $scripts === []) {
            $this->printing->note('当前项目还没有快捷命令。');
            $this->printing->note('设置：php bin/w shell:alias weline');
            $this->printing->note('删除：php bin/w shell:alias --remove');

            return 0;
        }
        $names = array_values(array_unique(array_merge(
            array_column($found, 'name'),
            array_column($scripts, 'name')
        )));
        if (count($names) > 1) {
            $this->printing->warning('检测到多个快捷命令：' . implode('、', $names) . '，请重新设置以只保留一个。');
        }
        foreach ($found as $item) {
            $this->printing->note('当前快捷命令：' . $item['name'] . '（' . $item['file'] . '）');
        }
        foreach ($scripts as $item) {
            $this->printing->note('命令文件：' . $item['name'] . '（' . $item['file'] . '）');
        }
        $this->printing->note('用法：' . $names[0] . ' cache:clear  → 等价于 php bin/w cache:clear');

        return 0;
    }

    /**
     * 兼容 `php bin/w shell:alias weline` 这种位置参数写法。
     */
    private function positionalName(array $args): ?string
    {
        $value = $args[1] ?? null;
        if (!is_string($value) || $value === '' || str_starts_with($value, '-')) {
            return null;
        }

        return $value;
    }

    public function tip(): string
    {
        return '设置项目快捷命令（任意目录进入项目并执行 php bin/w）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'shell:alias',
            $this->tip(),
            [
                '--name=<命令名>' => '设置快捷命令名（如 weline）；会先移除之前的别名，只保留一个',
                '--remove' => '删除本项目的快捷命令',
                '--ask' => '安装器专用：交互输入命令名，15 秒无输入自动跳过',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '写入文件' => '~/.zshrc、~/.bashrc、~/.bash_profile（新终端保持目录）；PATH 中的 ~/.local/bin/<命令名>（当前终端立即生效，无需 source）',
                '命令行为' => '进入项目目录（当前 shell 保持在该目录）并以项目用户执行 php bin/w，参数原样透传',
            ],
            [
                '设置命令名（如 weline）' => 'php bin/w shell:alias weline',
                '查看当前命令' => 'php bin/w shell:alias',
                '删除命令' => 'php bin/w shell:alias --remove',
            ],
            'php bin/w shell:alias [<命令名>|--name=<命令名>|--remove|--ask]'
        );
    }
}
