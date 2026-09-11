<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/1 21:09:58
 */

namespace Weline\Smtp\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;
use Weline\Smtp\Service\MailChannelCollector;
use Weline\Framework\App\Controller\BackendController;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

#[Acl('Weline_Smtp::system_smtp_config', 'SMTP 配置', 'mail', 'SMTP 邮件服务配置', 'Weline_Smtp::system_smtp')]
class Config extends BackendController
{
    /**
     * @var \Weline\Smtp\Helper\Data
     */
    private Data $data;

    function __construct(Data $data)
    {
        $this->data = $data;
    }

    #[Acl('Weline_Smtp::smtp_config_index', '配置页', 'settings', '查看 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function index(): string
    {
        $workScope = $this->resolveWorkScope(true);
        $storageScope = (string)$workScope['storage_scope'];
        $module = 'Weline_Smtp';

        $senders = $this->data->getSenders($module, $storageScope);
        $mailAccounts = $this->loadMailSmtpAccounts();
        $contacts = [];
        foreach ($senders as $s) {
            $code = $s['code'] ?? '';
            if ($code !== '') {
                $contacts[$code] = $this->data->getSenderContact($code, $module, $storageScope);
            }
        }
        $legacy = $this->data->get('', $module, $storageScope);
        /** @var MailChannelCollector $channelCollector */
        $channelCollector = ObjectManager::getInstance(MailChannelCollector::class);
        $this->assign('senders', $senders);
        $this->assign('mail_accounts', $mailAccounts);
        $this->assign('sender_contacts', $contacts);
        $this->assign('legacy', $legacy);
        $this->assign('mail_channels', $channelCollector->collect());
        $this->assign('channel_bindings', $this->data->getChannelBindings($module, $storageScope));
        $this->assignScopeVars($workScope);
        return $this->fetch('Weline_Smtp::Backend/Config');
    }

    /** @deprecated 兼容旧路由，重定向到 index */
    public function get(): string
    {
        return $this->index();
    }

