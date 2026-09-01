<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\Guide;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PaymentGuideI18nService;

/**
 * 为支付客户指南未译词条入队 I18n AI 翻译。
 */
class I18nTranslate extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var PaymentGuideI18nService $service */
        $service = ObjectManager::getInstance(PaymentGuideI18nService::class);

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
        $force = $this->hasFlag($args, 'force');
        $allPhrases = $this->hasFlag($args, 'all-phrases');
        $asJson = $this->hasFlag($args, 'json');

        try {
            $result = $service->enqueueAiTranslation(
                $locale,
                $methodCode !== '' ? $methodCode : null,
                !$allPhrases,
                $force,
            );
        } catch (\InvalidArgumentException $exception) {
            $printing->error($exception->getMessage());

            return 'error';
        }

        if ($asJson) {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing($encoded, ($result['queue_id'] ?? 0) > 0 ? 'success' : 'note');

            return $encoded;
        }

        if ((int) ($result['phrase_count'] ?? 0) === 0) {
            $printing->success((string) __('支付指南词条已全部翻译，无需入队。'));

            return 'ok';
        }

        if ((int) ($result['queue_id'] ?? 0) <= 0) {
            $printing->warning((string) __('未能创建 AI 翻译队列，请检查 I18n 配置与 Queue 模块。'));

            return 'failed';
        }

        $printing->success((string) __(
            '已入队支付指南 AI 翻译：队列 #%{1}，locale=%{2}，词条=%{3}',
            [
                (string) ($result['queue_id'] ?? 0),
                (string) ($result['locale'] ?? ''),
                (string) ($result['phrase_count'] ?? 0),
            ],
        ));

        return 'ok';
    }

    public function tip(): string
    {
        return (string) __('为支付客户指南未译词条入队 I18n AI 翻译');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'payment:guide:i18n-translate',
            $this->tip(),
            [
                '--method=' => (string) __('限定支付方式 code；省略则处理全部已注册指南'),
                '--locale=' => (string) __('目标语言，默认 en_US'),
                '--all-phrases' => (string) __('翻译全部收集词条，而非仅缺失项'),
                '--force' => (string) __('忽略同 locale/method 进行中的队列去重'),
                '--json' => (string) __('JSON 输出'),
                '-h, --help' => (string) __('显示本帮助'),
            ],
            [],
            [
                'php bin/w payment:guide:i18n-translate',
                'php bin/w payment:guide:i18n-translate --method=paypal --locale=en_US',
                'php bin/w payment:guide:i18n-translate --all-phrases --force',
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
