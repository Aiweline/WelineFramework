<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Exception;

/**
 * 支付客户指南 i18n 强制校验：模板 &lt;lang&gt; 契约 + 基础中英文 CSV 完整。
 */
class PaymentGuideI18nValidator
{
    /**
     * setup:upgrade / 供应商接入必须完整的基础语言。
     *
     * @var list<string>
     */
    public const REQUIRED_BASE_LOCALES = ['zh_Hans_CN', 'en_US'];

    public function __construct(
        private readonly PaymentGuideTemplateScanner $templateScanner,
        private readonly PaymentGuideI18nCatalog $catalog,
    ) {
    }

    /**
     * @return array{
     *     complete:bool,
     *     template_violations:list<array<string,mixed>>,
     *     locale_reports:array<string,array<string,mixed>>,
     *     summary:array{template_violations:int,missing_phrases:int}
     * }
     */
    public function validate(?string $methodCode = null): array
    {
        $templateViolations = $this->templateScanner->scanAll($methodCode);
        $localeReports = [];
        $missingTotal = 0;

        foreach (self::REQUIRED_BASE_LOCALES as $locale) {
            $report = $this->catalog->audit($methodCode, $locale);
            $missing = (int) ($report['summary']['missing_phrases'] ?? 0);
            $missingTotal += $missing;
            $localeReports[$locale] = $report;
        }

        return [
            'complete' => $templateViolations === [] && $missingTotal === 0,
            'template_violations' => $templateViolations,
            'locale_reports' => $localeReports,
            'summary' => [
                'template_violations' => count($templateViolations),
                'missing_phrases' => $missingTotal,
            ],
        ];
    }

    public function validateOrFail(?string $methodCode = null): void
    {
        $result = $this->validate($methodCode);
        if (!empty($result['complete'])) {
            return;
        }

        $lines = [];
        foreach ((array) ($result['template_violations'] ?? []) as $violation) {
            $lines[] = (string) ($violation['message'] ?? 'template violation');
        }

        foreach (self::REQUIRED_BASE_LOCALES as $locale) {
            $report = (array) (($result['locale_reports'][$locale] ?? []) ?: []);
            foreach ((array) ($report['methods'] ?? []) as $methodReport) {
                foreach ((array) ($methodReport['missing'] ?? []) as $phrase) {
                    $lines[] = (string) __(
                        '[%{1}] %{2} 缺少 %{3} 译文：%{4}',
                        [
                            (string) ($methodReport['method_code'] ?? ''),
                            (string) ($methodReport['source_module'] ?? ''),
                            $locale,
                            (string) $phrase,
                        ],
                    );
                }
            }

            $shared = (array) ($report['shared'] ?? []);
            foreach ((array) ($shared['missing'] ?? []) as $phrase) {
                $lines[] = (string) __(
                    '[shared] %{1} 缺少 %{2} 译文：%{3}',
                    [
                        (string) ($shared['source_module'] ?? PaymentGuideI18nCatalog::HUB_MODULE),
                        $locale,
                        (string) $phrase,
                    ],
                );
            }
        }

        $preview = array_slice($lines, 0, 40);
        $message = (string) __(
            '【致命错误】支付客户指南 i18n 未完整对接（模板 &lt;lang&gt; 与基础中英文 CSV）。共 %{1} 项问题。请补全供应商 guide/policy phtml 与 i18n/zh_Hans_CN.csv、en_US.csv 后重试。可先运行：php bin/w payment:guide:i18n-validate',
            [count($lines)],
        );
        $message .= "\n" . implode("\n", $preview);
        if (count($lines) > 40) {
            $message .= "\n" . (string) __(
                '其余 %{1} 条已省略；请运行 php bin/w payment:guide:i18n-validate --json 查看全部。',
                [count($lines) - 40],
            );
        }

        throw new Exception($message);
    }
}
