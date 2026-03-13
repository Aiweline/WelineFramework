<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/26 21:32:48
 */

namespace Weline\Cron\Console\Cron;

class Install extends BaseCommand
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $cron_name = $this->getCronName($data['module']);

        // Linux/macOS 下若 crontab 命令不存在，先尝试自动安装
        if (PHP_OS_FAMILY !== 'Windows' && !$this->crontabExists()) {
            $this->printing->note(__('检测到 crontab 命令不可用，正在尝试自动安装依赖...'));
            if ($this->tryInstallCrontab()) {
                $this->printing->success(__('crontab 依赖已安装 ✔'));
            } else {
                $this->printing->warning(__('crontab 自动安装失败，请手动执行: php bin/w env:install crontab -y'));
            }
        }

        $result = $this->schedule->create($cron_name);
        if ($result['status']) {
            $this->printing->note($result['msg']);
        } else {
            $this->printing->warning($result['msg']);
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->printing->note(__('若因 crontab 未安装导致失败，请执行: php bin/w env:install crontab -y 后重试'));
            }
        }
    }

    /**
     * 检测 crontab 命令是否可用
     */
    private function crontabExists(): bool
    {
        $output = [];
        @exec('crontab -l 2>/dev/null', $output, $code);
        if ($code === 127) {
            return false;
        }
        return $this->commandExists('crontab');
    }

    private function commandExists(string $cmd): bool
    {
        $output = [];
        @exec("command -v $cmd 2>/dev/null", $output, $code);
        return $code === 0 && !empty($output);
    }

    /**
     * 尝试通过 env:install 安装 crontab 依赖
     */
    private function tryInstallCrontab(): bool
    {
        $root = \defined('BP') ? rtrim(BP, DIRECTORY_SEPARATOR) : (getcwd() ?: '');
        if ($root === '' || !is_file($root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
            return false;
        }
        $phpBin = (string)(\PHP_BINARY ?? 'php');
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/bin/w') . ' env:install crontab -y 2>&1';
        $output = [];
        exec($cmd, $output, $ret);
        return $ret === 0;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '安装系统定时任务。';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cron:install',
            '安装系统定时任务到系统计划任务中',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '安装定时任务' => 'php bin/w cron:install',
            ]
        );
    }
}