    #[Acl('Weline_Smtp::smtp_config_save', '保存配置', 'save', '保存 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function post(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $storageScope = (string)$workScope['storage_scope'];
        $smtp_configs = array_intersect_key($this->request->getPost(), array_flip(Data::keys));
        $smtp_configs['smtp_secure'] = (string) ($smtp_configs['smtp_secure'] ?? $this->data->get(Data::smtp_secure, 'Weline_Smtp', $storageScope) ?: '1');
        $smtp_configs['smtp_auth'] = (string) ($smtp_configs['smtp_auth'] ?? $this->data->get(Data::smtp_auth, 'Weline_Smtp', $storageScope) ?: '1');
        $has_error = '';
        foreach ($smtp_configs as $key => $config) {
            try {
                $this->data->set($key, (string)$config, 'Weline_Smtp', $storageScope);
            } catch (Exception $e) {
                $has_error .= $e->getMessage();
            }
        }
        if (empty($has_error)) {
            $this->getMessageManager()->addSuccess(__('Smtp配置成功！为了保证Smtp邮件服务正常工作，请测试确认。'));
        } else {
            $this->getMessageManager()->addError($has_error);
        }
        $this->redirect($this->scopedConfigUrl($workScope));
    }

    #[Acl('Weline_Smtp::smtp_config_test', '测试发送', 'arrow-right', '测试 SMTP 发送', 'Weline_Smtp::system_smtp_config')]
    public function postTest(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $storageScope = (string)$workScope['storage_scope'];
        $test_email = $this->request->getPost('smtp_test_address');
        $sender_code = $this->request->getPost('sender_code', '');
        $module = 'Weline_Smtp';
        $wantsJson = $this->wantsJsonResponse();
        $success = false;
        $message = '';

        if ($sender_code !== '') {
            $result = w_query('smtp', 'send', [
                'sender_code' => $sender_code,
                'to' => $test_email,
                'subject' => __('[SMTP 测试] 发件人 %{1}', [$sender_code]),
                'content' => __('这是一封测试邮件。如果您收到此邮件，说明该发件人配置正确。'),
                'module' => $module,
                'scope' => $storageScope,
            ]);
            $success = !empty($result['success']);
            $message = (string)($result['message'] ?? ($success ? __('邮件发送成功！') : __('发送失败')));
            if ($success) {
                $this->data->markSetupConfirmed($module, $storageScope);
                $this->getMessageManager()->addSuccess(__('邮件发送成功！'));
            } else {
                $this->getMessageManager()->addError($message !== '' ? $message : __('发送失败'));
            }
        } else {
            try {
                $this->data->set('smtp_test_address', (string)$test_email, $module, $storageScope);
            } catch (Exception $e) {
                $this->getMessageManager()->addError($e->getMessage());
                if ($wantsJson) {
                    return $this->jsonError($e->getMessage());
                }
                $this->redirect($this->scopedConfigUrl($workScope));
                return '';
            }
            try {
                $smtpSender = ObjectManager::getInstance(SmtpSender::class);
                $legacyConfig = [
                    'smtp_host' => (string)$this->data->get(Data::smtp_host, $module, $storageScope),
                    'smtp_port' => (string)$this->data->get(Data::smtp_port, $module, $storageScope),
                    'smtp_username' => (string)$this->data->get(Data::smtp_username, $module, $storageScope),
                    'smtp_password' => (string)$this->data->get(Data::smtp_password, $module, $storageScope),
                    'smtp_secure' => (string)$this->data->get(Data::smtp_secure, $module, $storageScope),
                    'smtp_auth' => (string)$this->data->get(Data::smtp_auth, $module, $storageScope),
                ];
                $smtpSender->sendWithConfig(
                    ['email' => $legacyConfig['smtp_username'], 'name' => __('发送者')],
                    $test_email,
                    __('WelineFramework SMTP 测试'),
                    __('这是一封测试邮件。'),
                    '',
                    '',
                    '',
                    '',
                    '',
                    $legacyConfig,
                    $module
                );
                $success = true;
                $message = (string)__('邮件发送成功！');
                $this->data->markSetupConfirmed($module, $storageScope);
                $this->getMessageManager()->addSuccess($message);
            } catch (\Throwable $e) {
                $success = false;
                $message = $e->getMessage();
                $this->getMessageManager()->addError($message);
            }
        }

        if ($wantsJson) {
            return $success ? $this->jsonSuccess($message !== '' ? $message : (string)__('邮件发送成功！'))
                : $this->jsonError($message !== '' ? $message : (string)__('测试发送失败'));
        }
        $this->redirect($this->scopedConfigUrl($workScope));
        return '';
    }

    private function wantsJsonResponse(): bool
    {
        if ((int)$this->request->getParam('isAjax', 0) === 1
            || (int)$this->request->getPost('isAjax', 0) === 1
        ) {
            return true;
        }
        $accept = strtolower((string)$this->request->getHeader('Accept'));
        if ($accept === '' || $accept === 'null') {
            $accept = strtolower((string)($this->request->getServer('HTTP_ACCEPT') ?? ''));
        }
        $requestedWith = strtolower((string)$this->request->getServer('HTTP_X_REQUESTED_WITH'));
        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    private function normalizeSmtpSecureForPort(string $secure, string $port): string
    {
        $secure = strtolower(trim($secure));
        $port = trim($port);
        if ($port === '465' && in_array($secure, ['tls', 'starttls', '2'], true)) {
            return 'ssl';
        }
        if ($port === '587' && in_array($secure, ['ssl', 'smtps', '1', 'true', 'on', 'yes'], true)) {
            return 'tls';
        }
        if (in_array($secure, ['ssl', 'tls', 'none'], true)) {
            return $secure;
        }
        return $port === '587' ? 'tls' : 'ssl';
    }

    /** 保存多发件人配置（JSON）及联系人 */
    #[Acl('Weline_Smtp::smtp_config_save', '保存配置', 'save', '保存 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function saveSenders(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }
        $workScope = $this->resolveWorkScope(false);
        $storageScope = (string)$workScope['storage_scope'];
        $module = 'Weline_Smtp';
        $sendersJson = $this->getRequestPayloadValue('senders');
        if ($sendersJson === null || $sendersJson === '') {
            $sendersJson = $this->getRequestPayloadValue('smtp_senders_json');
        }
        $contactsJson = $this->getRequestPayloadValue('sender_contacts');
        if ($contactsJson === null || $contactsJson === '') {
            $contactsJson = $this->getRequestPayloadValue('smtp_sender_contacts_json');
        }
        if (($sendersJson === null || $sendersJson === '') && ($contactsJson === null || $contactsJson === '')) {
            return $this->jsonError(__('缺少发件人配置数据'));
        }
        if ($sendersJson !== null && $sendersJson !== '') {
            $senders = json_decode($sendersJson, true);
            if (is_array($senders)) {
                $existing = $this->data->getSenders($module, $storageScope);
                $existingByCode = [];
                foreach ($existing as $e) {
                    $c = $e['code'] ?? '';
                    if ($c !== '') {
                        $existingByCode[$c] = $e;
                    }
                }
                foreach ($senders as &$s) {
                    $code = trim((string)($s['code'] ?? ''));
                    if ($code === '') {
                        $code = Data::generateTransportId();
                    }
                    $s['code'] = $code;
                    if (!Data::isValidTransportId($code)) {
                        return $this->jsonError(__('传输账户 ID 格式无效'));
                    }
                    if ($code !== '' && (trim((string)($s['smtp_password'] ?? '')) === '') && isset($existingByCode[$code]['smtp_password'])) {
                        $s['smtp_password'] = $existingByCode[$code]['smtp_password'];
                    }
                    $sourceType = (string)($s['source_type'] ?? 'external');
                    $sourceType = in_array($sourceType, ['external', 'mail_account'], true) ? $sourceType : 'external';
                    $s['source_type'] = $sourceType;
                    $s['smtp_secure'] = $this->normalizeSmtpSecureForPort(
                        (string)($s['smtp_secure'] ?? 'ssl'),
                        (string)($s['smtp_port'] ?? '465')
                    );
                    if ($sourceType === 'mail_account') {
                        $mailAccountId = (int)($s['mail_account_id'] ?? 0);
                        if ($mailAccountId <= 0) {
                            return $this->jsonError(__('请选择自建邮箱账号'));
                        }
                        $mailConfig = $this->loadMailSmtpAccountConfig($mailAccountId);
                        if ($mailConfig === null) {
                            return $this->jsonError(__('自建邮箱账号不存在或未启用'));
                        }
                        $s['mail_account_id'] = (string)$mailAccountId;
                        $s['mail_account_email'] = (string)($mailConfig['email'] ?? '');
                        $s['mail_domain_id'] = (string)($mailConfig['domain_id'] ?? '');
                        $s['mail_domain_name'] = (string)($mailConfig['domain_name'] ?? '');
                        $s['mail_engine'] = (string)($mailConfig['engine'] ?? '');
                        $s['mail_is_fake'] = !empty($mailConfig['is_fake']) ? '1' : '0';
                        $s['smtp_host'] = (string)($mailConfig['smtp_host'] ?? '');
                        $s['smtp_port'] = (string)($mailConfig['smtp_port'] ?? '587');
                        $s['smtp_secure'] = (string)($mailConfig['smtp_secure'] ?? 'tls');
                        $s['smtp_auth'] = (string)($mailConfig['smtp_auth'] ?? '1');
                        $s['smtp_username'] = (string)($mailConfig['email'] ?? '');
                        if (trim((string)($s['name'] ?? '')) === '') {
                            $s['name'] = (string)($mailConfig['display_name'] ?? $mailConfig['email'] ?? $code);
                        }
                    }
                }
                unset($s);
                $this->data->setSenders($senders, $module, $storageScope);
            }
        }
        if ($contactsJson !== null && $contactsJson !== '') {
            $contacts = json_decode($contactsJson, true);
            if (is_array($contacts)) {
                foreach ($contacts as $code => $toEmail) {
                    if (is_string($code) && $code !== '') {
                        $this->data->setSenderContact($code, trim((string) $toEmail), $module, $storageScope);
                    }
                }
            }
        }
        $bindingsJson = $this->getRequestPayloadValue('channel_bindings');
        if ($bindingsJson === null || $bindingsJson === '') {
            $bindingsJson = $this->getRequestPayloadValue('smtp_channel_bindings_json');
        }
        if ($bindingsJson !== null && $bindingsJson !== '') {
            $bindings = is_array($bindingsJson) ? $bindingsJson : json_decode((string)$bindingsJson, true);
            if (is_array($bindings)) {
                $normalized = [];
                foreach ($bindings as $channel => $transport) {
                    $channel = trim((string)$channel);
                    $transport = trim((string)$transport);
                    if ($channel !== '' && $transport !== '') {
                        $normalized[$channel] = $transport;
                    }
                }
                $this->data->setChannelBindings($normalized, $module, $storageScope);
            }
        }
        return $this->jsonSuccess(__('保存成功'));
    }

    /**
     * @param bool $normalizeUrl 为 true 时若缺少显式范围则 302 规范化到含 target_scope 的 URL
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string}
     */
    private function resolveWorkScope(bool $normalizeUrl): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $get = $this->request->getGet();
        $post = $this->request->isPost() ? $this->request->getPost() : [];
        $input = [
            'target_scope' => (string)($post['target_scope'] ?? $get['target_scope'] ?? ''),
            'scope' => (string)($post['scope'] ?? $get['scope'] ?? ''),
            'website_code' => (string)($post['website_code'] ?? $get['website_code'] ?? ''),
            'store_code' => (string)($post['store_code'] ?? $get['store_code'] ?? ''),
            'channel_code' => (string)($post['channel_code'] ?? $get['channel_code'] ?? ''),
        ];
        // POST JSON body may carry target_scope
        $bodyScope = $this->getRequestPayloadValue('target_scope');
        if (($input['target_scope'] === '' || $input['target_scope'] === null) && $bodyScope !== null && $bodyScope !== '') {
            $input['target_scope'] = $bodyScope;
        }

        $resolved = $targetScopeService->resolveFromInput($input, false);
        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');

        $hasExplicit = trim((string)($get['target_scope'] ?? '')) !== ''
            || trim((string)($get['scope'] ?? '')) !== ''
            || array_key_exists('website_code', $get)
            || trim((string)($post['target_scope'] ?? '')) !== '';

        if ($normalizeUrl && !$hasExplicit && !$this->request->isPost()) {
            $this->redirect($this->scopedConfigUrl($resolved));
        }

        return [
            'kind' => (string)($resolved['kind'] ?? 'global'),
            'website_code' => (string)($resolved['website_code'] ?? ''),
            'store_code' => (string)($resolved['store_code'] ?? ''),
            'channel_code' => (string)($resolved['channel_code'] ?? ''),
            'storage_scope' => $storageScope,
        ];
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string} $workScope
     */
    private function assignScopeVars(array $workScope): void
    {
        $storageScope = (string)($workScope['storage_scope'] ?? 'default.default.default');
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($workScope['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($workScope['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($workScope['channel_code'] ?? ''));
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string} $workScope
     */
    private function scopedConfigUrl(array $workScope): string
    {
        return $this->_url->getBackendUrl('smtp/backend/config', [
            'target_scope' => (string)($workScope['storage_scope'] ?? 'default.default.default'),
            'website_code' => (string)($workScope['website_code'] ?? ''),
            'store_code' => (string)($workScope['store_code'] ?? ''),
            'channel_code' => (string)($workScope['channel_code'] ?? ''),
        ]);
    }

    private function getRequestPayloadValue(string $key): ?string
    {
        $value = $this->request->getPost($key);
        if ($value !== null && $value !== '') {
            return is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $bodyParams = $this->request->getBodyParams(true);
        if (is_array($bodyParams) && array_key_exists($key, $bodyParams)) {
            $bodyValue = $bodyParams[$key];
            return is_scalar($bodyValue) ? (string)$bodyValue : json_encode($bodyValue, JSON_UNESCAPED_UNICODE);
        }

        $rawBody = '';
        if (method_exists($this->request, 'getParameterBag')) {
            $rawBody = (string)$this->request->getParameterBag()->getRawBody();
        }
        if ($rawBody === '') {
            return null;
        }

        $trimmed = ltrim($rawBody);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $json = json_decode($rawBody, true);
            if (is_array($json) && array_key_exists($key, $json)) {
                $jsonValue = $json[$key];
                return is_scalar($jsonValue) ? (string)$jsonValue : json_encode($jsonValue, JSON_UNESCAPED_UNICODE);
            }
        }

        parse_str($rawBody, $params);
        if (array_key_exists($key, $params)) {
            $paramValue = $params[$key];
            return is_scalar($paramValue) ? (string)$paramValue : json_encode($paramValue, JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function jsonSuccess(string $msg): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $msg): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    }

    private function loadMailSmtpAccounts(): array
    {
        try {
            $result = w_query('mail', 'getSmtpAccounts', ['limit' => 200]);
            if (is_array($result) && !empty($result['success']) && is_array($result['items'] ?? null)) {
                return $result['items'];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    private function loadMailSmtpAccountConfig(int $accountId): ?array
    {
        try {
            $result = w_query('mail', 'getSmtpAccountConfig', ['account_id' => $accountId]);
            if (is_array($result) && !empty($result['success']) && is_array($result['config'] ?? null)) {
                return $result['config'];
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
