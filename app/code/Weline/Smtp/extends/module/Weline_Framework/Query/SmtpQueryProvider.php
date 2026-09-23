<?php

declare(strict_types=1);

namespace Weline\Smtp\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\Smtp\Model\SmtpSendLog;
use Weline\Smtp\Service\MailBrandContextService;
use Weline\Smtp\Service\MailChannelCollector;
use Weline\Smtp\Service\MailTemplateRenderer;
use Weline\Smtp\Service\MailTemplateResolver;
use Weline\Smtp\Service\MailTemplateSendContext;
use Weline\Smtp\Service\MailTemplateShellComposer;

/**
 * SMTP 统一查询器
 *
 * 提供 send/test/getConfig 能力，供其他模块通过 w_query('smtp', ...) 调用。
 * 支持多模块 SMTP 配置：module 参数指定使用哪一模块的配置。
 */
class SmtpQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'smtp';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'send' => $this->send($params),
            'test' => $this->test($params),
            'getConfig' => $this->getConfig($params),
            'isAvailable' => $this->isAvailable($params),
            'getSenders' => $this->getSenders($params),
            'getSenderByCode' => $this->getSenderByCode($params),
            'setContact' => $this->setContact($params),
            'getContact' => $this->getContact($params),
            default => throw new \InvalidArgumentException(
                (string)__('Smtp 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    /**
     * 检测 SMTP 是否已配置可用（至少有一个发件人配置了 host + username）
     */
    private function isAvailable(array $params): array
    {
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        $scope = $this->resolveScopeParam($params);
        $senders = $this->getSendersInternal($module, $scope);
        foreach ($senders as $s) {
            if ((string)($s['source_type'] ?? 'external') === 'mail_account') {
                $mailConfig = $this->loadMailAccountConfig((int)($s['mail_account_id'] ?? 0));
                if ($mailConfig !== null) {
                    return ['available' => true, 'message' => __('SMTP 已配置')];
                }
                continue;
            }
            $host = trim((string) ($s['smtp_host'] ?? ''));
            $user = trim((string) ($s['smtp_username'] ?? ''));
            if ($host !== '' && $user !== '') {
                return ['available' => true, 'message' => __('SMTP 已配置')];
            }
        }
        return ['available' => false, 'message' => __('请先在 SMTP 配置中添加至少一个发件人')];
    }

    private function getSenders(array $params): array
    {
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        return $this->getSendersInternal($module, $this->resolveScopeParam($params));
    }

    private function getSendersInternal(string $module, ?string $scope = null): array
    {
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        return $data->getSenders($module, $scope);
    }

    private function resolveScopeParam(array $params): ?string
    {
        $scope = trim((string)($params['scope'] ?? $params['target_scope'] ?? ''));
        if ($scope !== '') {
            return $scope;
        }
        $websiteCode = strtolower(trim((string)($params['website_code'] ?? '')));
        if ($websiteCode !== '' && $websiteCode !== 'default') {
            return $websiteCode . '.default.default';
        }

        return null;
    }

    private function getSenderByCode(array $params): ?array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return null;
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        return $data->getSenderByCode($code, $module, $this->resolveScopeParam($params));
    }

    /**
     * 设置某发件人 code 的默认联系人（收件邮箱），供消息通知等调用
     */
    private function setContact(array $params): array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $toEmail = trim((string) ($params['to_email'] ?? $params['email'] ?? ''));
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return ['success' => false, 'message' => __('发件人 code 不能为空')];
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $data->setSenderContact($code, $toEmail, $module, $this->resolveScopeParam($params));
        return ['success' => true, 'message' => __('已保存')];
    }

    /**
     * 获取某发件人 code 的默认联系人（收件邮箱）
     */
    private function getContact(array $params): array
    {
        $code = (string) ($params['sender_code'] ?? $params['code'] ?? '');
        $module = (string) ($params['module'] ?? 'Weline_Smtp');
        if ($code === '') {
            return ['success' => false, 'to_email' => ''];
        }
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $toEmail = $data->getSenderContact($code, $module, $this->resolveScopeParam($params));
        return ['success' => true, 'to_email' => $toEmail];
    }

    private function send(array $params): array
    {
        $to = $params['to'] ?? null;
        $subject = trim((string)($params['subject'] ?? ''));
        $content = (string)($params['content'] ?? '');
        $from = $params['from'] ?? null;
        $module = (string)($params['module'] ?? 'Weline_Smtp');
        $channel = trim((string)($params['channel'] ?? $params['mail_channel'] ?? ''));
        $senderCode = $params['sender_code'] ?? $params['code'] ?? null;
        $alt = (string)($params['alt'] ?? '');
        $attachment = $params['attachment'] ?? '';
        $cc = $params['cc'] ?? '';
        $bcc = $params['bcc'] ?? '';
        $templateId = 0;
        $resolvedLocale = '';
        $useTemplate = !array_key_exists('use_template', $params) || filter_var($params['use_template'], FILTER_VALIDATE_BOOLEAN);

        if (empty($to)) {
            return ['success' => false, 'message' => __('收件人不能为空')];
        }

        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        /** @var SmtpSender $sender */
        $sender = ObjectManager::getInstance(SmtpSender::class);

        if ($channel !== '' && $useTemplate) {
            /** @var MailTemplateSendContext $ctxService */
            $ctxService = ObjectManager::getInstance(MailTemplateSendContext::class);
            $ctx = $ctxService->resolve($params);
            if (!$ctx['ok']) {
                return ['success' => false, 'message' => $ctx['message']];
            }
            /** @var MailTemplateResolver $resolver */
            $resolver = ObjectManager::getInstance(MailTemplateResolver::class);
            $hit = $resolver->resolve($channel, $ctx['storage_scope'], $ctx['locale'], $ctx['website_default']);
            if ($hit === null) {
                return ['success' => false, 'message' => __('发信渠道 %{1} 未找到可用邮件模板', [$channel])];
            }
            /** @var SmtpMailTemplate $tpl */
            $tpl = $hit['template'];
            $templateId = (int)$tpl->getId();
            $resolvedLocale = (string)$hit['locale'];
            $storageScope = (string)$hit['storage_scope'];

            $allowed = [];
            /** @var MailChannelCollector $collector */
            $collector = ObjectManager::getInstance(MailChannelCollector::class);
            $meta = $collector->getByCode($channel) ?? [];
            foreach ($meta['variables'] ?? [] as $var) {
                $code = trim((string)($var['code'] ?? ''));
                if ($code !== '') {
                    $allowed[] = $code;
                }
            }
            $vars = is_array($params['vars'] ?? null) ? $params['vars'] : [];
            /** @var MailBrandContextService $brandContext */
            $brandContext = ObjectManager::getInstance(MailBrandContextService::class);
            $vars = $brandContext->mergeInto($vars, $storageScope, $resolvedLocale !== '' ? $resolvedLocale : $ctx['locale']);
            $allowed = array_values(array_unique(array_merge(
                $allowed,
                MailBrandContextService::variableCodes()
            )));
            /** @var MailTemplateRenderer $renderer */
            $renderer = ObjectManager::getInstance(MailTemplateRenderer::class);
            $subjectTpl = (string)$tpl->getData(SmtpMailTemplate::schema_fields_SUBJECT);
            $bodyTpl = (string)$tpl->getData(SmtpMailTemplate::schema_fields_BODY_HTML);
            $altTpl = (string)$tpl->getData(SmtpMailTemplate::schema_fields_BODY_TEXT);
            /** @var MailTemplateShellComposer $shellComposer */
            $shellComposer = ObjectManager::getInstance(MailTemplateShellComposer::class);
            $bodyTpl = $shellComposer->extractBodyFragment($bodyTpl);
            $subject = $renderer->render($subjectTpl, $vars, $allowed);
            $content = $renderer->render($bodyTpl, $vars, $allowed);
            $overrideSubject = trim((string)($params['override_subject'] ?? ''));
            $overrideContent = (string)($params['override_content'] ?? '');
            if ($overrideSubject !== '') {
                $subject = $overrideSubject;
            }
            if ($overrideContent !== '') {
                $content = $overrideContent;
            }
            // 架构契约：Subject 先补品牌，再作为壳 preheader（收件箱与页头一致）
            $subject = $brandContext->ensureBrandedSubject($subject, $vars);
            $content = $shellComposer->wrap($content, $resolvedLocale !== '' ? $resolvedLocale : $ctx['locale'], [
                'preheader' => $subject,
                'storage_scope' => $storageScope,
            ]);
            // 壳内还有 {{var.site_*}} 等品牌变量，再渲染一次
            $content = $renderer->render($content, $vars, $allowed);
            if ($altTpl !== '') {
                $alt = $renderer->render($altTpl, $vars, $allowed);
            } elseif ($alt === '') {
                $alt = $renderer->htmlToText($content);
            }
            $params['scope'] = $storageScope;
            $params['locale'] = $resolvedLocale;
        }

        if ($subject === '') {
            return ['success' => false, 'message' => __('邮件主题不能为空')];
        }

        $scope = $this->resolveScopeParam($params);
        $storageScope = $data->resolveScope($scope);

        /** @var MailBrandContextService $brandContext */
        $brandContext = ObjectManager::getInstance(MailBrandContextService::class);
        $brandVars = $brandContext->mergeInto(
            is_array($params['vars'] ?? null) ? $params['vars'] : [],
            $storageScope,
            $resolvedLocale !== '' ? $resolvedLocale : trim((string)($params['locale'] ?? ''))
        );
        // 架构契约：所有渠道 Subject 必须可见品牌（模板已含则不重复前缀）
        $subject = $brandContext->ensureBrandedSubject($subject, $brandVars);

        if ($channel !== '') {
            $bound = $data->resolveTransportIdForChannel($channel, $module, $scope);
            if ($bound === '' && $module !== 'Weline_Smtp') {
                // 传输账户绑定统一维护在 Weline_Smtp；业务模块仅声明 channel。
                $bound = $data->resolveTransportIdForChannel($channel, 'Weline_Smtp', $scope);
            }
            if ($bound === '') {
                return ['success' => false, 'message' => __('发信渠道 %{1} 未绑定传输账户', [$channel])];
            }
            $senderCode = $bound;
        }

        $templateMeta = [
            '__template_id' => $templateId,
            '__locale' => $resolvedLocale,
        ];

        if ($senderCode !== null && $senderCode !== '') {
            $senderConfig = $data->getSenderByCode((string) $senderCode, $module, $scope);
            if (!$senderConfig && $module !== 'Weline_Smtp') {
                $senderConfig = $data->getSenderByCode((string) $senderCode, 'Weline_Smtp', $scope);
            }
            if (!$senderConfig) {
                return ['success' => false, 'message' => __('发件人 %{1} 未配置或配置不完整', [$senderCode])];
            }
            if ((string)($senderConfig['source_type'] ?? 'external') === 'mail_account') {
                return $this->sendWithMailAccountSender(
                    array_merge($senderConfig, $templateMeta),
                    $from,
                    $to,
                    $subject,
                    $content,
                    $alt,
                    $attachment,
                    $cc,
                    $bcc,
                    $module,
                    $channel,
                    (string)$senderCode,
                    $storageScope
                );
            }
            if (empty($senderConfig['smtp_host']) || empty($senderConfig['smtp_username'])) {
                return ['success' => false, 'message' => __('发件人 %{1} 未配置或配置不完整', [$senderCode])];
            }
            $username = trim((string)($senderConfig['smtp_username'] ?? ''));
            $fromResolved = $from;
            if (empty($fromResolved)) {
                $fromResolved = [
                    'email' => $username,
                    'name' => $this->resolveFromDisplayName(
                        (string)($senderConfig['name'] ?? ''),
                        (string)$senderCode,
                        $username,
                        $storageScope,
                        $resolvedLocale
                    ),
                ];
            } elseif (is_string($fromResolved)) {
                $fromResolved = [
                    'email' => $fromResolved,
                    'name' => $this->resolveFromDisplayName(
                        '',
                        (string)$senderCode,
                        $fromResolved,
                        $storageScope,
                        $resolvedLocale
                    ),
                ];
            } elseif (is_array($fromResolved)) {
                $fromResolved['name'] = $this->resolveFromDisplayName(
                    (string)($fromResolved['name'] ?? ''),
                    (string)$senderCode,
                    (string)($fromResolved['email'] ?? $username),
                    $storageScope,
                    $resolvedLocale
                );
            }
            try {
                $senderConfig['__channel'] = $channel;
                $senderConfig['__sender_code'] = (string)$senderCode;
                $senderConfig['__storage_scope'] = $storageScope;
                $senderConfig = array_merge($senderConfig, $templateMeta);
                $ok = $sender->sendWithConfig(
                    $fromResolved,
                    $to,
                    $subject,
                    $content,
                    $alt,
                    $attachment,
                    '',
                    $cc,
                    $bcc,
                    $senderConfig,
                    $module
                );
                return ['success' => $ok, 'message' => $ok ? __('发送成功') : __('发送失败')];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => __('发送失败：%{1}', [$e->getMessage()])];
            }
        }

        $username = $data->get(Data::smtp_username, $module, $scope);
        if (empty($username)) {
            return ['success' => false, 'message' => __('模块 %{1} 未配置 SMTP，请先在后台配置', [$module])];
        }
        $fromResolved = $from;
        if (empty($fromResolved)) {
            $fromResolved = [
                'email' => $username,
                'name' => $this->resolveFromDisplayName(
                    '',
                    is_scalar($senderCode) ? (string)$senderCode : '',
                    (string)$username,
                    $storageScope,
                    $resolvedLocale
                ),
            ];
        } elseif (is_string($fromResolved)) {
            $fromResolved = [
                'email' => $fromResolved,
                'name' => $this->resolveFromDisplayName(
                    '',
                    is_scalar($senderCode) ? (string)$senderCode : '',
                    $fromResolved,
                    $storageScope,
                    $resolvedLocale
                ),
            ];
        } elseif (is_array($fromResolved)) {
            $fromResolved['name'] = $this->resolveFromDisplayName(
                (string)($fromResolved['name'] ?? ''),
                is_scalar($senderCode) ? (string)$senderCode : '',
                (string)($fromResolved['email'] ?? $username),
                $storageScope,
                $resolvedLocale
            );
        }
        $legacyConfig = [
            'smtp_host' => (string)$data->get(Data::smtp_host, $module, $scope),
            'smtp_port' => (string)$data->get(Data::smtp_port, $module, $scope),
            'smtp_username' => (string)$username,
            'smtp_password' => (string)$data->get(Data::smtp_password, $module, $scope),
            'smtp_secure' => (string)$data->get(Data::smtp_secure, $module, $scope),
            'smtp_auth' => (string)$data->get(Data::smtp_auth, $module, $scope),
            '__channel' => $channel,
            '__sender_code' => is_scalar($senderCode) ? (string)$senderCode : '',
            '__storage_scope' => $storageScope,
            '__template_id' => $templateId,
            '__locale' => $resolvedLocale,
        ];
        try {
            $ok = $sender->sendWithConfig(
                $fromResolved,
                $to,
                $subject,
                $content,
                $alt,
                $attachment,
                '',
                $cc,
                $bcc,
                $legacyConfig,
                $module
            );
            return [
                'success' => $ok,
                'message' => $ok ? __('发送成功') : __('发送失败'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('发送失败：%{1}', [$e->getMessage()]),
            ];
        }
    }

    private function sendWithMailAccountSender(
        array $senderConfig,
        mixed $from,
        mixed $to,
        string $subject,
        string $content,
        string $alt,
        mixed $attachment,
        mixed $cc,
        mixed $bcc,
        string $module,
        string $channel = '',
        string $senderCode = '',
        string $storageScope = ''
    ): array {
        $mailAccountId = (int)($senderConfig['mail_account_id'] ?? 0);
        $mailConfig = $this->loadMailAccountConfig($mailAccountId);
        if ($mailConfig === null) {
            return ['success' => false, 'message' => __('自建邮箱账号不存在或未启用')];
        }

        $mailEmail = trim((string)($mailConfig['email'] ?? ''));
        $fromResolved = $from;
        $configuredName = trim((string)($senderConfig['name'] ?? $mailConfig['display_name'] ?? ''));
        if (empty($fromResolved)) {
            $fromResolved = [
                'email' => $mailEmail,
                'name' => $this->resolveFromDisplayName(
                    $configuredName,
                    $senderCode,
                    $mailEmail,
                    $storageScope,
                    (string)($senderConfig['__locale'] ?? '')
                ),
            ];
        } elseif (is_string($fromResolved)) {
            $fromResolved = [
                'email' => $fromResolved,
                'name' => $this->resolveFromDisplayName(
                    $configuredName,
                    $senderCode,
                    $fromResolved,
                    $storageScope,
                    (string)($senderConfig['__locale'] ?? '')
                ),
            ];
        } elseif (is_array($fromResolved)) {
            $fromResolved['name'] = $this->resolveFromDisplayName(
                trim((string)($fromResolved['name'] ?? '')) !== ''
                    ? (string)$fromResolved['name']
                    : $configuredName,
                $senderCode,
                (string)($fromResolved['email'] ?? $mailEmail),
                $storageScope,
                (string)($senderConfig['__locale'] ?? '')
            );
        }

        if (!empty($mailConfig['is_fake'])) {
            try {
                $result = w_query('mail', 'sendViaSmtpAccount', [
                    'account_id' => $mailAccountId,
                    'to' => $to,
                    'subject' => $subject,
                    'content' => $content,
                ]);
                if (is_array($result) && !empty($result['success'])) {
                    $this->writeVirtualSendLog(
                        $fromResolved,
                        $to,
                        $subject,
                        $content,
                        $alt,
                        $attachment,
                        $cc,
                        $bcc,
                        $mailEmail,
                        $module,
                        $channel,
                        $senderCode !== '' ? $senderCode : (string)($senderConfig['code'] ?? ''),
                        $storageScope,
                        (int)($senderConfig['__template_id'] ?? 0),
                        (string)($senderConfig['__locale'] ?? '')
                    );
                    return ['success' => true, 'message' => __('发送成功')];
                }

                return ['success' => false, 'message' => (string)($result['message'] ?? __('发送失败'))];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => __('发送失败：%{1}', [$e->getMessage()])];
            }
        }

        $transportConfig = array_merge($senderConfig, [
            'smtp_host' => (string)($mailConfig['smtp_host'] ?? $senderConfig['smtp_host'] ?? ''),
            'smtp_port' => (string)($mailConfig['smtp_port'] ?? $senderConfig['smtp_port'] ?? '587'),
            'smtp_secure' => (string)($mailConfig['smtp_secure'] ?? $senderConfig['smtp_secure'] ?? 'tls'),
            'smtp_auth' => (string)($mailConfig['smtp_auth'] ?? $senderConfig['smtp_auth'] ?? '1'),
            'smtp_username' => $mailEmail,
            'smtp_password' => (string)($senderConfig['smtp_password'] ?? ''),
            '__channel' => $channel,
            '__sender_code' => $senderCode !== '' ? $senderCode : (string)($senderConfig['code'] ?? ''),
            '__storage_scope' => $storageScope,
            '__template_id' => (int)($senderConfig['__template_id'] ?? 0),
            '__locale' => (string)($senderConfig['__locale'] ?? ''),
        ]);

        /** @var SmtpSender $sender */
        $sender = ObjectManager::getInstance(SmtpSender::class);
        try {
            $ok = $sender->sendWithConfig(
                $fromResolved,
                $to,
                $subject,
                $content,
                $alt,
                $attachment,
                '',
                $cc,
                $bcc,
                $transportConfig,
                $module
            );
            return ['success' => $ok, 'message' => $ok ? __('发送成功') : __('发送失败')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('发送失败：%{1}', [$e->getMessage()])];
        }
    }

    private function loadMailAccountConfig(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        try {
            $result = w_query('mail', 'getSmtpAccountConfig', ['account_id' => $accountId]);
            if (is_array($result) && !empty($result['success']) && is_array($result['config'] ?? null)) {
                return $result['config'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function writeVirtualSendLog(
        string|array $from,
        string|array $to,
        string $subject,
        string $content,
        string $alt,
        string|array $attachment,
        string|array $cc,
        string|array $bcc,
        string $proxy,
        string $module,
        string $channel = '',
        string $senderCode = '',
        string $storageScope = '',
        int $templateId = 0,
        string $locale = ''
    ): void {
        /** @var SmtpSendLog $sendLog */
        $sendLog = ObjectManager::getInstance(SmtpSendLog::class);
        $fromInfo = $this->normalizeSingleEmailEntry($from);
        try {
            $sendLog->clear()
                ->setData(SmtpSendLog::schema_fields_FROM_EMAIL, $fromInfo['email'])
                ->setData(SmtpSendLog::schema_fields_SENDER_NAME, substr($fromInfo['name'], 0, 30))
                ->setData(SmtpSendLog::schema_fields_TO_EMAIL, json_encode($this->normalizeEmailEntries($to), JSON_UNESCAPED_UNICODE))
                ->setData(SmtpSendLog::schema_fields_REPLY_TO, json_encode([], JSON_UNESCAPED_UNICODE))
                ->setData(SmtpSendLog::schema_fields_SUBJECT, $subject)
                ->setData(SmtpSendLog::schema_fields_CONTENT, $content)
                ->setData(SmtpSendLog::schema_fields_ALT, $alt)
                ->setData(SmtpSendLog::schema_fields_ATTACHMENT, json_encode($attachment === '' ? [] : $attachment, JSON_UNESCAPED_UNICODE))
                ->setData(SmtpSendLog::schema_fields_CC, json_encode($this->normalizeEmailEntries($cc), JSON_UNESCAPED_UNICODE))
                ->setData(SmtpSendLog::schema_fields_BCC, json_encode($this->normalizeEmailEntries($bcc), JSON_UNESCAPED_UNICODE))
                ->setData(SmtpSendLog::schema_fields_IS_HTML, 1)
                ->setData(SmtpSendLog::schema_fields_PROXY, $proxy)
                ->setData(SmtpSendLog::schema_fields_MODULE, $module)
                ->setData(SmtpSendLog::schema_fields_CHANNEL, $channel)
                ->setData(SmtpSendLog::schema_fields_SENDER_CODE, $senderCode)
                ->setData(SmtpSendLog::schema_fields_STORAGE_SCOPE, $storageScope);
            if (defined(SmtpSendLog::class . '::schema_fields_TEMPLATE_ID')) {
                $sendLog->setData(SmtpSendLog::schema_fields_TEMPLATE_ID, $templateId);
            }
            if (defined(SmtpSendLog::class . '::schema_fields_LOCALE')) {
                $sendLog->setData(SmtpSendLog::schema_fields_LOCALE, $locale);
            }
            $sendLog->save();
        } catch (\Throwable) {
        }
    }

    /**
     * 发件人显示名：委托 MailBrandContextService（全渠道架构契约）。
     */
    private function resolveFromDisplayName(
        string $configuredName,
        string $senderCode,
        string $emailOrUsername,
        string $storageScope,
        string $locale = ''
    ): string {
        /** @var MailBrandContextService $brand */
        $brand = ObjectManager::getInstance(MailBrandContextService::class);

        return $brand->resolveFromDisplayName(
            $configuredName,
            $senderCode,
            $emailOrUsername,
            $storageScope,
            $locale
        );
    }

    private function normalizeSingleEmailEntry(string|array $entry): array
    {
        if (is_string($entry)) {
            return ['email' => trim($entry), 'name' => ''];
        }

        return [
            'email' => trim((string)($entry['email'] ?? '')),
            'name' => trim((string)($entry['name'] ?? '')),
        ];
    }

    private function normalizeEmailEntries(string|array $entries): array
    {
        if ($entries === '' || $entries === []) {
            return [];
        }

        if (is_string($entries) || isset($entries['email'])) {
            return [$this->normalizeSingleEmailEntry($entries)];
        }

        $normalized = [];
        foreach ($entries as $entry) {
            if (is_string($entry) || is_array($entry)) {
                $item = $this->normalizeSingleEmailEntry($entry);
                if ($item['email'] !== '') {
                    $normalized[] = $item;
                }
            }
        }

        return $normalized;
    }

    private function test(array $params): array
    {
        $to = $params['to'] ?? null;
        $module = (string)($params['module'] ?? 'Weline_Smtp');

        if (empty($to)) {
            return ['success' => false, 'message' => __('测试邮箱不能为空')];
        }

        return $this->send([
            'from' => null,
            'to' => $to,
            'subject' => __('[SMTP 测试] WelineFramework 邮件测试'),
            'content' => __('这是一封测试邮件。如果您收到此邮件，说明 SMTP 配置正确。'),
            'module' => $module,
        ]);
    }

    private function getConfig(array $params): array
    {
        $module = (string)($params['module'] ?? 'Weline_Smtp');
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $all = $data->get('', $module, $this->resolveScopeParam($params));
        return is_array($all) ? $all : [];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'smtp',
            'name' => __('SMTP 邮件'),
            'description' => __('提供 SMTP 邮件发送能力，支持多模块独立配置'),
            'module' => 'Weline_Smtp',
            'operations' => [
                [
                    'name' => 'send',
                    'description' => __('发送邮件'),
                    'params' => [
                        ['name' => 'to', 'type' => 'string|array', 'required' => true, 'description' => __('收件人')],
                        ['name' => 'subject', 'type' => 'string', 'required' => false, 'description' => __('主题（无 channel 模板时必填）')],
                        ['name' => 'content', 'type' => 'string', 'required' => false, 'description' => __('HTML 正文（无 channel 模板时建议填写）')],
                        ['name' => 'channel', 'type' => 'string', 'required' => false, 'description' => __('发信渠道 code，有则走模板')],
                        ['name' => 'vars', 'type' => 'array', 'required' => false, 'description' => __('模板变量')],
                        ['name' => 'locale', 'type' => 'string', 'required' => false, 'description' => __('模板语言')],
                        ['name' => 'use_template', 'type' => 'bool', 'required' => false, 'description' => __('是否使用模板，默认 true')],
                        ['name' => 'override_subject', 'type' => 'string', 'required' => false, 'description' => __('覆盖模板主题')],
                        ['name' => 'override_content', 'type' => 'string', 'required' => false, 'description' => __('覆盖模板正文')],
                        ['name' => 'from', 'type' => 'string|array|null', 'required' => false, 'description' => __('发件人，空则用配置')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('使用哪一模块的 SMTP 配置')],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'description' => __('SystemConfig storage_scope，如 website.default.default')],
                        ['name' => 'website_code', 'type' => 'string', 'required' => false, 'description' => __('网站 code，可推导 website 级 scope')],
                        ['name' => 'sender_code', 'type' => 'string', 'required' => false, 'description' => __('传输账户 code（无 channel 时）')],
                        ['name' => 'alt', 'type' => 'string', 'required' => false],
                        ['name' => 'attachment', 'type' => 'string|array', 'required' => false],
                        ['name' => 'cc', 'type' => 'string|array', 'required' => false],
                        ['name' => 'bcc', 'type' => 'string|array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'test',
                    'description' => __('发送测试邮件'),
                    'params' => [
                        ['name' => 'to', 'type' => 'string', 'required' => true, 'description' => __('测试邮箱')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'getConfig',
                    'description' => __('获取模块 SMTP 配置'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'isAvailable',
                    'description' => __('检测 SMTP 是否已配置可用'),
                    'params' => [['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')]],
                ],
                [
                    'name' => 'getSenders',
                    'description' => __('获取所有发件人列表（含 code）'),
                    'params' => [['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')]],
                ],
                [
                    'name' => 'getSenderByCode',
                    'description' => __('按 code 获取发件人配置'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'setContact',
                    'description' => __('设置发件人默认联系人/收件邮箱，供消息通知等调用'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'to_email', 'type' => 'string', 'required' => true, 'description' => __('默认收件邮箱')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
                [
                    'name' => 'getContact',
                    'description' => __('获取发件人默认联系人/收件邮箱'),
                    'params' => [
                        ['name' => 'sender_code', 'type' => 'string', 'required' => true, 'description' => __('发件人 code')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块')],
                    ],
                ],
            ],
        ];
    }
}
