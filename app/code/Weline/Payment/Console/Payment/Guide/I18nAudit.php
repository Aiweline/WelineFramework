<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\Guide;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PaymentGuideI18nCatalog;

/**
 * 审计支付客户指南词条在目标语言 CSV 中的翻译完整度。
 */
class I18nAudit extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var PaymentGuideI18nCatalog $catalog */
        $catalog = ObjectManager::getInstance(PaymentGuideI18nCatalog::class);

        if ($this->hasHelpFlag($args)) {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        $methodCode = trim((string) ($this->optionValue($args, 'method') ?? ''));
        $locale = trim((string) ($this->optionValue($args, 'locale') ?? 'en_US'));
        $asJson = $this->hasJsonFlag($args);

        $report = $catalog->audit($methodCode !== '' ? $methodCode : null, $locale);

        if ($asJson) {
            $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing($encoded, $report['summary']['complete'] ? 'success' : 'warning');

            return $encoded;
        }

        $this->printHumanReport($printing, $report);

        return $report['summary']['complete'] ? 'ok' : 'incomplete';
    }

    public function tip(): string
    {
        return (string) __('审计支付指南模板词条在目标语言 CSV 中的翻译缺口');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'payment:guide:i18n-audit',
            $this->tip(),
            [
                '--method=' => (string) __('限定支付方式 code，例如 paypal、fake_card'),
                '--locale=' => (string) __('目标语言，默认 en_US'),
                '--json' => (string) __('以 JSON 输出审计结果'),
                '-h, --help' => (string) __('显示本帮助'),
            ],
            [],
            [
                'php bin/w payment:guide:i18n-audit',
                'php bin/w payment:guide:i18n-audit --method=paypal --locale=en_US',
                'php bin/w payment:guide:i18n-audit --json',
            ],
        );
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printHumanReport(Printing $printing, array $report): void
    {
        $summary = $report['summary'];
        $printing->note((string) __(
            '支付指南 i18n 审计：locale=%{1}，词条=%{2}，缺失=%{3}',
            [
                (string) ($report['locale'] ?? ''),
                (string) ($summary['total_phrases'] ?? 0),
                (string) ($summary['missing_phrases'] ?? 0),
            ],
        ));

        foreach ((array) ($report['methods'] ?? []) as $methodReport) {
            $this->printSection($printing, (string) ($methodReport['method_code'] ?? ''), $methodReport);
        }

        $shared = (array) ($report['shared'] ?? []);
        if ($shared !== []) {
            $this->printSection($printing, 'shared', $shared);
        }

        if (!empty($summary['complete'])) {
            $printing->success((string) __('支付指南词条翻译完整。'));
        } else {
            $printing->warning((string) __('存在未翻译词条，请补全模块 i18n CSV 或运行 I18n AI 翻译。'));
        }
    }

    /**
     * @param array<string, mixed> $section
     */
    private function printSection(Printing $printing, string $label, array $section): void
    {
        $printing->printing(sprintf(
            '[%s] module=%s phrases=%d missing=%d',
            $label,
            (string) ($section['source_module'] ?? ''),
            (int) ($section['phrase_count'] ?? 0),
            (int) ($section['missing_count'] ?? 0),
        ), !empty($section['complete']) ? 'success' : 'warning');

        foreach ((array) ($section['missing'] ?? []) as $phrase) {
            $printing->printing('  - ' . $phrase, 'note');
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasHelpFlag(array $args): bool
    {
        foreach ($args as $arg) {
            $candidate = strtolower(trim((string) $arg));
            if (in_array($candidate, ['help', '-h', '--help'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasJsonFlag(array $args): bool
    {
        foreach ($args as $arg) {
            if (strtolower(trim((string) $arg)) === '--json') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            $arg = (string) $arg;
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }
}
