<?php

declare(strict_types=1);

namespace Weline\Smtp\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Service\MailTemplateSetupCoverage;
use Weline\Websites\Model\Website;

/**
 * Smtp 建站任务：发信确认、模板就绪、多语言覆盖（自声明 + 自检）。
 */
class SmtpSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function __construct(
        private readonly Data $data,
        private readonly ?MailTemplateSetupCoverage $coverage = null,
    ) {
    }

    private function coverage(): MailTemplateSetupCoverage
    {
        return $this->coverage ?? ObjectManager::getInstance(MailTemplateSetupCoverage::class);
    }

    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $websiteId = (int)($context['website_id'] ?? Website::ID_DEFAULT);
        if ($websiteId < 0) {
            $websiteId = Website::ID_DEFAULT;
        }

        return $this->tasks([
            $this->transportTask($scope),
            $this->templateTask($scope),
            $this->localeTask($scope, $websiteId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transportTask(string $scope): array
    {
        $provenance = $this->data->resolveSendersProvenance('Weline_Smtp', $scope);
        $confirmed = $this->data->isSetupConfirmed('Weline_Smtp', $scope);
        $href = $this->backendPath('smtp/backend/config');

        if (empty($provenance['found'])) {
            return [
                'code' => 'smtp_transport',
                'parent_code' => 'smtp',
                'sort' => 10,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('配置 SMTP 发信'),
                'tip' => (string)__('尚未配置 SMTP 传输账户。请添加发件账户并发送测试邮件确认。'),
                'status' => 'todo',
                'href' => $href,
                'meta' => ['source_scope' => '', 'inherited' => false, 'confirmed' => false],
            ];
        }

        if ($confirmed) {
            $tip = !empty($provenance['inherited'])
                ? (string)__('已确认可用（当前继承自 %{1}）。', [(string)$provenance['source_scope']])
                : (string)__('已确认可用（当前范围自有配置）。');

            return [
                'code' => 'smtp_transport',
                'parent_code' => 'smtp',
                'sort' => 10,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('配置 SMTP 发信'),
                'tip' => $tip,
                'status' => 'done',
                'href' => $href,
                'meta' => [
                    'source_scope' => (string)$provenance['source_scope'],
                    'inherited' => !empty($provenance['inherited']),
                    'confirmed' => true,
                ],
            ];
        }

        $tip = !empty($provenance['inherited'])
            ? (string)__('已检测到继承自 %{1} 的 SMTP 配置，请打开配置页发送测试邮件确认。', [(string)$provenance['source_scope']])
            : (string)__('已检测到当前范围的 SMTP 配置，请发送测试邮件确认后再上线。');

        return [
            'code' => 'smtp_transport',
            'parent_code' => 'smtp',
            'sort' => 10,
            'category' => (string)__('通信'),
            'module' => 'Weline_Smtp',
            'title' => (string)__('配置 SMTP 发信'),
            'tip' => $tip,
            'status' => 'doing',
            'href' => $href,
            'meta' => [
                'source_scope' => (string)$provenance['source_scope'],
                'inherited' => !empty($provenance['inherited']),
                'confirmed' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateTask(string $scope): array
    {
        $href = $this->backendPath('smtp/backend/template/listing');
        $missing = [];
        try {
            $missing = $this->coverage()->channelsMissingTemplates($scope);
        } catch (\Throwable) {
            $missing = ['*'];
        }

        if ($missing === ['*']) {
            return [
                'code' => 'smtp_mail_template',
                'parent_code' => 'smtp',
                'sort' => 20,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('配置邮件模板'),
                'tip' => (string)__('无法检测邮件模板表，请打开渠道管理确认模板已种子化。'),
                'status' => 'doing',
                'href' => $href,
                'meta' => ['missing_channels' => []],
            ];
        }

        if ($missing !== []) {
            $sample = implode(', ', array_slice($missing, 0, 5));
            $more = count($missing) > 5 ? '…' : '';

            return [
                'code' => 'smtp_mail_template',
                'parent_code' => 'smtp',
                'sort' => 20,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('配置邮件模板'),
                'tip' => (string)__('尚有 %{1} 个发信渠道无模板：%{2}%{3}', [
                    (string)count($missing),
                    $sample,
                    $more,
                ]),
                'status' => 'todo',
                'href' => $href,
                'meta' => ['missing_channels' => $missing],
            ];
        }

        return [
            'code' => 'smtp_mail_template',
            'parent_code' => 'smtp',
            'sort' => 20,
            'category' => (string)__('通信'),
            'module' => 'Weline_Smtp',
            'title' => (string)__('配置邮件模板'),
            'tip' => (string)__('各发信渠道已有可解析邮件模板。'),
            'status' => 'done',
            'href' => $href,
            'meta' => ['missing_channels' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localeTask(string $scope, int $websiteId): array
    {
        $href = $this->backendPath('smtp/backend/template/listing');
        try {
            $report = $this->coverage()->localeGaps($scope, $websiteId);
        } catch (\Throwable) {
            return [
                'code' => 'smtp_mail_template_i18n',
                'parent_code' => 'smtp',
                'sort' => 30,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('多语言邮件模板'),
                'tip' => (string)__('无法检测多语言模板覆盖，请打开渠道管理核对各语言行。'),
                'status' => 'doing',
                'href' => $href,
                'meta' => ['missing_pairs' => [], 'locales' => []],
            ];
        }

        $missing = $report['missing_pairs'];
        $locales = $report['locales'];
        if ($missing !== []) {
            $n = count($missing);
            $sample = $missing[0]['channel'] . '@' . $missing[0]['locale'];

            return [
                'code' => 'smtp_mail_template_i18n',
                'parent_code' => 'smtp',
                'sort' => 30,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('多语言邮件模板'),
                'tip' => (string)__('站点 %{1} 个语种中，尚缺 %{2} 组渠道×语言模板（例：%{3}）。', [
                    (string)count($locales),
                    (string)$n,
                    $sample,
                ]),
                'status' => 'todo',
                'href' => $href,
                'meta' => [
                    'missing_pairs' => $missing,
                    'locales' => $locales,
                ],
            ];
        }

        return [
            'code' => 'smtp_mail_template_i18n',
            'parent_code' => 'smtp',
            'sort' => 30,
            'category' => (string)__('通信'),
            'module' => 'Weline_Smtp',
            'title' => (string)__('多语言邮件模板'),
            'tip' => (string)__('站点已开通语种的邮件模板均已覆盖。'),
            'status' => 'done',
            'href' => $href,
            'meta' => [
                'missing_pairs' => [],
                'locales' => $locales,
            ],
        ];
    }
}
