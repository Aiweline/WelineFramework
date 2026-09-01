<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\Guide;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PaymentGuideI18nValidator;

/**
 * 强制校验支付客户指南模板 &lt;lang&gt; 与基础中英文 CSV 完整度（与 setup:upgrade 相同规则）。
 */
class I18nValidate extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var PaymentGuideI18nValidator $validator */
        $validator = ObjectManager::getInstance(PaymentGuideI18nValidator::class);

        if ($this->hasHelpFlag($args)) {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        $methodCode = trim((string) ($this->optionValue($args, 'method') ?? ''));
        $asJson = $this->hasJsonFlag($args);
        $strict = !$this->hasNoFailFlag($args);

        try {
            if ($strict) {
                $validator->validateOrFail($methodCode !== '' ? $methodCode : null);
                $result = ['complete' => true];
            } else {
                $result = $validator->validate($methodCode !== '' ? $methodCode : null);
            }
        } catch (\Throwable $exception) {
            if ($asJson) {
                $encoded = json_encode([
                    'complete' => false,
                    'error' => $exception->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
                $printing->printing($encoded, 'error');

                return 'failed';
            }

            $printing->error($exception->getMessage());

            return 'failed';
        }

        if ($asJson) {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing($encoded, !empty($result['complete']) ? 'success' : 'warning');

            return !empty($result['complete']) ? 'ok' : 'incomplete';
        }

        $printing->success((string) __('支付客户指南 i18n 校验通过（模板 &lt;lang&gt; + zh_Hans_CN + en_US）。'));

        return 'ok';
    }

    public function tip(): string
    {
        return (string) __('强制校验支付客户指南模板与基础中英文 CSV（setup:upgrade 同款规则）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'payment:guide:i18n-validate',
            $this->tip(),
            [
                '--method=' => (string) __('限定支付方式 code，例如 paypal、fake_card'),
                '--json' => (string) __('以 JSON 输出校验结果'),
                '--no-fail' => (string) __('仅报告问题，不以非零状态终止（供 CI 预览）'),
                '-h, --help' => (string) __('显示本帮助'),
            ],
            [],
            [
                'php bin/w payment:guide:i18n-validate',
                'php bin/w payment:guide:i18n-validate --method=paypal',
                'php bin/w payment:guide:i18n-validate --json --no-fail',
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
    private function hasNoFailFlag(array $args): bool
    {
        foreach ($args as $arg) {
            if (strtolower(trim((string) $arg)) === '--no-fail') {
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
