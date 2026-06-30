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
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Smtp::system_smtp_config', 'SMTP 配置', 'mdi-email-send-outline', 'SMTP 邮件服务配置', 'Weline_Smtp::system_smtp')]
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

    #[Acl('Weline_Smtp::smtp_config_index', '配置页', 'mdi-cog', '查看 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function index(): string
    {
        $senders = $this->data->getSenders('Weline_Smtp');
        $mailAccounts = $this->loadMailSmtpAccounts();
        $contacts = [];
        foreach ($senders as $s) {
            $code = $s['code'] ?? '';
            if ($code !== '') {
                $contacts[$code] = $this->data->getSenderContact($code, 'Weline_Smtp');
            }
        }
        $legacy = $this->data->get();
        $this->assign('senders', $senders);
        $this->assign('mail_accounts', $mailAccounts);
        $this->assign('sender_contacts', $contacts);
        $this->assign('legacy', $legacy);
        return $this->fetch('Weline_Smtp::Backend/Config');
    }

    /** @deprecated 兼容旧路由，重定向到 index */
    public function get(): string
    {
        return $this->index();
    }

    #[Acl('Weline_Smtp::smtp_config_save', '保存配置', 'mdi-content-save', '保存 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function post(): string
    {
        $smtp_configs = array_intersect_key($this->request->getPost(), array_flip(Data::keys));
        $smtp_configs['smtp_secure'] = (string) ($smtp_configs['smtp_secure'] ?? $this->data->get(Data::smtp_secure) ?: '1');
        $smtp_configs['smtp_auth'] = (string) ($smtp_configs['smtp_auth'] ?? $this->data->get(Data::smtp_auth) ?: '1');
        $has_error = '';
        foreach ($smtp_configs as $key => $config) {
            try {
                $this->data->set($key, $config);
            } catch (Exception $e) {
                $has_error .= $e->getMessage();
            }
        }
        if (empty($has_error)) {
            $this->getMessageManager()->addSuccess(__('Smtp配置成功！为了保证Smtp邮件服务正常工作，请测试确认。'));
        } else {
            $this->getMessageManager()->addError($has_error);
        }
        $this->redirect($this->_url->getBackendUrl('smtp/backend/config'));
    }

    #[Acl('Weline_Smtp::smtp_config_test', '测试发送', 'mdi-send', '测试 SMTP 发送', 'Weline_Smtp::system_smtp_config')]
    public function postTest(): string
    {
        $test_email = $this->request->getPost('smtp_test_address');
        $sender_code = $this->request->getPost('sender_code', '');
        $module = 'Weline_Smtp';
        if ($sender_code !== '') {
            $result = w_query('smtp', 'send', [
                'sender_code' => $sender_code,
                'to' => $test_email,
                'subject' => __('[SMTP 测试] 发件人 %{1}', [$sender_code]),
                'content' => __('这是一封测试邮件。如果您收到此邮件，说明该发件人配置正确。'),
                'module' => $module,
            ]);
            if ($result['success']) {
                $this->getMessageManager()->addSuccess(__('邮件发送成功！'));
            } else {
                $this->getMessageManager()->addError($result['message'] ?? __('发送失败'));
            }
        } else {
            try {
                $this->data->set('smtp_test_address', $test_email);
            } catch (Exception $e) {
                $this->getMessageManager()->addError($e->getMessage());
            }
            try {
                $smtpSender = ObjectManager::getInstance(SmtpSender::class);
                $smtpSender->sender(
                    ['email' => $this->data->get($this->data::smtp_username), 'name' => __('发送者')],
                    $test_email,
                    __('WelineFramework SMTP 测试'),
                    __('这是一封测试邮件。')
                );
                $this->getMessageManager()->addSuccess(__('邮件发送成功！'));
            } catch (\Throwable $e) {
                $this->getMessageManager()->addError($e->getMessage());
            }
        }
        $this->redirect($this->_url->getBackendUrl('smtp/backend/config'));
    }

    /** 保存多发件人配置（JSON）及联系人 */
    #[Acl('Weline_Smtp::smtp_config_save', '保存配置', 'mdi-content-save', '保存 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function saveSenders(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonError(__('无效的请求方法'));
        }
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
                $existing = $this->data->getSenders($module);
                $existingByCode = [];
                foreach ($existing as $e) {
                    $c = $e['code'] ?? '';
                    if ($c !== '') {
                        $existingByCode[$c] = $e;
                    }
                }
                foreach ($senders as &$s) {
                    $code = trim((string)($s['code'] ?? ''));
                    $s['code'] = $code;
                    if ($code !== '' && (trim((string)($s['smtp_password'] ?? '')) === '') && isset($existingByCode[$code]['smtp_password'])) {
                        $s['smtp_password'] = $existingByCode[$code]['smtp_password'];
                    }
                    $sourceType = (string)($s['source_type'] ?? 'external');
                    $sourceType = in_array($sourceType, ['external', 'mail_account'], true) ? $sourceType : 'external';
                    $s['source_type'] = $sourceType;
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
                $this->data->setSenders($senders, $module);
            }
        }
        if ($contactsJson !== null && $contactsJson !== '') {
            $contacts = json_decode($contactsJson, true);
            if (is_array($contacts)) {
                foreach ($contacts as $code => $toEmail) {
                    if (is_string($code) && $code !== '') {
                        $this->data->setSenderContact($code, trim((string) $toEmail), $module);
                    }
                }
            }
        }
        return $this->jsonSuccess(__('保存成功'));
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
