<?php

declare(strict_types=1);

namespace Weline\Smtp\Extends\Module\Weline_SiteSetupAssistant\SetupTaskStatus;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\SetupTaskStatusProviderInterface;
use Weline\Smtp\Helper\Data;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Websites\Model\Website;

/**
 * 建站助手：SMTP 有自有/继承配置时标记待确认；测试成功后标记完成。
 */
class SmtpSetupTaskStatusProvider implements SetupTaskStatusProviderInterface
{
    public function __construct(
        private readonly Data $data,
    ) {
    }

    public function resolveTaskStatus(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $provenance = $this->data->resolveSendersProvenance('Weline_Smtp', $scope);
        $confirmed = $this->data->isSetupConfirmed('Weline_Smtp', $scope);

        if (empty($provenance['found'])) {
            return [[
                'code' => 'smtp',
                'status' => 'todo',
                'tip' => (string)__('尚未配置 SMTP 传输账户。请添加发件账户并发送测试邮件确认。'),
                'meta' => [
                    'source_scope' => '',
                    'inherited' => false,
                    'confirmed' => false,
                ],
            ]];
        }

        if ($confirmed) {
            $tip = !empty($provenance['inherited'])
                ? (string)__('已确认可用（当前继承自 %{1}）。', [(string)$provenance['source_scope']])
                : (string)__('已确认可用（当前范围自有配置）。');
            return [[
                'code' => 'smtp',
                'status' => 'done',
                'tip' => $tip,
                'meta' => [
                    'source_scope' => (string)$provenance['source_scope'],
                    'inherited' => !empty($provenance['inherited']),
                    'confirmed' => true,
                ],
            ]];
        }

        $tip = !empty($provenance['inherited'])
            ? (string)__('已检测到继承自 %{1} 的 SMTP 配置，请打开配置页发送测试邮件确认。', [(string)$provenance['source_scope']])
            : (string)__('已检测到当前范围的 SMTP 配置，请发送测试邮件确认后再上线。');

        return [[
            'code' => 'smtp',
            'status' => 'doing',
            'tip' => $tip,
            'meta' => [
                'source_scope' => (string)$provenance['source_scope'],
                'inherited' => !empty($provenance['inherited']),
                'confirmed' => false,
            ],
        ]];
    }

    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     */
    private function resolveStorageScope(array $context): string
    {
        $explicit = trim((string)($context['storage_scope'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $code = trim((string)($context['website_code'] ?? ''));
        if ($code === '' || $code === 'default') {
            $websiteId = (int)($context['website_id'] ?? 0);
            if ($websiteId > 0) {
                try {
                    /** @var Website $website */
                    $website = ObjectManager::getInstance(Website::class);
                    $website->load($websiteId);
                    $code = trim((string)$website->getCode());
                } catch (\Throwable) {
                    $code = '';
                }
            }
        }
        if ($code === '' || $code === 'default') {
            return ConfigReader::SCOPE_GLOBAL;
        }

        return $code . '.default.default';
    }
}
