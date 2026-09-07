<?php

declare(strict_types=1);

namespace Weline\Customer\Console\Customer\Guide\SocialLogin;

use Weline\Customer\Service\SocialLogin\SocialLoginGuideI18nCatalog;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 审计社媒登录客户指南词条在目标语言 CSV 中的翻译完整度。
 */
class I18nAudit extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var SocialLoginGuideI18nCatalog $catalog */
        $catalog = ObjectManager::getInstance(SocialLoginGuideI18nCatalog::class);

        if ($this->hasHelpFlag($args)) {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        $providerCode = trim((string) ($this->optionValue($args, 'provider') ?? ''));
        $locale = trim((string) ($this->optionValue($args, 'locale') ?? 'en_US'));
        $asJson = $this->hasFlag($args, 'json');

        $report = $catalog->audit($providerCode !== '' ? $providerCode : null, $locale);

        if ($asJson) {
            $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing($encoded, $report['summary']['complete'] ? 'success' : 'warning');

            return $encoded;
        }

        $summary = $report['summary'];
        $printing->note((string) __(
            '社媒登录指南 i18n 审计：locale=%{1}，词条=%{2}，缺失=%{3}',
            [
                (string) ($report['locale'] ?? ''),
                (string) ($summary['total_phrases'] ?? 0),
                (string) ($summary['missing_phrases'] ?? 0),
            ],
        ));

        foreach ((array) ($report['providers'] ?? []) as $providerReport) {
            $printing->note((string) __(
                '提供方 %{1}：词条=%{2}，缺失=%{3}',
                [
                    (string) ($providerReport['provider_code'] ?? ''),
                    (string) ($providerReport['phrase_count'] ?? 0),
                    (string) ($providerReport['missing_count'] ?? 0),
                ],
            ));
            foreach (array_slice((array) ($providerReport['missing'] ?? []), 0, 8) as $phrase) {
                $printing->warning('  - ' . (string) $phrase);
            }
        }

        $shared = (array) ($report['shared'] ?? []);
        if ($shared !== []) {
            $printing->note((string) __(
                '共享外壳：词条=%{1}，缺失=%{2}',
                [
                    (string) ($shared['phrase_count'] ?? 0),
                    (string) ($shared['missing_count'] ?? 0),
                ],
            ));
            foreach (array_slice((array) ($shared['missing'] ?? []), 0, 8) as $phrase) {
                $printing->warning('  - ' . (string) $phrase);
            }
        }

        return $report['summary']['complete'] ? 'ok' : 'incomplete';
    }

    public function tip(): string
    {
        return (string) __('审计社媒登录指南模板词条在目标语言 CSV 中的翻译缺口');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'customer:guide:social-login:i18n-audit',
            $this->tip(),
            [
                '--provider=' => (string) __('限定提供方 code，例如 google、facebook'),
                '--locale=' => (string) __('目标语言，默认 en_US'),
                '--json' => (string) __('以 JSON 输出审计结果'),
                '-h, --help' => (string) __('显示本帮助'),
            ],
            [],
            [
                'php bin/w customer:guide:social-login:i18n-audit',
                'php bin/w customer:guide:social-login:i18n-audit --provider=google --locale=en_US',
                'php bin/w customer:guide:social-login:i18n-audit --json',
            ],
        );
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
    private function hasFlag(array $args, string $name): bool
    {
        $needle = '--' . $name;
        foreach ($args as $arg) {
            if (strtolower(trim((string) $arg)) === $needle) {
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
