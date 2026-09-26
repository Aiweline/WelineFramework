<?php

declare(strict_types=1);

namespace Weline\Smtp\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Service\MailAccountTransportProvisioner;
use Weline\Smtp\Service\MailTemplateSetupCoverage;
use Weline\Websites\Model\Website;

/**
 * Smtp 建站任务：发信路径、传输确认、渠道绑定、模板与多语言（自声明 + 自检）。
 * 自建邮局传输/绑定由 Smtp 自管（MailAccountTransportProvisioner）；助手只检测并深链。
 */
class SmtpSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function __construct(
        private readonly Data $data,
        private readonly ?MailTemplateSetupCoverage $coverage = null,
        private readonly ?MailAccountTransportProvisioner $provisioner = null,
    ) {
    }

    private function coverage(): MailTemplateSetupCoverage
    {
        return $this->coverage ?? ObjectManager::getInstance(MailTemplateSetupCoverage::class);
    }

    private function provisioner(): MailAccountTransportProvisioner
    {
        return $this->provisioner ?? ObjectManager::getInstance(MailAccountTransportProvisioner::class);
    }

    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $websiteId = (int)($context['website_id'] ?? Website::ID_DEFAULT);
        if ($websiteId < 0) {
            $websiteId = Website::ID_DEFAULT;
        }

        return $this->tasks([
            $this->sendPathTask($scope),
            $this->transportTask($scope),
            $this->bindingsTask($scope),
            $this->templateTask($scope),
            $this->localeTask($scope, $websiteId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendPathTask(string $scope): array
    {
        $href = $this->backendPath('smtp/backend/config');
        $pathInfo = $this->provisioner()->detectSendPath($scope);
        $path = (string)($pathInfo['path'] ?? 'none');
        $hasMailAccounts = $this->hasUsableMailAccounts();

        if ($path === 'none') {
            $tip = $hasMailAccounts
                ? (string)__('请选择发信方式：在 Smtp配置 使用「外部 SMTP」，或点「用自建邮局一键配置」由 Smtp 自动挂接邮局账号并绑定渠道。')
                : (string)__('请选择发信方式：在 Smtp配置 添加「外部 SMTP」；若要用本站域名邮箱，请先完成企业邮箱（引擎/域名/账号）再建传输。');

            return [
                'code' => 'smtp_send_path',
                'parent_code' => 'smtp',
                'sort' => 5,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('选择发信方式'),
                'tip' => $tip,
                'status' => 'todo',
                'href' => $hasMailAccounts
                    ? $this->backendPath('smtp/backend/config?ensure_mail=1')
                    : $href,
                'meta' => [
                    'path' => $path,
                    'has_mail_accounts' => $hasMailAccounts,
                ],
            ];
        }

        if ($path === 'mail_account') {
            return [
                'code' => 'smtp_send_path',
                'parent_code' => 'smtp',
                'sort' => 5,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('选择发信方式'),
                'tip' => (string)__('当前为自建邮局路径（mail_account）。传输与渠道绑定由 Smtp 自管，请完成测试确认。'),
                'status' => 'done',
                'href' => $href,
                'meta' => ['path' => $path, 'has_mail_accounts' => $hasMailAccounts],
            ];
        }

        if ($path === 'external') {
            return [
                'code' => 'smtp_send_path',
                'parent_code' => 'smtp',
                'sort' => 5,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('选择发信方式'),
                'tip' => $hasMailAccounts
                    ? (string)__('当前为外部 SMTP。若要改走本站邮局，可打开 Smtp配置 点「用自建邮局一键配置」。')
                    : (string)__('当前为外部 SMTP 路径。'),
                'status' => 'done',
                'href' => $href,
                'meta' => ['path' => $path, 'has_mail_accounts' => $hasMailAccounts],
            ];
        }

        return [
            'code' => 'smtp_send_path',
            'parent_code' => 'smtp',
            'sort' => 5,
            'category' => (string)__('通信'),
            'module' => 'Weline_Smtp',
            'title' => (string)__('选择发信方式'),
            'tip' => (string)__('同时存在外部 SMTP 与自建邮局传输。建议统一路径后，再确认渠道绑定与测试发信。'),
            'status' => 'doing',
            'href' => $href,
            'meta' => ['path' => $path, 'has_mail_accounts' => $hasMailAccounts],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transportTask(string $scope): array
    {
        $provenance = $this->data->resolveSendersProvenance('Weline_Smtp', $scope);
        $confirmed = $this->data->isSetupConfirmed('Weline_Smtp', $scope);
        $pathInfo = $this->provisioner()->detectSendPath($scope);
        $path = (string)($pathInfo['path'] ?? 'none');
        $href = $this->backendPath('smtp/backend/config');

        if (empty($provenance['found'])) {
            return [
                'code' => 'smtp_transport',
                'parent_code' => 'smtp',
                'sort' => 10,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('配置 SMTP 发信'),
                'tip' => (string)__('尚未配置 SMTP 传输账户。外部 SMTP 请手工添加；自建邮局请用「一键配置」由 Smtp 自动写入。'),
                'status' => 'todo',
                'href' => $href,
                'meta' => [
                    'source_scope' => '',
                    'inherited' => false,
                    'confirmed' => false,
                    'path' => $path,
                ],
            ];
        }

        if ($confirmed) {
            $tip = !empty($provenance['inherited'])
                ? (string)__('已确认可用（当前继承自 %{1}，路径：%{2}）。', [
                    (string)$provenance['source_scope'],
                    $this->pathLabel($path),
                ])
                : (string)__('已确认可用（当前范围自有配置，路径：%{1}）。', [$this->pathLabel($path)]);

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
                    'path' => $path,
                ],
            ];
        }

        $tip = match ($path) {
            'mail_account' => (string)__('自建邮局传输已就绪，请打开配置页点「测试」发送确认邮件（真实账号需先填 SMTP 密码）。'),
            'external' => !empty($provenance['inherited'])
                ? (string)__('已检测到继承自 %{1} 的外部 SMTP，请打开配置页发送测试邮件确认。', [(string)$provenance['source_scope']])
                : (string)__('已检测到外部 SMTP 配置，请发送测试邮件确认后再上线。'),
            default => !empty($provenance['inherited'])
                ? (string)__('已检测到继承自 %{1} 的 SMTP 配置，请打开配置页发送测试邮件确认。', [(string)$provenance['source_scope']])
                : (string)__('已检测到当前范围的 SMTP 配置，请发送测试邮件确认后再上线。'),
        };

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
                'path' => $path,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingsTask(string $scope): array
    {
        $href = $this->backendPath('smtp/backend/config');
        $missing = $this->provisioner()->unboundChannels($scope);
        $pathInfo = $this->provisioner()->detectSendPath($scope);
        $path = (string)($pathInfo['path'] ?? 'none');

        if ($path === 'none') {
            return [
                'code' => 'smtp_channel_bindings',
                'parent_code' => 'smtp',
                'sort' => 15,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('绑定发信渠道'),
                'tip' => (string)__('请先配置传输账户；自建邮局可用一键配置自动绑定全部已注册渠道。'),
                'status' => 'todo',
                'href' => $href,
                'meta' => ['missing_channels' => $missing, 'path' => $path],
            ];
        }

        if ($missing !== []) {
            $sample = implode(', ', array_slice($missing, 0, 5));
            $more = count($missing) > 5 ? '…' : '';
            $ensureHref = $path === 'mail_account' || $this->hasUsableMailAccounts()
                ? $this->backendPath('smtp/backend/config?ensure_mail=1')
                : $href;

            return [
                'code' => 'smtp_channel_bindings',
                'parent_code' => 'smtp',
                'sort' => 15,
                'category' => (string)__('通信'),
                'module' => 'Weline_Smtp',
                'title' => (string)__('绑定发信渠道'),
                'tip' => (string)__('尚有 %{1} 个发信渠道未绑定传输：%{2}%{3}', [
                    (string)count($missing),
                    $sample,
                    $more,
                ]),
                'status' => 'todo',
                'href' => $ensureHref,
                'meta' => ['missing_channels' => $missing, 'path' => $path],
            ];
        }

        return [
            'code' => 'smtp_channel_bindings',
            'parent_code' => 'smtp',
            'sort' => 15,
            'category' => (string)__('通信'),
            'module' => 'Weline_Smtp',
            'title' => (string)__('绑定发信渠道'),
            'tip' => (string)__('全部已注册发信渠道均已绑定传输账户。'),
            'status' => 'done',
            'href' => $href,
            'meta' => ['missing_channels' => [], 'path' => $path],
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

    private function pathLabel(string $path): string
    {
        return match ($path) {
            'mail_account' => (string)__('自建邮局'),
            'external' => (string)__('外部 SMTP'),
            'mixed' => (string)__('混合'),
            default => (string)__('未配置'),
        };
    }

    private function hasUsableMailAccounts(): bool
    {
        try {
            $result = w_query('mail', 'getSmtpAccounts', ['limit' => 5]);
            if (is_array($result) && !empty($result['success']) && is_array($result['items'] ?? null)) {
                return $result['items'] !== [];
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
