<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase\Console\Cli;

use Weline\Framework\App\State;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\CliLanguage;
use Weline\Framework\Phrase\CliLanguageCatalog;

/**
 * cli:language — select Phrase locale for CLI (env.php cli_language).
 */
class Language extends CommandAbstract
{
    public function __construct(
        private readonly System $system,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $showOnly = isset($args['show']) || \in_array('--show', $args, true) || \in_array('-s', $args, true);
        $positional = [];
        foreach ($args as $key => $value) {
            if (\is_int($key) && \is_string($value) && !\str_starts_with($value, '-')) {
                $positional[] = $value;
            }
        }
        // drop command name
        \array_shift($positional);
        $requested = CliLanguage::normalize((string)($positional[0] ?? ''));

        $bootstrapLang = CliLanguage::resolveConfigured();
        State::setRequestLanguageOverride($bootstrapLang);

        if ($showOnly) {
            $this->printer->success(__('当前 CLI 语言：%{1}', [$bootstrapLang]));
            return;
        }

        if ($requested !== '') {
            $this->applyLanguage($requested);
            return;
        }

        if (!$this->isInteractiveTerminal()) {
            $this->printer->error(__('非交互终端请指定语言代码，例如：php bin/w cli:language zh_Hans_CN'));
            $this->printer->note(__('查看当前：php bin/w cli:language --show'));
            return;
        }

        /** @var CliLanguageCatalog $catalog */
        $catalog = ObjectManager::getInstance(CliLanguageCatalog::class);
        $languages = $catalog->languages();
        if ($languages === []) {
            $this->printer->error(__('没有可选的 CLI 语言'));
            return;
        }

        $this->printer->note(__('当前 CLI 语言：%{1}', [$bootstrapLang]));
        $this->printer->setup(__('可选语言（输入编号或语言代码）：'));
        foreach ($languages as $index => $row) {
            $mark = $row['code'] === $bootstrapLang ? ' *' : '';
            $label = $row['label'] !== '' && $row['label'] !== $row['code']
                ? ' — ' . $row['label']
                : '';
            $this->printer->printing('  [' . ($index + 1) . '] ' . $row['code'] . $label . $mark);
        }

        $this->printer->printing(__('请选择：'));
        $input = \trim((string)$this->system->input());
        if ($input === '') {
            $this->printer->warning(__('已取消'));
            return;
        }

        if (\ctype_digit($input)) {
            $idx = (int)$input - 1;
            if (!isset($languages[$idx])) {
                $this->printer->error(__('无效编号：%{1}', [$input]));
                return;
            }
            $this->applyLanguage($languages[$idx]['code']);
            return;
        }

        $this->applyLanguage($input);
    }

    private function applyLanguage(string $code): void
    {
        try {
            $applied = CliLanguage::set($code);
        } catch (\Throwable $e) {
            $this->printer->error($e->getMessage());
            return;
        }

        $this->printer->success(__('CLI 语言已设置为：%{1}', [$applied]));
        $this->printer->note(__('新进程命令将使用该语种，例如：php bin/w server:status'));
    }

    private function isInteractiveTerminal(): bool
    {
        if (\function_exists('stream_isatty') && \defined('STDIN')) {
            try {
                return @\stream_isatty(STDIN);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public function tip(): string
    {
        return (string)__('设置 CLI 短语语言（写入 env.php cli_language）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cli:language',
            $this->tip(),
            [
                '-h, --help' => (string)__('显示帮助信息'),
                '-s, --show' => (string)__('仅显示当前 CLI 语言'),
            ],
            [
                'locale' => (string)__('语言代码，如 zh_Hans_CN / en_US'),
            ],
            [
                (string)__('查看当前') => 'php bin/w cli:language --show',
                (string)__('设为中文') => 'php bin/w cli:language zh_Hans_CN',
                (string)__('交互选择') => 'php bin/w cli:language',
            ]
        );
    }
}
