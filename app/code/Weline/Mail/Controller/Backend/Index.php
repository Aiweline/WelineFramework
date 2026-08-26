<?php
declare(strict_types=1);

namespace Weline\Mail\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Model\MailMessage;
use Weline\Mail\Service\DnsRecordAdvisor;
use Weline\Mail\Service\StalwartEngineAdapter;

#[\Weline\Framework\Acl\Acl(
    'Weline_Mail::mail',
    '企业邮箱管理',
    'mail',
    '管理企业邮箱、用户账号、邮件与 DNS'
)]
class Index extends BackendController
{
    public function index(): string
    {
        $requestedView = strtolower(trim((string)$this->request->getParam('view', 'mailbox')));
        $mailView = match ($requestedView) {
            'config', 'domains' => 'domains',
            'accounts' => 'accounts',
            default => 'mailbox',
        };
        $folder = strtolower(trim((string)$this->request->getParam('folder', 'inbox')));
        if (!in_array($folder, ['inbox', 'sent'], true)) {
            $folder = 'inbox';
        }

        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);
        $domains = $domainModel->clear()
            ->order(MailDomain::schema_fields_DOMAIN_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        $accounts = $accountModel->clear()
            ->order(MailAccount::schema_fields_DOMAIN_ID, 'ASC')
            ->order(MailAccount::schema_fields_EMAIL, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $domainLookup = $this->buildDomainLookup($domains);
        $domainAccountCounts = $this->buildDomainAccountCounts($accounts);
        $mailboxAccounts = array_values(array_filter(
            $accounts,
            static fn($account): bool => trim((string)$account->getData(MailAccount::schema_fields_EMAIL)) !== ''
        ));

        $selectedAccountId = max(0, (int)$this->request->getParam('account', 0));
        $selectedAccount = null;
        foreach ($mailboxAccounts as $mailboxAccount) {
            if ((int)$mailboxAccount->getId() === $selectedAccountId) {
                $selectedAccount = $mailboxAccount;
                break;
            }
        }
        if (!$selectedAccount && $mailboxAccounts !== []) {
            $selectedAccount = $mailboxAccounts[0];
            $selectedAccountId = (int)$selectedAccount->getId();
        }

        $selectedMailbox = $selectedAccount
            ? strtolower(trim((string)$selectedAccount->getData(MailAccount::schema_fields_EMAIL)))
            : '';
        $mailboxMessages = [];
        $selectedMessage = null;
        $mailboxError = null;
        $messageId = trim((string)$this->request->getParam('message', ''));

        if ($selectedAccount && $mailView === 'mailbox') {
            $domainId = (int)$selectedAccount->getData(MailAccount::schema_fields_DOMAIN_ID);
            $selectedDomain = $domainLookup[$domainId] ?? null;
            $isFake = $selectedDomain
                && (string)$selectedDomain->getData(MailDomain::schema_fields_ENGINE) === 'fake';

            try {
                if ($isFake) {
                    /** @var MailMessage $messageModel */
                    $messageModel = ObjectManager::getInstance(MailMessage::class);
                    $items = $messageModel->clear()
                        ->where(MailMessage::schema_fields_ACCOUNT_ID, $selectedAccountId)
                        ->where(MailMessage::schema_fields_FOLDER, $folder)
                        ->order(MailMessage::schema_fields_ID, 'DESC')
                        ->limit(50)
                        ->select()
                        ->fetch()
                        ->getItems();
                    foreach ($items as $item) {
                        $body = (string)$item->getData(MailMessage::schema_fields_BODY);
                        $normalized = [
                            'id' => (string)$item->getId(),
                            'from' => (string)$item->getData(MailMessage::schema_fields_FROM_EMAIL),
                            'to' => (string)$item->getData(MailMessage::schema_fields_TO_EMAIL),
                            'subject' => (string)$item->getData(MailMessage::schema_fields_SUBJECT),
                            'received_at' => (string)$item->getData(MailMessage::schema_fields_CREATED_AT),
                            'preview' => strlen($body) > 120 ? substr($body, 0, 117) . '...' : $body,
                            'body' => $body,
                            'unread' => !(bool)$item->getData(MailMessage::schema_fields_IS_READ),
                            'has_attachment' => false,
                        ];
                        $mailboxMessages[] = $normalized;
                        if ($messageId !== '' && $normalized['id'] === $messageId) {
                            $selectedMessage = $normalized;
                        }
                    }
                } else {
                    /** @var StalwartEngineAdapter $engine */
                    $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
                    $mailboxMessages = $engine->listMailboxMessages($selectedMailbox, $folder, 50);
                    if ($messageId !== '') {
                        $selectedMessage = $engine->getMailboxMessage($selectedMailbox, $messageId);
                    }
                }
            } catch (\Throwable $throwable) {
                $mailboxError = $throwable->getMessage();
            }
        }

        $this->assign('mail_view', $mailView);
        $this->assign('folder', $folder);
        $this->assign('domains', $domains);
        $this->assign('accounts', $accounts);
        $this->assign('mailbox_accounts', $mailboxAccounts);
        $this->assign('selected_account_id', $selectedAccountId);
        $this->assign('selected_mailbox', $selectedMailbox);
        $this->assign('mailbox_messages', $mailboxMessages);
        $this->assign('selected_message', $selectedMessage);
        $this->assign('mailbox_error', $mailboxError);
        $this->assign('domain_lookup', $domainLookup);
        $this->assign('domain_account_counts', $domainAccountCounts);
        $this->assign('website_domain_options', $this->loadWebsiteDomainOptions($domains, $domainAccountCounts));
        $this->assign(
            'management_ready',
            ObjectManager::getInstance(\Weline\Mail\Service\StalwartManagementAdapter::class)
                ->hasManagementCredential()
        );

        return $this->fetch('Weline_Mail::templates/Backend/Index/enterprise.phtml');
    }

    public function postCreateDomain(): string
    {
        $domain = strtolower(trim((string)$this->request->getParam('domain_name', '')));
        if ($domain === '') {
            $domain = strtolower(trim((string)$this->request->getParam('website_domain', '')));
        }
        $hostname = strtolower(trim((string)$this->request->getParam('hostname', '')));
        $engine = strtolower(trim((string)$this->request->getParam('engine', 'stalwart')));
        $quota = max(128, (int)$this->request->getParam('default_quota_mb', 1024));

        if ($domain === '') {
            return $this->respondFormResult(400, __('请选择或输入邮箱域名'));
        }
        if ($hostname === '') {
            $hostname = 'mail.' . $domain;
        }

        if (!in_array($engine, ['stalwart', 'fake'], true)) {
            return $this->respondFormResult(400, __('邮件引擎参数无效'));
        }

        if ($engine === 'fake' && !$this->isFakeTestDomain($domain)) {
            return $this->respondFormResult(400, __('Fake 测试引擎只允许 .invalid 或 .test 域名'));
        }

        if ($engine !== 'fake' && !$this->isWebsiteDomainCandidate($domain)) {
            return $this->respondFormResult(400, __('真实邮箱域名必须先从 Websites 域名候选中选择'));
        }

        /** @var MailDomain $model */
        $model = ObjectManager::getInstance(MailDomain::class);
        $existing = $model->clear()->where(MailDomain::schema_fields_DOMAIN_NAME, $domain)->find()->fetch();
        if ($existing->getId()) {
            return $this->respondFormResult(409, __('邮箱域名已存在'));
        }

        $now = date('Y-m-d H:i:s');
        $model->clear()
            ->setData(MailDomain::schema_fields_DOMAIN_NAME, $domain)
            ->setData(MailDomain::schema_fields_HOSTNAME, $hostname)
            ->setData(MailDomain::schema_fields_ENGINE, $engine)
            ->setData(MailDomain::schema_fields_STATUS, 'pending')
            ->setData(MailDomain::schema_fields_DEFAULT_QUOTA_MB, $quota)
            ->setData(MailDomain::schema_fields_CREATED_AT, $now)
            ->setData(MailDomain::schema_fields_UPDATED_AT, $now)
            ->save();

        return $this->respondFormResult(200, __('邮箱域名已创建，可在域名列表中开启或继续创建邮箱账号'));
    }

    public function postCreateAccount(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }

        /** @var \Weline\Mail\Service\MailAccountManagementService $service */
        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailAccountManagementService::class);
        $result = $service->createAccount(
            (int)$this->request->getParam('domain_id', 0),
            (string)$this->request->getParam('email', ''),
            (string)$this->request->getParam('local_part', ''),
            (string)$this->request->getParam('display_name', ''),
            max(0, (int)$this->request->getParam('customer_id', 0)),
            max(128, (int)$this->request->getParam('quota_mb', 1024)),
            (string)$this->request->getParam('password', '')
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('邮箱账号操作失败')
        );
    }

    public function postSetDomainStatus(): string
    {
        $domainId = (int)$this->request->getParam('domain_id', 0);
        $status = (string)$this->request->getParam('status', '');

        if ($domainId <= 0 || !in_array($status, ['active', 'pending', 'suspended'], true)) {
            return $this->respondFormResult(400, __('域名状态参数无效'));
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return $this->respondFormResult(404, __('邮箱域名不存在'));
        }

        $domain->setData(MailDomain::schema_fields_STATUS, $status)
            ->setData(MailDomain::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->respondFormResult(200, __('邮箱域名状态已更新'));
    }

    /**
     * @param MailDomain[] $domains
     * @return array<int, array<string, mixed>>
     */
    private function buildDomainLookup(array $domains): array
    {
        $lookup = [];
        foreach ($domains as $domain) {
            $id = (int)$domain->getId();
            if ($id <= 0) {
                continue;
            }
            $lookup[$id] = [
                'domain_id' => $id,
                'domain_name' => (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME),
                'hostname' => (string)$domain->getData(MailDomain::schema_fields_HOSTNAME),
                'engine' => (string)$domain->getData(MailDomain::schema_fields_ENGINE),
                'status' => (string)$domain->getData(MailDomain::schema_fields_STATUS),
                'default_quota_mb' => (int)$domain->getData(MailDomain::schema_fields_DEFAULT_QUOTA_MB),
            ];
        }
        return $lookup;
    }

    /**
     * @param MailAccount[] $accounts
     * @return array<int, int>
     */
    private function buildDomainAccountCounts(array $accounts): array
    {
        $counts = [];
        foreach ($accounts as $account) {
            $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
            if ($domainId <= 0) {
                continue;
            }
            $counts[$domainId] = ($counts[$domainId] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param MailDomain[] $mailDomains
     * @param array<int, int> $accountCounts
     * @return array<int, array<string, mixed>>
     */
    private function loadWebsiteDomainOptions(array $mailDomains, array $accountCounts, int $limit = 500): array
    {
        $created = [];
        foreach ($mailDomains as $mailDomain) {
            $domainName = strtolower(trim((string)$mailDomain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            if ($domainName === '') {
                continue;
            }
            $domainId = (int)$mailDomain->getId();
            $created[$domainName] = [
                'mail_domain_id' => $domainId,
                'mail_status' => (string)$mailDomain->getData(MailDomain::schema_fields_STATUS),
                'mail_engine' => (string)$mailDomain->getData(MailDomain::schema_fields_ENGINE),
                'account_count' => (int)($accountCounts[$domainId] ?? 0),
            ];
        }

        $candidates = [];
        foreach ($this->queryWebsiteDomainPools($limit) as $row) {
            $domain = strtolower(trim((string)($row['root_domain'] ?? $row['domain'] ?? '')));
            $sourceDomain = strtolower(trim((string)($row['domain'] ?? $domain)));
            $this->mergeDomainCandidate($candidates, $domain, 'Websites 域名池', [
                'source_domain' => $sourceDomain,
                'website_ref' => isset($row['pool_id']) ? 'pool#' . (int)$row['pool_id'] : '',
                'website_status' => (string)($row['status'] ?? ''),
                'https_status' => (string)($row['https_status'] ?? ''),
            ]);
        }

        foreach ($this->queryWebsiteLocalDomains($limit) as $row) {
            $domain = strtolower(trim((string)($row['domain'] ?? $row['domain_name'] ?? '')));
            $this->mergeDomainCandidate($candidates, $domain, 'Websites 注册域名', [
                'website_ref' => isset($row['domain_id']) ? 'domain#' . (int)$row['domain_id'] : '',
                'website_status' => (string)($row['status'] ?? ''),
                'https_status' => (string)($row['https_status'] ?? ''),
            ]);
        }

        foreach ($created as $domainName => $mailState) {
            $this->mergeDomainCandidate($candidates, $domainName, 'Mail 已开通', []);
        }

        foreach ($candidates as $domainName => &$candidate) {
            $candidate['source_labels'] = array_values($candidate['source_labels']);
            $candidate['source'] = implode(' / ', $candidate['source_labels']);
            $candidate['is_created'] = isset($created[$domainName]);
            $candidate['mail_domain_id'] = $created[$domainName]['mail_domain_id'] ?? 0;
            $candidate['mail_status'] = $created[$domainName]['mail_status'] ?? '';
            $candidate['mail_engine'] = $created[$domainName]['mail_engine'] ?? '';
            $candidate['account_count'] = $created[$domainName]['account_count'] ?? 0;
        }
        unset($candidate);

        $options = array_values($candidates);
        usort($options, static function (array $a, array $b): int {
            if (($a['is_created'] ?? false) !== ($b['is_created'] ?? false)) {
                return ($a['is_created'] ?? false) ? 1 : -1;
            }
            return strcmp((string)$a['domain'], (string)$b['domain']);
        });
        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryWebsiteDomainPools(int $limit): array
    {
        try {
            $result = w_query('websites', 'getDomainPoolList', [
                'status' => 'active',
                'limit' => min(2000, max(1, $limit)),
            ]);
        } catch (\Throwable $e) {
            w_log_error('[Mail] Websites domain pool query failed: ' . $e->getMessage());
            return [];
        }
        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryWebsiteLocalDomains(int $limit): array
    {
        try {
            $result = w_query('websites', 'getLocalDomains', [
                'filters' => [],
                'page' => 1,
                'limit' => min(500, max(1, $limit)),
            ]);
        } catch (\Throwable $e) {
            w_log_error('[Mail] Websites local domain query failed: ' . $e->getMessage());
            return [];
        }
        $items = is_array($result) ? ($result['items'] ?? []) : [];
        return is_array($items) ? $items : [];
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, mixed> $meta
     */
    private function mergeDomainCandidate(array &$candidates, string $domain, string $source, array $meta): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !str_contains($domain, '.')) {
            return;
        }
        if (!isset($candidates[$domain])) {
            $candidates[$domain] = [
                'domain' => $domain,
                'source_domain' => (string)($meta['source_domain'] ?? $domain),
                'website_ref' => (string)($meta['website_ref'] ?? ''),
                'website_status' => (string)($meta['website_status'] ?? ''),
                'https_status' => (string)($meta['https_status'] ?? ''),
                'source_labels' => [],
            ];
        }
        $candidates[$domain]['source_labels'][$source] = $source;
        foreach (['source_domain', 'website_ref', 'website_status', 'https_status'] as $key) {
            if (($candidates[$domain][$key] ?? '') === '' && isset($meta[$key])) {
                $candidates[$domain][$key] = (string)$meta[$key];
            }
        }
    }

    private function isWebsiteDomainCandidate(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }
        foreach ($this->loadWebsiteDomainOptions([], [], 2000) as $option) {
            if ((string)($option['domain'] ?? '') === $domain) {
                return true;
            }
        }
        return false;
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_accounts_manage',
        '管理企业邮箱账号',
        'users',
        '开通、暂停及重置企业邮箱账号',
        'Weline_Mail::mail'
    )]
    public function postSetAccountStatus(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }

        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailAccountManagementService::class);
        $result = $service->setStatus(
            (int)$this->request->getPost('account_id', 0),
            (string)$this->request->getPost('status', '')
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('邮箱账号操作失败')
        );
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_accounts_password',
        '开通或重置邮箱密码',
        'key',
        '在 Stalwart 中开通账号或安全重置密码',
        'Weline_Mail::mail'
    )]
    public function postResetAccountPassword(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }

        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailAccountManagementService::class);
        $result = $service->provisionOrResetPassword(
            (int)$this->request->getPost('account_id', 0),
            (string)$this->request->getPost('password', '')
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('邮箱账号操作失败')
        );
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_send_as',
        '使用企业邮箱代发',
        'send',
        '管理员使用已启用的企业邮箱账号发送邮件',
        'Weline_Mail::mail'
    )]
    public function postSendAs(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }

        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailAccountManagementService::class);
        $result = $service->sendAs(
            (int)$this->request->getPost('account_id', 0),
            (string)$this->request->getPost('to_email', ''),
            (string)$this->request->getPost('subject', ''),
            (string)$this->request->getPost('body', '')
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('邮件发送失败')
        );
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_dns_cloudflare',
        '配置 Cloudflare 邮箱 DNS',
        'cloud',
        '预览并同步当前企业邮箱域名 DNS',
        'Weline_Mail::mail'
    )]
    public function postConfigureCloudflareDns(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }

        $domainId = (int)$this->request->getPost('domain_id', 0);
        $apply = (string)$this->request->getPost('apply', '') === '1';
        if ($domainId < 1) {
            return $this->respondFormResult(422, __('邮箱域名 ID 无效。'));
        }
        if ($apply && (string)$this->request->getPost('confirm_public_origin', '') !== '1') {
            return $this->respondFormResult(
                422,
                __('应用前必须确认邮件主机将以 DNS-only 暴露源站公网 IP。')
            );
        }

        try {
            $domain = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Mail\Model\MailDomain::class
            )->reset()->load($domainId);
            if (!$domain->getId()) {
                return $this->respondFormResult(404, __('邮箱域名不存在。'));
            }

            $originIp = trim((string)$this->request->getPost('origin_ip', ''));
            $selector = trim((string)$this->request->getPost('dkim_selector', ''));
            $publicKey = trim((string)$this->request->getPost('dkim_public_key', ''));

            $factory = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Mail\Service\MailDnsRecordFactory::class
            );
            $targets = $factory->build(
                (string)$domain->getData(\Weline\Mail\Model\MailDomain::schema_fields_DOMAIN_NAME),
                (string)$domain->getData(\Weline\Mail\Model\MailDomain::schema_fields_HOSTNAME),
                $originIp,
                $selector,
                $publicKey,
            );

            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_ORIGIN_IP, $originIp);
            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_DKIM_SELECTOR, $selector);
            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_DKIM_PUBLIC_KEY, $publicKey);
            $domain->save();

            $result = \w_query('cdn', 'reconcileMailDns', [
                'domain' => (string)$domain->getData(
                    \Weline\Mail\Model\MailDomain::schema_fields_DOMAIN_NAME
                ),
                'desired_records' => $targets['desired_records'],
                'dns_only_hosts' => $targets['dns_only_hosts'],
                'apply' => $apply,
            ]);
            if (!is_array($result)) {
                throw new \RuntimeException((string)__('CDN 模块返回了无效的 DNS 结果。'));
            }

            $domain->setData(
                \Weline\Mail\Model\MailDomain::schema_fields_DNS_STATUS_JSON,
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
            $domain->setData(
                \Weline\Mail\Model\MailDomain::schema_fields_LAST_CHECKED_AT,
                date('Y-m-d H:i:s'),
            );
            $domain->save();

            if (($result['success'] ?? false) !== true) {
                return $this->respondFormResult(
                    409,
                    (string)($result['message'] ?? __('Cloudflare DNS 配置失败。'))
                );
            }

            return $this->respondFormResult(
                200,
                (string)($result['message'] ?? (
                    $apply ? __('Cloudflare DNS 已同步。') : __('Cloudflare DNS 预览完成。')
                ))
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->respondFormResult(422, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->respondFormResult(
                500,
                __('Cloudflare DNS 配置失败：%{1}', $e->getMessage())
            );
        }
    }

    private function respondFormResult(int $code, mixed $message): string
    {
        if ($this->request->isAjax()) {
            return $this->fetchJson(['code' => $code, 'msg' => $message]);
        }

        if ($code >= 200 && $code < 300) {
            $this->getMessageManager()->addSuccess($message);
        } else {
            $this->getMessageManager()->addError($message);
        }

        $view = strtolower(trim((string)$this->request->getParam('return_view', 'accounts')));
        if (!in_array($view, ['mailbox', 'accounts', 'domains'], true)) {
            $view = 'accounts';
        }
        $query = ['view' => $view];
        $accountId = max(0, (int)$this->request->getParam('account_id', 0));
        if ($accountId > 0) {
            $query['account'] = $accountId;
        }
        $folder = strtolower(trim((string)$this->request->getParam('folder', '')));
        if (in_array($folder, ['inbox', 'sent'], true)) {
            $query['folder'] = $folder;
        }

        return $this->redirect(
            $this->_url->getBackendUrl('weline_mail/backend') . '?' . http_build_query($query)
        );
    }

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }
}
