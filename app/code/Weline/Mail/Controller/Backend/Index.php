<?php
declare(strict_types=1);

namespace Weline\Mail\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Service\DnsRecordAdvisor;
use Weline\Mail\Service\MailOriginIpResolver;
use Weline\Mail\Service\Mailbox\MailboxFolder;
use Weline\Mail\Service\Mailbox\MailboxOpsService;

#[\Weline\Framework\Acl\Acl(
    'Weline_Mail::mail',
    '企业邮局',
    'mail',
    '管理企业邮箱、用户账号、邮件与 DNS'
)]
class Index extends BackendController
{
    public function index(): string
    {
        $requestedView = strtolower(trim((string)$this->request->getParam('view', 'mailbox')));
        // CDN OAuth 回跳到邮件后台时强制进「域名与 DNS」，否则错误参数在邮箱页看不见。
        if (trim((string)$this->request->getGet('oauth_error', '')) === '1'
            || trim((string)$this->request->getGet('oauth_adapter', '')) !== ''
        ) {
            $requestedView = 'domains';
        }
        $mailView = match ($requestedView) {
            'config', 'domains' => 'domains',
            'accounts' => 'accounts',
            'smtp' => 'smtp',
            'routes', 'outbound-routes' => 'routes',
            default => 'mailbox',
        };
        $folder = strtolower(trim((string)$this->request->getParam('folder', 'inbox')));
        try {
            $folder = MailboxFolder::assertValid($folder);
        } catch (\InvalidArgumentException) {
            $folder = MailboxFolder::INBOX;
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
        $allAccounts = $accountModel->clear()
            ->order(MailAccount::schema_fields_DOMAIN_ID, 'ASC')
            ->order(MailAccount::schema_fields_EMAIL, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $accountFilterQ = trim((string)$this->request->getParam('q', ''));
        $accountFilterStatus = strtolower(trim((string)$this->request->getParam('status', '')));
        $accountFilterDomainId = max(0, (int)$this->request->getParam('domain_id', 0));
        $accountPage = max(1, (int)$this->request->getParam('page', 1));
        $accountPageSize = \Weline\Mail\Service\MailAccountManagementService::DEFAULT_PAGE_SIZE;
        $accountListTotal = count($allAccounts);
        $accountListHasMore = false;
        $accounts = $allAccounts;
        if ($mailView === 'accounts') {
            /** @var \Weline\Mail\Service\MailAccountManagementService $accountMgmt */
            $accountMgmt = ObjectManager::getInstance(
                \Weline\Mail\Service\MailAccountManagementService::class
            );
            $listed = $accountMgmt->listAccounts([
                'q' => $accountFilterQ,
                'status' => $accountFilterStatus,
                'domain_id' => $accountFilterDomainId,
                'page' => $accountPage,
                'page_size' => $accountPageSize,
            ]);
            $accountListTotal = (int)($listed['total'] ?? 0);
            $accountListHasMore = !empty($listed['has_more']);
            // SSR 直接用序列化行，避免 ORM 单例/克隆在长驻 worker 里把行数据冲成空。
            $accounts = [];
            foreach (($listed['items'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $aid = (int)($row['account_id'] ?? 0);
                if ($aid <= 0) {
                    continue;
                }
                $accounts[] = $row;
            }
        }

        $domainLookup = $this->buildDomainLookup($domains);
        $domainAccountCounts = $this->buildDomainAccountCounts($allAccounts);
        $mailboxAccounts = array_values(array_filter(
            $allAccounts,
            static fn($account): bool => trim((string)$account->getData(MailAccount::schema_fields_EMAIL)) !== ''
        ));
        // 真实域优先：一键装好后默认落到 contact@ 等可外投账号，避免卡在 .invalid 测试域「已发送」假成功。
        $mailboxAccounts = $this->orderMailboxAccountsPreferReal($mailboxAccounts, $domainLookup);

        $selectedAccountId = max(0, (int)$this->request->getParam('account', 0));
        $canPickAccount = $this->canPickAnyLocalMailbox();
        if (!$canPickAccount) {
            $ownAccountId = $this->resolveOwnLocalAccountId($mailboxAccounts);
            if ($ownAccountId > 0) {
                $selectedAccountId = $ownAccountId;
            }
        }
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
        // 显式点开测试域时保留；无参/坏参已在上方落到真实域优先序的首项。
        $preferredRealAccountId = $this->firstRealMailboxAccountId($mailboxAccounts, $domainLookup);

        $selectedMailbox = $selectedAccount
            ? strtolower(trim((string)$selectedAccount->getData(MailAccount::schema_fields_EMAIL)))
            : '';
        $selectedMailboxIsFake = false;
        if ($selectedAccount) {
            $selectedDomainId = (int)$selectedAccount->getData(MailAccount::schema_fields_DOMAIN_ID);
            $selectedDomainMeta = $domainLookup[$selectedDomainId] ?? null;
            $selectedDomainName = strtolower(trim((string)($selectedDomainMeta['domain_name'] ?? '')));
            $selectedMailboxIsFake = self::isFakeDomainLookupEntry($selectedDomainMeta)
                || $this->isFakeTestDomain($selectedDomainName);
        }
        $mailboxMessages = [];
        $selectedMessage = null;
        $mailboxError = null;
        $messageId = trim((string)$this->request->getParam('message', ''));
        /** @var array<int, int> $mailboxUnreadByAccount */
        $mailboxUnreadByAccount = [];

        if ($mailView === 'mailbox') {
            /** @var MailboxOpsService $ops */
            $ops = ObjectManager::getInstance(MailboxOpsService::class);
            $accountIds = [];
            foreach ($mailboxAccounts as $mailboxAccount) {
                $accountIds[] = (int)$mailboxAccount->getId();
            }
            $mailboxUnreadByAccount = $ops->mapInboxUnreadCounts($accountIds);
            // 收件箱默认显示全部（含已读）；?show=unread 才只看未读——避免点开标已读后从列表消失。
            // 兼容旧链 ?show=all（仍表示全部）。
            $showParam = strtolower(trim((string)$this->request->getParam('show', '')));
            $showAll = $showParam === 'all' || ($folder === MailboxFolder::INBOX && $showParam !== 'unread' && $showParam !== 'unread_only');
            $mailboxListUnreadOnly = $folder === MailboxFolder::INBOX && ($showParam === 'unread' || $showParam === 'unread_only');
            if ($selectedAccount) {
                try {
                    $mailboxMessages = $ops->list($selectedAccountId, $folder, 50, $mailboxListUnreadOnly);
                    if ($messageId !== '') {
                        $selectedMessage = $ops->get($selectedAccountId, $messageId);
                        if (is_array($selectedMessage) && (string)($selectedMessage['id'] ?? '') !== '') {
                            $this->touchMailMenuSignal($selectedAccountId, $messageId, false);
                        }
                    }
                } catch (\Throwable $throwable) {
                    $mailboxError = $throwable->getMessage();
                }
            }
        } else {
            $mailboxListUnreadOnly = false;
            $showAll = false;
        }

        $this->assign('mail_view', $mailView);
        $this->assign('folder', $folder);
        $this->assign('mailbox_list_unread_only', $mailboxListUnreadOnly ?? false);
        $this->assign('mailbox_show_all', $showAll ?? false);
        $this->assign('domains', $domains);
        $this->assign('accounts', $accounts);
        $this->assign('account_filter_q', $accountFilterQ);
        $this->assign('account_filter_status', $accountFilterStatus);
        $this->assign('account_filter_domain_id', $accountFilterDomainId);
        $this->assign('account_page', $accountPage);
        $this->assign('account_page_size', $accountPageSize);
        $this->assign('account_list_total', $accountListTotal);
        $this->assign('account_list_has_more', $accountListHasMore);
        $this->assign('mailbox_accounts', $mailboxAccounts);
        $this->assign('mailbox_unread_by_account', $mailboxUnreadByAccount);
        $this->assign('selected_account_id', $selectedAccountId);
        $this->assign('preferred_real_account_id', $preferredRealAccountId);
        $this->assign('selected_mailbox', $selectedMailbox);
        $this->assign('selected_mailbox_is_fake', $selectedMailboxIsFake);
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
        $detectedOriginIp = '';
        $cloudflareTokenReady = false;
        if ($mailView === 'domains') {
            $detectedOriginIp = ObjectManager::getInstance(MailOriginIpResolver::class)->resolve('');
            $cloudflareTokenReady = $this->isCloudflareDefaultTokenReady();
        }
        $this->assign('detected_origin_ip', $detectedOriginIp);
        $this->assign('cloudflare_token_ready', $cloudflareTokenReady);
        $this->assign('dns_live_checks', $mailView === 'domains'
            ? $this->buildLiveDnsChecks($domains, $detectedOriginIp)
            : []
        );

        $smtpServices = [];
        $smtpAccountsByService = [];
        $smtpPoolDomains = [];
        $smtpAccountPasswords = [];
        $smtpCanViewPasswords = false;
        $smtpOutboundPicks = [];
        $smtpExternalProviders = [];
        $smtpStalwartByService = [];
        $smtpLocalHealthByService = [];
        $smtpSystemCompletenessByService = [];
        $outboundRouteRules = [];
        if ($mailView === 'smtp' || $mailView === 'accounts' || $mailView === 'routes') {
            /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
            /** @var \Weline\Smtp\Service\SmtpExternalProviderCatalog $providerCatalog */
            $providerCatalog = ObjectManager::getInstance(
                \Weline\Smtp\Service\SmtpExternalProviderCatalog::class
            );
            $smtpExternalProviders = $providerCatalog->listPresetsForUi();
            if ($mailView === 'accounts' || $mailView === 'routes') {
                $smtpOutboundPicks = $catalog->listOutboundPicks();
            }
            if ($mailView === 'routes') {
                $outboundRouteRules = ObjectManager::getInstance(
                    \Weline\Mail\Service\MailOutboundRouteRuleService::class
                )->listRules();
            }
            if ($mailView === 'smtp') {
                $smtpServices = $catalog->listServices();
                /** @var \Weline\Smtp\Service\SmtpSystemAccountProvisioner $systemProvisioner */
                $systemProvisioner = ObjectManager::getInstance(
                    \Weline\Smtp\Service\SmtpSystemAccountProvisioner::class
                );
                foreach ($smtpServices as $svc) {
                    $sid = (int)$svc->getId();
                    if ($sid > 0) {
                        $smtpAccountsByService[$sid] = $catalog->listAccountsForService($sid);
                        if ((string)$svc->getData(\Weline\Smtp\Model\SmtpService::schema_fields_MODE)
                            === \Weline\Smtp\Model\SmtpService::MODE_SELF
                        ) {
                            $smtpStalwartByService[$sid] = $catalog->probeStalwartPresenceForService($sid);
                            $byId = is_array($smtpStalwartByService[$sid]['by_account_id'] ?? null)
                                ? $smtpStalwartByService[$sid]['by_account_id']
                                : [];
                            $smtpSystemCompletenessByService[$sid] = $systemProvisioner->inspectForService(
                                $sid,
                                $byId
                            );
                        }
                    }
                }
                /** @var \Weline\Smtp\Service\SmtpLocalDomainHealthService $localHealth */
                $localHealth = ObjectManager::getInstance(
                    \Weline\Smtp\Service\SmtpLocalDomainHealthService::class
                );
                $smtpLocalHealthByService = $localHealth->summarizeForServices($smtpServices);
                // 有「开通或重置邮箱密码」ACL 时本页直接解出密码展示，不另开 reveal 路由。
                $smtpCanViewPasswords = $this->isMailAclAllowed('Weline_Mail::mail_accounts_password');
                if ($smtpCanViewPasswords) {
                    foreach ($smtpAccountsByService as $accts) {
                        foreach ($accts as $acct) {
                            if (!$acct instanceof \Weline\Smtp\Model\SmtpAccount) {
                                continue;
                            }
                            $aid = (int)$acct->getId();
                            if ($aid <= 0) {
                                continue;
                            }
                            $revealed = $catalog->revealAccountPassword($aid);
                            if (!empty($revealed['success']) && (string)($revealed['password_once'] ?? '') !== '') {
                                $smtpAccountPasswords[$aid] = (string)$revealed['password_once'];
                            }
                        }
                    }
                }
                // getDomainPoolList 返回域名行列表（非 {items:...} 包装）；与 queryWebsiteDomainPools 同形。
                $smtpPoolDomains = $this->queryWebsiteDomainPools(200);
            }
        }
        $this->assign('smtp_services', $smtpServices);
        $this->assign('smtp_accounts_by_service', $smtpAccountsByService);
        $this->assign('smtp_pool_domains', $smtpPoolDomains);
        $this->assign('smtp_account_passwords', $smtpAccountPasswords);
        $this->assign('smtp_can_view_passwords', $smtpCanViewPasswords);
        $this->assign('smtp_outbound_picks', $smtpOutboundPicks);
        $this->assign('smtp_external_providers', $smtpExternalProviders);
        $this->assign('smtp_stalwart_by_service', $smtpStalwartByService);
        $this->assign('smtp_local_health_by_service', $smtpLocalHealthByService);
        $this->assign('smtp_system_completeness_by_service', $smtpSystemCompletenessByService);
        $this->assign('outbound_route_rules', $outboundRouteRules);

        $compose = $this->resolveComposeContext($selectedAccountId, $mailboxAccounts);
        $this->assign('compose_open', $compose['open']);
        $this->assign('compose_to', $compose['to']);
        $this->assign('compose_subject', $compose['subject']);
        $this->assign('compose_body', $compose['body']);
        $this->assign('compose_source', $compose['source']);
        $this->assign('compose_source_id', $compose['source_id']);
        $this->assign('compose_account_id', $compose['account_id']);
        $this->assign('compose_account_locked', $compose['account_locked']);
        $this->assign('compose_can_pick_account', $compose['can_pick_account']);
        $this->assign('compose_customer_select_value', $compose['customer_id']);
        $this->assign('compose_customer_select_display', $compose['customer_display']);

        return $this->fetch('Weline_Mail::templates/backend/Index/enterprise.phtml');
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

        /** @var \Weline\Mail\Service\MailDomainAdminService $service */
        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailDomainAdminService::class);
        $result = $service->createDomain(
            $domain,
            $hostname,
            $engine,
            $quota,
            fn (string $candidate): bool => $this->isWebsiteDomainCandidate($candidate)
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('邮箱域名操作失败')
        );
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

        $message = (string)__('邮箱域名状态已更新');
        // 启用域名：仅企业邮箱 contact@。Smtp 中继在 Smtp 模块独立启用，不在邮局开通。
        if ($status === 'active') {
            try {
                /** @var \Weline\Mail\Service\MailAccountEnsureService $ensure */
                $ensure = ObjectManager::getInstance(
                    \Weline\Mail\Service\MailAccountEnsureService::class
                );
                $parts = [];
                $contact = $ensure->ensureContactAccount($domainId);
                if (!empty($contact['success'])) {
                    $email = (string)($contact['email'] ?? '');
                    if (!empty($contact['created']) && (string)($contact['smtp_password_once'] ?? '') !== '') {
                        $parts[] = (string)__(
                            '联系邮箱 %{1} 已开通，密码（仅一次）：%{2}',
                            [$email, (string)$contact['smtp_password_once']]
                        );
                    } elseif ($email !== '') {
                        $parts[] = (string)__('联系邮箱 %{1} 已就绪', [$email]);
                    }
                } else {
                    $detail = (string)($contact['message'] ?? '');
                    $parts[] = $detail !== ''
                        ? (string)__('自动开通 contact@ 失败：%{1}', [$detail])
                        : (string)__('自动开通 contact@ 失败');
                }

                $message = (string)__('域名已启用') . '；' . implode('；', $parts);
            } catch (\Throwable $e) {
                w_log_error(
                    '[Mail] ' . __('启用域名后自动开通邮箱失败：%{1}', [$e->getMessage()]),
                    [],
                    'mail'
                );
                $message = (string)__(
                    '域名已启用，但自动开通邮箱异常：%{1}',
                    [$e->getMessage()]
                );
            }
        }

        return $this->respondFormResult(200, $message);
    }

    /**
     * @deprecated Smtp 已与邮局拆分；保留入口避免旧书签 404，一律提示去 Smtp 启用。
     */
    public function postSetDefaultSmtpAccount(): string
    {
        return $this->respondFormResult(
            410,
            (string)__('默认 Smtp 提交已迁至 Smtp 模块：请在「Smtp 配置」中启用并自配域名与账户，邮局不再开通 Smtp。')
        );
    }

    /**
     * @deprecated 外投已迁至账号编辑；保留入口避免旧书签 404。
     */
    public function postSaveOutboundRelay(): string
    {
        return $this->respondFormResult(
            410,
            (string)__('外投 smarthost 已迁至「用户与账号」编辑；域名与 DNS 不再配置外投。')
        );
    }

    public function postSetAccountOutboundRelay(): string
    {
        /** @var \Weline\Mail\Service\MailAccountOutboundRelayService $service */
        $service = ObjectManager::getInstance(\Weline\Mail\Service\MailAccountOutboundRelayService::class);
        $result = $service->setRelay([
            'account_id' => (int)$this->request->getParam('account_id', 0),
            'outbound_relay_enabled' => (int)$this->request->getParam('outbound_relay_enabled', 0),
            'outbound_relay_host' => (string)$this->request->getParam('outbound_relay_host', ''),
            'outbound_relay_port' => (int)$this->request->getParam('outbound_relay_port', 587),
            'outbound_relay_username' => (string)$this->request->getParam('outbound_relay_username', ''),
            'outbound_relay_password' => (string)$this->request->getParam('outbound_relay_password', ''),
            'outbound_relay_tls' => (string)$this->request->getParam('outbound_relay_tls', 'starttls'),
            'outbound_smtp_pick' => (string)$this->request->getParam('outbound_smtp_pick', ''),
        ]);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : (int)($result['code'] ?? 422),
            $result['message'] ?? __('外投保存失败')
        );
    }

    /**
     * 邮箱正文异步拉取（JSON），避免整页跳转。
     */
    public function getMailboxMessage(): string
    {
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $messageId = trim((string)$this->request->getParam('message', ''));
        if ($accountId <= 0 || $messageId === '') {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('缺少账号或邮件 ID'), 'data' => null]);
        }
        try {
            $data = ObjectManager::getInstance(MailboxOpsService::class)->get($accountId, $messageId);
            if (!is_array($data) || (string)($data['id'] ?? '') === '') {
                return $this->fetchJson(['code' => 404, 'msg' => (string)__('邮件不存在'), 'data' => null]);
            }
            $this->touchMailMenuSignal($accountId, $messageId, false);
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }

        return $this->fetchJson(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    public function getMailboxSearch(): string
    {
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $folder = strtolower(trim((string)$this->request->getParam('folder', 'inbox')));
        try {
            $folder = MailboxFolder::assertValid($folder);
        } catch (\InvalidArgumentException) {
            $folder = MailboxFolder::INBOX;
        }
        $q = trim((string)$this->request->getParam('q', ''));
        if ($accountId <= 0) {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('缺少账号'), 'data' => []]);
        }
        try {
            $rows = ObjectManager::getInstance(MailboxOpsService::class)->search($accountId, $folder, $q, 50);
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => []]);
        }
        return $this->fetchJson(['code' => 200, 'msg' => 'ok', 'data' => $rows]);
    }

    /**
     * 邮件夹列表异步拉取（JSON），供刷新/轮询收信，避免整页 reload。
     */
    public function getMailboxList(): string
    {
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $folder = strtolower(trim((string)$this->request->getParam('folder', 'inbox')));
        $limit = max(1, min(100, (int)$this->request->getParam('limit', 50)));
        try {
            $folder = MailboxFolder::assertValid($folder);
        } catch (\InvalidArgumentException) {
            $folder = MailboxFolder::INBOX;
        }
        if ($accountId <= 0) {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('缺少账号'), 'data' => null]);
        }
        $showParam = strtolower(trim((string)$this->request->getParam('show', '')));
        $unreadOnly = $folder === MailboxFolder::INBOX
            && ($showParam === 'unread' || $showParam === 'unread_only');
        try {
            $ops = ObjectManager::getInstance(MailboxOpsService::class);
            $rows = $ops->list($accountId, $folder, $limit, $unreadOnly);
            $unread = $folder === MailboxFolder::INBOX ? $ops->countUnreadInbox($accountId) : 0;
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }

        return $this->fetchJson([
            'code' => 200,
            'msg' => 'ok',
            'data' => [
                'folder' => $folder,
                'items' => $rows,
                'unread_inbox' => $unread,
                'unread_only' => $unreadOnly,
                'fetched_at' => time(),
            ],
        ]);
    }

    public function postMailboxBatch(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['code' => 405, 'msg' => (string)__('无效的请求方法'), 'data' => null]);
        }
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $action = strtolower(trim((string)$this->request->getParam('action', '')));
        $folder = strtolower(trim((string)$this->request->getParam('folder', 'inbox')));
        $idsRaw = $this->request->getParam('ids', []);
        if (is_string($idsRaw)) {
            $idsRaw = preg_split('/[\s,]+/', $idsRaw) ?: [];
        }
        $ids = is_array($idsRaw) ? array_values(array_map('strval', $idsRaw)) : [];
        try {
            $result = ObjectManager::getInstance(MailboxOpsService::class)
                ->batch($accountId, $action, $ids, $folder);
            if (!empty($result['success']) && ($action === 'seen' || $action === 'unseen')) {
                foreach ($ids as $id) {
                    $this->touchMailMenuSignal($accountId, (string)$id, $action === 'unseen');
                }
            }
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
        return $this->fetchJson([
            'code' => !empty($result['success']) ? 200 : 422,
            'msg' => (string)($result['message'] ?? ''),
            'data' => null,
        ]);
    }

    public function getMailboxComposePrefill(): string
    {
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $messageId = trim((string)$this->request->getParam('message', ''));
        $mode = strtolower(trim((string)$this->request->getParam('mode', 'reply')));
        if ($accountId <= 0 || $messageId === '') {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('缺少账号或邮件 ID'), 'data' => null]);
        }
        try {
            $data = ObjectManager::getInstance(MailboxOpsService::class)
                ->buildComposePrefill($accountId, $messageId, $mode);
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
        return $this->fetchJson(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    public function getMailboxAttachment(): string
    {
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $blobId = trim((string)$this->request->getParam('blob', ''));
        $name = trim((string)$this->request->getParam('name', 'attachment'));
        if ($accountId <= 0 || $blobId === '') {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('缺少附件参数'), 'data' => null]);
        }
        try {
            $file = ObjectManager::getInstance(MailboxOpsService::class)
                ->downloadAttachment($accountId, $blobId, $name);
        } catch (\Throwable $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
        $safeName = preg_replace('/[^\w.\-]+/u', '_', $file['name']) ?: 'attachment';
        header('Content-Type: ' . ($file['type'] !== '' ? $file['type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string)$file['size']);
        echo $file['content'];
        throw new ResponseTerminateException();
    }

    public function postMailboxUpload(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['code' => 405, 'msg' => (string)__('无效的请求方法'), 'data' => null]);
        }
        $accountId = max(0, (int)$this->request->getParam('account', 0));
        $file = $_FILES['file'] ?? null;
        if ($accountId <= 0 || !is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('请选择附件'), 'data' => null]);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'attachment');
        $type = (string)($file['type'] ?? 'application/octet-stream');
        $binary = is_readable($tmp) ? (string)file_get_contents($tmp) : '';
        if ($binary === '') {
            return $this->fetchJson(['code' => 400, 'msg' => (string)__('附件读取失败'), 'data' => null]);
        }
        $result = ObjectManager::getInstance(MailboxOpsService::class)->upload($accountId, $name, $type, $binary);
        if (isset($result['success']) && $result['success'] === false) {
            return $this->fetchJson(['code' => 422, 'msg' => (string)($result['message'] ?? ''), 'data' => null]);
        }
        return $this->fetchJson(['code' => 200, 'msg' => 'ok', 'data' => $result]);
    }

    public function postCreateSmtpServiceSelf(): string
    {
        $name = trim((string)$this->request->getParam('name', ''));
        $mode = trim((string)$this->request->getParam('domain_mode', 'type'));
        $domain = $mode === 'pool'
            ? trim((string)$this->request->getParam('pool_domain', ''))
            : trim((string)$this->request->getParam('typed_domain', ''));
        $expectedIp = trim((string)$this->request->getParam('expected_ip', ''));
        $relay = (int)$this->request->getParam('relay_service', 0) === 1;
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->createSelfService($name, $domain, $expectedIp, $relay);
        $msg = (string)($result['message'] ?? __('失败'));

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            $msg
        );
    }

    public function postCreateSmtpServiceExternal(): string
    {
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->createExternalService(
            trim((string)$this->request->getParam('name', '')),
            trim((string)$this->request->getParam('smtp_hostname', '')),
            (int)$this->request->getParam('port', 587),
            trim((string)$this->request->getParam('username', '')),
            (string)$this->request->getParam('password', ''),
            strtolower(trim((string)$this->request->getParam('tls_mode', 'starttls')))
        );

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postCreateSmtpServiceAccount(): string
    {
        $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
        $local = trim((string)$this->request->getParam('local_part', ''));
        $role = trim((string)$this->request->getParam('account_role', 'submit'));
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->createAccountOnService($serviceId, $local, $role);
        $msg = (string)($result['message'] ?? __('失败'));
        $once = (string)($result['password_once'] ?? '');
        if (!empty($result['success']) && $once !== '') {
            $msg .= ' ' . (string)__('密码（仅一次）：%{1}', [$once]);
        }

        return $this->respondFormResult(!empty($result['success']) ? 200 : 400, $msg);
    }

    public function postUpdateSmtpServiceAccount(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限修改 Smtp 账户'));
        }
        $accountId = (int)$this->request->getParam('smtp_account_id', 0);
        $role = trim((string)$this->request->getParam('account_role', ''));
        $status = trim((string)$this->request->getParam('account_status', ''));
        $display = trim((string)$this->request->getParam('display_name', ''));
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->updateAccountOnService($accountId, $role, $status, $display);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postDeleteSmtpServiceAccount(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限删除 Smtp 账户'));
        }
        $accountId = (int)$this->request->getParam('smtp_account_id', 0);
        $alsoStalwart = (string)$this->request->getParam('also_stalwart', '1') !== '0';
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->deleteAccountOnService($accountId, $alsoStalwart);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postResetSmtpServiceAccountPassword(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        // 复用「开通或重置邮箱密码」ACL，不另注册 reveal/reset 路由资源。
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限重置 Smtp 账户密码'));
        }
        $accountId = (int)$this->request->getParam('smtp_account_id', 0);
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->resetAccountPassword($accountId);
        $msg = (string)($result['message'] ?? __('失败'));
        $once = (string)($result['password_once'] ?? '');
        $email = (string)($result['email'] ?? '');
        if (!empty($result['success']) && $once !== '') {
            $msg .= ' ' . (string)__('账号 %{1} 新密码：%{2}', [$email !== '' ? $email : (string)$accountId, $once]);
        }

        return $this->respondFormResult(!empty($result['success']) ? 200 : 400, $msg);
    }

    public function postSyncSmtpServiceAccountToStalwart(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限写入 Smtp 账户到本机 Stalwart 邮局'));
        }
        $accountId = (int)$this->request->getParam('smtp_account_id', 0);
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->syncAccountToStalwart($accountId);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postResetSmtpSystemAccounts(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限重置系统代理账户'));
        }
        $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
        /** @var \Weline\Smtp\Service\SmtpSystemAccountProvisioner $systemProvisioner */
        $systemProvisioner = ObjectManager::getInstance(
            \Weline\Smtp\Service\SmtpSystemAccountProvisioner::class
        );
        $inspect = $systemProvisioner->inspectForService($serviceId);
        if (empty($inspect['show_reset'])) {
            return $this->respondFormResult(200, (string)__('系统代理账户已齐全，无需重置'));
        }
        $result = $systemProvisioner->repairForService($serviceId);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postImportSmtpAccountsFromStalwart(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
            return $this->respondFormResult(403, __('无权限从本机 Stalwart 同步账号'));
        }
        $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->importAccountsFromStalwart($serviceId);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_accounts_manage',
        '管理企业邮箱账号',
        'users',
        '从企业邮箱域名同步 Smtp 服务目录',
        'Weline_Mail::mail'
    )]
    public function postImportSmtpServicesFromMailDomains(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondFormResult(405, __('无效的请求方法'));
        }
        if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_manage')) {
            return $this->respondFormResult(403, __('无权限从企业邮箱域名同步 Smtp 服务'));
        }
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->importSelfServicesFromMailDomains();

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postSetSmtpServiceStatus(): string
    {
        $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
        $status = trim((string)$this->request->getParam('status', 'disabled'));
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->setStatus($serviceId, $status);

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postUpdateSmtpServiceExternal(): string
    {
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->updateExternalService(
            (int)$this->request->getParam('smtp_service_id', 0),
            trim((string)$this->request->getParam('name', '')),
            trim((string)$this->request->getParam('smtp_hostname', '')),
            (int)$this->request->getParam('port', 587),
            trim((string)$this->request->getParam('username', '')),
            (string)$this->request->getParam('password', ''),
            strtolower(trim((string)$this->request->getParam('tls_mode', 'starttls')))
        );
        if (!empty($result['success'])) {
            PostResponseTaskQueue::enqueue(
                'mail-outbound-relay-sync',
                static function (): void {
                    try {
                        ObjectManager::getInstance(\Weline\Mail\Service\MailOutboundRelaySyncService::class)->syncAll();
                    } catch (\Throwable) {
                    }
                }
            );
        }

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    public function postDeleteSmtpService(): string
    {
        $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
        /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
        $result = $catalog->deleteService($serviceId);
        if (!empty($result['success'])) {
            PostResponseTaskQueue::enqueue(
                'mail-outbound-relay-sync',
                static function (): void {
                    try {
                        ObjectManager::getInstance(\Weline\Mail\Service\MailOutboundRelaySyncService::class)->syncAll();
                    } catch (\Throwable) {
                    }
                }
            );
        }

        return $this->respondFormResult(
            !empty($result['success']) ? 200 : 400,
            (string)($result['message'] ?? __('失败'))
        );
    }

    /**
     * SSE：删除 Smtp 服务（本机会逐账户卸 Stalwart），进度事件后 done/error。
     * complete() + CaptureMode::None：避免 WLS 捕获 Fiber 上下文冲掉 SSE 流。
     */
    public function postSmtpServiceDeleteStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }

            $serviceId = (int)$this->request->getParam('smtp_service_id', 0);
            $sse->sendEvent('start', [
                'message' => (string)__('开始删除 Smtp 服务…'),
                'progress' => 0,
                'smtp_service_id' => $serviceId,
            ]);

            /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
            $result = $catalog->deleteService(
                $serviceId,
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                }
            );

            if (empty($result['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('失败')),
                    'progress' => 100,
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('已删除')),
                'progress' => 100,
                'smtp_service_id' => $serviceId,
            ]);

            PostResponseTaskQueue::enqueue(
                'mail-outbound-relay-sync',
                static function (): void {
                    try {
                        ObjectManager::getInstance(\Weline\Mail\Service\MailOutboundRelaySyncService::class)->syncAll();
                    } catch (\Throwable) {
                    }
                },
                0.0,
                \Weline\Framework\Runtime\PostResponseCaptureMode::None
            );
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('删除失败'),
                ]);
                $sse->close();
            }
        }

        return '';
    }

    /**
     * SSE：删除 Smtp 账户（默认同步卸本机 Stalwart）。
     */
    public function postSmtpServiceAccountDeleteStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }
            if (!$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无权限删除 Smtp 账户'),
                ]);
                $sse->close();

                return '';
            }

            $accountId = (int)$this->request->getParam('smtp_account_id', 0);
            $alsoStalwart = (string)$this->request->getParam('also_stalwart', '1') !== '0';
            $sse->sendEvent('start', [
                'message' => (string)__('开始删除 Smtp 账户…'),
                'progress' => 0,
                'smtp_account_id' => $accountId,
            ]);

            /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
            $result = $catalog->deleteAccountOnService(
                $accountId,
                $alsoStalwart,
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                }
            );

            if (empty($result['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('失败')),
                    'progress' => 100,
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('已删除')),
                'progress' => 100,
                'smtp_account_id' => $accountId,
                'email' => (string)($result['email'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('删除失败'),
                ]);
                $sse->close();
            }
        }

        return '';
    }

    /**
     * SSE：按库将 Mail/Smtp 账户录入本机 Stalwart（需管理连接；系统账号直调 adapter）。
     * complete() + CaptureMode::None：对齐删除流，避免 WLS 捕获冲掉 SSE。
     */
    public function postSyncEngineAccountsFromDbStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }
            if (
                !$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')
                && !$this->isMailAclAllowed('Weline_Mail::mail_accounts_manage')
            ) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无权限从数据库同步到本机引擎'),
                ]);
                $sse->close();

                return '';
            }

            $force = (string)$this->request->getParam('force', '0') === '1';
            $sse->sendEvent('start', [
                'message' => (string)__('开始从数据库同步到本机引擎…'),
                'progress' => 0,
            ]);

            /** @var \Weline\Mail\Service\MailEngineAccountSyncService $sync */
            $sync = ObjectManager::getInstance(\Weline\Mail\Service\MailEngineAccountSyncService::class);
            $result = $sync->syncFromDatabase(
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                },
                static function (string $email, string $passwordOnce) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('password_once', [
                        'email' => $email,
                        'password_once' => $passwordOnce,
                    ]);
                },
                $force
            );

            if (empty($result['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('同步失败')),
                    'progress' => 100,
                    'provisioned' => (int)($result['provisioned'] ?? 0),
                    'skipped' => (int)($result['skipped'] ?? 0),
                    'regenerated' => (int)($result['regenerated'] ?? 0),
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('已同步')),
                'progress' => 100,
                'provisioned' => (int)($result['provisioned'] ?? 0),
                'skipped' => (int)($result['skipped'] ?? 0),
                'regenerated' => (int)($result['regenerated'] ?? 0),
                'reader_synced' => !empty($result['reader_synced']),
            ]);
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('同步失败'),
                ]);
                $sse->close();
            }
        }

        return '';
    }

    /**
     * SSE：全局一键配置（绑定管理凭据 → 按库同步）。
     * complete() + CaptureMode::None：对齐同步流，避免 WLS 捕获冲掉 SSE。
     */
    public function postOneClickMailSetupStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }
            if (
                !$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')
                && !$this->isMailAclAllowed('Weline_Mail::mail_accounts_manage')
            ) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无权限一键配置邮局'),
                ]);
                $sse->close();

                return '';
            }

            $sse->sendEvent('start', [
                'message' => (string)__('开始一键配置邮局…'),
                'progress' => 0,
            ]);

            /** @var \Weline\Mail\Service\MailOneClickSetupService $setup */
            $setup = ObjectManager::getInstance(\Weline\Mail\Service\MailOneClickSetupService::class);
            $result = $setup->oneClickGlobal(
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                },
                static function (string $email, string $passwordOnce) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('password_once', [
                        'email' => $email,
                        'password_once' => $passwordOnce,
                    ]);
                }
            );

            if (empty($result['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('一键配置失败')),
                    'hint' => (string)($result['hint'] ?? ''),
                    'progress' => 100,
                    'provisioned' => (int)($result['provisioned'] ?? 0),
                    'skipped' => (int)($result['skipped'] ?? 0),
                    'regenerated' => (int)($result['regenerated'] ?? 0),
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('一键配置完成')),
                'progress' => 100,
                'provisioned' => (int)($result['provisioned'] ?? 0),
                'skipped' => (int)($result['skipped'] ?? 0),
                'regenerated' => (int)($result['regenerated'] ?? 0),
                'reader_synced' => !empty($result['reader_synced']),
                'bind_skipped' => !empty($result['bind_skipped']),
            ]);

            PostResponseTaskQueue::enqueue(
                'mail-one-click-setup-done',
                static function (): void {
                },
                0.0,
                \Weline\Framework\Runtime\PostResponseCaptureMode::None
            );
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('一键配置失败'),
                ]);
                $sse->close();
            }
        }

        return '';
    }

    /**
     * SSE：单域一键配置（需管理连接；ensureDomain + contact@ + 域内 sync）。
     * complete() + CaptureMode::None：对齐同步流。
     */
    public function postOneClickDomainSetupStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }
            if (
                !$this->isMailAclAllowed('Weline_Mail::mail_accounts_password')
                && !$this->isMailAclAllowed('Weline_Mail::mail_accounts_manage')
            ) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无权限一键配置本域'),
                ]);
                $sse->close();

                return '';
            }

            $domainId = (int)$this->request->getParam('domain_id', 0);
            $sse->sendEvent('start', [
                'message' => (string)__('开始一键配置本域…'),
                'progress' => 0,
                'domain_id' => $domainId,
            ]);

            /** @var \Weline\Mail\Service\MailOneClickSetupService $setup */
            $setup = ObjectManager::getInstance(\Weline\Mail\Service\MailOneClickSetupService::class);
            $result = $setup->oneClickDomain(
                $domainId,
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                },
                static function (string $email, string $passwordOnce) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('password_once', [
                        'email' => $email,
                        'password_once' => $passwordOnce,
                    ]);
                }
            );

            if (empty($result['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('本域一键配置失败')),
                    'hint' => (string)($result['hint'] ?? ''),
                    'progress' => 100,
                    'domain_id' => $domainId,
                    'provisioned' => (int)($result['provisioned'] ?? 0),
                    'skipped' => (int)($result['skipped'] ?? 0),
                    'regenerated' => (int)($result['regenerated'] ?? 0),
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('本域一键配置完成')),
                'progress' => 100,
                'domain_id' => $domainId,
                'domain_name' => (string)($result['domain_name'] ?? ''),
                'contact_email' => (string)($result['contact_email'] ?? ''),
                'provisioned' => (int)($result['provisioned'] ?? 0),
                'skipped' => (int)($result['skipped'] ?? 0),
                'regenerated' => (int)($result['regenerated'] ?? 0),
                'reader_synced' => !empty($result['reader_synced']),
                'bind_skipped' => !empty($result['bind_skipped']),
            ]);

            PostResponseTaskQueue::enqueue(
                'mail-one-click-domain-setup-done',
                static function (): void {
                },
                0.0,
                \Weline\Framework\Runtime\PostResponseCaptureMode::None
            );
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('本域一键配置失败'),
                ]);
                $sse->close();
            }
        }

        return '';
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
                'default_smtp_account_id' => (int)$domain->getData(
                    MailDomain::schema_fields_DEFAULT_SMTP_ACCOUNT_ID
                ),
            ];
        }
        return $lookup;
    }

    /**
     * @param array<string, mixed>|null $domain
     */
    private static function isFakeDomainLookupEntry(?array $domain): bool
    {
        return (string)($domain[MailDomain::schema_fields_ENGINE] ?? '') === 'fake';
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

        $requestedAccountId = (int)$this->request->getPost('account_id', 0);
        $accountId = $this->resolveSendAsAccountId($requestedAccountId);
        if ($accountId <= 0) {
            return $this->respondFormResult(422, __('请选择已启用的本机企业邮箱账号'));
        }

        $to = trim((string)$this->request->getPost('to_email', ''));
        $subject = trim((string)$this->request->getPost('subject', ''));
        $body = (string)$this->request->getPost('body', '');
        $source = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$this->request->getPost('source', '')))) ?: '';
        $sourceId = max(0, (int)$this->request->getPost('source_id', 0));

        $result = ObjectManager::getInstance(\Weline\Mail\Service\MailComposerService::class)
            ->send($accountId, $to, $subject, $body, $source, $sourceId);

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

        try {
            $outcome = $this->runConfigureCloudflareDns(null);
            return $this->respondFormResult((int)$outcome['code'], (string)$outcome['message']);
        } catch (ResponseTerminateException $terminate) {
            // respondFormResult → redirect() 以 302 终止；不可当成业务失败 flash
            throw $terminate;
        }
    }

    /**
     * SSE：一键/预览 Cloudflare 邮箱 DNS（长请求心跳，避免反代空等超时）。
     * complete()：对齐 Smtp/一键配置流，避免 WLS 捕获冲掉 SSE。
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_dns_cloudflare',
        '配置 Cloudflare 邮箱 DNS',
        'cloud',
        '预览并同步当前企业邮箱域名 DNS（SSE）',
        'Weline_Mail::mail'
    )]
    public function postConfigureCloudflareDnsStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            if (!$this->request->isPost()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)__('无效的请求方法'),
                ]);
                $sse->close();

                return '';
            }

            $apply = (string)$this->request->getPost('apply', '') === '1'
                || (string)$this->request->getParam('apply', '') === '1';
            $sse->sendEvent('start', [
                'message' => (string)(
                    $apply
                        ? __('开始同步 Cloudflare DNS…')
                        : __('开始预览 Cloudflare DNS…')
                ),
                'progress' => 0,
                'apply' => $apply,
            ]);

            $outcome = $this->runConfigureCloudflareDns(
                static function (string $step, string $message, int $current, int $total) use ($sse): void {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $percent = (int)floor(($current / max(1, $total)) * 95);
                    $sse->sendEvent('progress', [
                        'step' => $step,
                        'message' => $message,
                        'current' => $current,
                        'total' => $total,
                        'progress' => min(95, max(1, $percent)),
                    ]);
                    $sse->maybeHeartbeat();
                }
            );

            if (empty($outcome['success'])) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => (string)($outcome['message'] ?? __('Cloudflare DNS 配置失败。')),
                    'progress' => 100,
                    'code' => (int)($outcome['code'] ?? 500),
                ]);
                $sse->close();

                return '';
            }

            $sse->complete([
                'success' => true,
                'message' => (string)($outcome['message'] ?? __('Cloudflare DNS 已同步。')),
                'progress' => 100,
                'apply' => $apply,
            ]);
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('error', [
                    'success' => false,
                    'message' => $e->getMessage() !== ''
                        ? $e->getMessage()
                        : (string)__('Cloudflare DNS 配置失败'),
                ]);
                $sse->close();
            }
        }

        return '';
    }

    /**
     * 同步 Cloudflare 邮箱 DNS（表单与 SSE 共用）。
     *
     * @param (callable(string $step, string $message, int $current, int $total): void)|null $onProgress
     * @return array{success:bool,code:int,message:string}
     */
    private function runConfigureCloudflareDns(?callable $onProgress = null): array
    {
        $emit = static function (
            string $step,
            string $message,
            int $current,
            int $total,
        ) use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            $onProgress($step, $message, $current, $total);
        };

        $domainId = (int)$this->request->getPost('domain_id', 0);
        if ($domainId < 1) {
            $domainId = (int)$this->request->getParam('domain_id', 0);
        }
        $apply = (string)$this->request->getPost('apply', '') === '1'
            || (string)$this->request->getParam('apply', '') === '1';
        $confirmOrigin = (string)$this->request->getPost('confirm_public_origin', '') === '1'
            || (string)$this->request->getParam('confirm_public_origin', '') === '1';

        if ($domainId < 1) {
            return [
                'success' => false,
                'code' => 422,
                'message' => (string)__('邮箱域名 ID 无效。'),
            ];
        }
        if ($apply && !$confirmOrigin) {
            return [
                'success' => false,
                'code' => 422,
                'message' => (string)__('应用前必须确认邮件主机将以 DNS-only 暴露源站公网 IP。'),
            ];
        }

        try {
            $emit('load_domain', (string)__('正在加载邮箱域名…'), 1, 8);
            $domain = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Mail\Model\MailDomain::class
            )->reset()->load($domainId);
            if (!$domain->getId()) {
                return [
                    'success' => false,
                    'code' => 404,
                    'message' => (string)__('邮箱域名不存在。'),
                ];
            }

            $originIp = trim((string)$this->request->getPost('origin_ip', ''));
            if ($originIp === '') {
                $originIp = trim((string)$this->request->getParam('origin_ip', ''));
            }
            if ($originIp === '') {
                $originIp = trim((string)$domain->getData(
                    \Weline\Mail\Model\MailDomain::schema_fields_ORIGIN_IP
                ));
            }
            if ($originIp === '') {
                $emit('resolve_origin', (string)__('正在探测本机邮局公网 IP…'), 2, 8);
                /** @var \Weline\Mail\Service\MailOriginIpResolver $originResolver */
                $originResolver = ObjectManager::getInstance(
                    \Weline\Mail\Service\MailOriginIpResolver::class
                );
                $originIp = $originResolver->resolve('');
            }
            $domainName = (string)$domain->getData(
                \Weline\Mail\Model\MailDomain::schema_fields_DOMAIN_NAME
            );
            /** @var list<array{selector:string,public_key:string,algorithm?:string,stage?:string}> $dkimRecords */
            $dkimRecords = [];
            $emit('dkim', (string)__('正在准备 DKIM 公钥…'), 3, 8);
            /** @var \Weline\Mail\Service\StalwartManagementAdapter $stalwartMgmt */
            $stalwartMgmt = ObjectManager::getInstance(
                \Weline\Mail\Service\StalwartManagementAdapter::class
            );
            if ($stalwartMgmt->hasManagementCredential()) {
                try {
                    $dkimRecords = $stalwartMgmt->listPublishableDkimDnsRecords($domainName);
                } catch (\Throwable) {
                    $dkimRecords = [];
                }
            }

            $selector = trim((string)$this->request->getPost('dkim_selector', ''));
            if ($selector === '') {
                $selector = trim((string)$this->request->getParam('dkim_selector', ''));
            }
            if ($selector === '') {
                $selector = trim((string)$domain->getData(
                    \Weline\Mail\Model\MailDomain::schema_fields_DKIM_SELECTOR
                ));
            }
            if ($selector === '') {
                $selector = 'default';
            }
            $publicKey = trim((string)$this->request->getPost('dkim_public_key', ''));
            if ($publicKey === '') {
                $publicKey = trim((string)$this->request->getParam('dkim_public_key', ''));
            }
            if ($publicKey === '') {
                $publicKey = trim((string)$domain->getData(
                    \Weline\Mail\Model\MailDomain::schema_fields_DKIM_PUBLIC_KEY
                ));
            }

            if ($dkimRecords === []) {
                /** @var \Weline\Mail\Service\MailDkimLocalEnsureService $dkimEnsure */
                $dkimEnsure = ObjectManager::getInstance(
                    \Weline\Mail\Service\MailDkimLocalEnsureService::class
                );
                $ensured = $dkimEnsure->ensure($domainName, $selector, $publicKey);
                $selector = $ensured['selector'];
                $publicKey = $ensured['public_key'];
                $dkimRecords = [[
                    'selector' => $selector,
                    'public_key' => $publicKey,
                    'algorithm' => 'rsa',
                ]];
            } else {
                $primary = $dkimRecords[0];
                foreach ($dkimRecords as $candidate) {
                    if (($candidate['algorithm'] ?? '') === 'rsa') {
                        $primary = $candidate;
                        break;
                    }
                }
                $selector = (string)$primary['selector'];
                $publicKey = (string)$primary['public_key'];
            }

            $emit('build_records', (string)__('正在生成 DNS 清单…'), 4, 8);
            $factory = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Mail\Service\MailDnsRecordFactory::class
            );
            $targets = $factory->build(
                $domainName,
                (string)$domain->getData(\Weline\Mail\Model\MailDomain::schema_fields_HOSTNAME),
                $originIp,
                $dkimRecords,
            );

            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_ORIGIN_IP, $originIp);
            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_DKIM_SELECTOR, $selector);
            $domain->setData(\Weline\Mail\Model\MailDomain::schema_fields_DKIM_PUBLIC_KEY, $publicKey);
            $domain->save();

            $emit(
                'reconcile',
                (string)($apply ? __('正在写入 Cloudflare…') : __('正在预览 Cloudflare…')),
                5,
                8
            );
            /** @var \Weline\Cdn\Api\MailDnsManagerInterface $mailDns */
            $mailDns = ObjectManager::getInstance(\Weline\Cdn\Api\MailDnsManagerInterface::class);
            $result = $mailDns->reconcile(
                (string)$domain->getData(
                    \Weline\Mail\Model\MailDomain::schema_fields_DOMAIN_NAME
                ),
                $targets['desired_records'],
                $targets['dns_only_hosts'],
                $apply,
                $onProgress
            );
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
                return [
                    'success' => false,
                    'code' => 409,
                    'message' => (string)($result['message'] ?? __('Cloudflare DNS 配置失败。')),
                ];
            }

            $poolMsg = '';
            if ($apply) {
                $emit('domain_pool', (string)__('正在同步域名池主机名…'), 7, 8);
                $poolMsg = $this->ensureMailHostnamesInDomainPool(
                    (string)$domain->getData(\Weline\Mail\Model\MailDomain::schema_fields_DOMAIN_NAME),
                    (string)$domain->getData(\Weline\Mail\Model\MailDomain::schema_fields_HOSTNAME),
                    $originIp,
                    is_array($targets['dns_only_hosts'] ?? null) ? $targets['dns_only_hosts'] : [],
                );
            }

            $baseMsg = (string)($result['message'] ?? (
                $apply ? __('Cloudflare DNS 已同步。') : __('Cloudflare DNS 预览完成。')
            ));
            if ($poolMsg !== '') {
                $baseMsg .= ' ' . $poolMsg;
            }

            $emit('done', $baseMsg, 8, 8);

            return [
                'success' => true,
                'code' => 200,
                'message' => $baseMsg,
            ];
        } catch (\DomainException|\InvalidArgumentException $e) {
            return [
                'success' => false,
                'code' => 422,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'code' => 500,
                'message' => (string)__('Cloudflare DNS 配置失败：%{1}', $e->getMessage()),
            ];
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
        if (!in_array($view, ['mailbox', 'accounts', 'domains', 'smtp'], true)) {
            $view = 'accounts';
        }
        $query = ['view' => $view];
        $accountId = max(0, (int)$this->request->getParam('account_id', 0));
        if ($accountId > 0) {
            $query['account'] = $accountId;
        }
        $folder = strtolower(trim((string)$this->request->getParam('folder', '')));
        if (in_array($folder, MailboxFolder::all(), true)) {
            $query['folder'] = $folder;
        }

        return $this->redirect(
            $this->_url->getBackendUrl('weline_mail/backend') . '?' . http_build_query($query)
        );
    }

    private function touchMailMenuSignal(int $accountId, string $messageId, bool $unread): void
    {
        $messageId = trim($messageId);
        $userId = (int)$this->getLoginUserId();
        if ($accountId <= 0 || $messageId === '' || $userId <= 0) {
            return;
        }
        if (!class_exists(\Weline\Mail\Service\Mailbox\MailMenuSignalPublisher::class)) {
            return;
        }
        try {
            $publisher = ObjectManager::getInstance(\Weline\Mail\Service\Mailbox\MailMenuSignalPublisher::class);
            if ($unread) {
                $publisher->markUnread($accountId, $messageId, $userId);
                return;
            }
            $publisher->markRead($accountId, $messageId, $userId);
        } catch (\Throwable) {
        }
    }

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }

    /**
     * 邮箱账号列表：可外投的真实域在前（contact@ 优先），.invalid/.test / fake 殿后。
     *
     * @param list<object> $mailboxAccounts
     * @param array<int, array<string, mixed>> $domainLookup
     * @return list<object>
     */
    private function orderMailboxAccountsPreferReal(array $mailboxAccounts, array $domainLookup): array
    {
        if ($mailboxAccounts === []) {
            return $mailboxAccounts;
        }
        $real = [];
        $fake = [];
        foreach ($mailboxAccounts as $account) {
            if ($this->isMailboxAccountFake($account, $domainLookup)) {
                $fake[] = $account;
            } else {
                $real[] = $account;
            }
        }
        usort($real, static function ($a, $b): int {
            $ea = strtolower(trim((string)$a->getData(MailAccount::schema_fields_EMAIL)));
            $eb = strtolower(trim((string)$b->getData(MailAccount::schema_fields_EMAIL)));
            $ca = str_starts_with($ea, 'contact@') ? 0 : 1;
            $cb = str_starts_with($eb, 'contact@') ? 0 : 1;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }

            return ((int)$a->getId()) <=> ((int)$b->getId());
        });

        return array_values(array_merge($real, $fake));
    }

    /**
     * @param list<object> $mailboxAccounts
     * @param array<int, array<string, mixed>> $domainLookup
     */
    private function firstRealMailboxAccountId(array $mailboxAccounts, array $domainLookup): int
    {
        foreach ($mailboxAccounts as $account) {
            if ($this->isMailboxAccountFake($account, $domainLookup)) {
                continue;
            }
            $id = (int)$account->getId();
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $domainLookup
     */
    private function isMailboxAccountFake(object $account, array $domainLookup): bool
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        $meta = $domainLookup[$domainId] ?? null;
        if (self::isFakeDomainLookupEntry($meta)) {
            return true;
        }
        $domainName = strtolower(trim((string)($meta['domain_name'] ?? '')));

        return $this->isFakeTestDomain($domainName);
    }

    /**
     * DNS 应用成功后：入域名池并申请 HTTPS（经 websites Query，不直连 Websites 模型）。
     *
     * @param list<string> $dnsOnlyHosts
     */
    private function ensureMailHostnamesInDomainPool(
        string $rootDomain,
        string $hostname,
        string $originIp,
        array $dnsOnlyHosts,
    ): string {
        $rootDomain = strtolower(rtrim(trim($rootDomain), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $hostnames = [];
        foreach ($dnsOnlyHosts as $h) {
            $n = strtolower(rtrim(trim((string)$h), '.'));
            if ($n !== '') {
                $hostnames[] = $n;
            }
        }
        if ($hostname !== '') {
            $hostnames[] = $hostname;
        }
        if ($rootDomain !== '') {
            $hostnames[] = 'smtp.' . $rootDomain;
        }
        $hostnames = array_values(array_unique(array_filter($hostnames)));
        if ($hostnames === []) {
            return '';
        }

        try {
            $pool = w_query('websites', 'ensurePublicDnsHostnamesInPool', [
                'root_domain' => $rootDomain,
                'hostnames' => $hostnames,
                'origin_ip' => $originIp,
                'dns_provider' => 'cloudflare',
                'request_certificate' => true,
                'source' => 'mail_dns',
            ]);
            if (!is_array($pool)) {
                return (string)__('域名池同步返回异常。');
            }
            $baseMsg = (($pool['success'] ?? false) === true)
                ? (string)($pool['message'] ?? __('已入域名池并申请证书。'))
                : (string)($pool['message'] ?? __('域名池/证书同步未完成。'));
            try {
                w_query('mail', 'syncSmtpTlsFromDomainPool', [
                    'hostnames' => $hostnames,
                    'root_domain' => $rootDomain,
                    'origin_ip' => $originIp,
                ]);
            } catch (\Throwable $tlsEx) {
                w_log_warning(
                    '[Mail] ' . __('入池后 Stalwart TLS 同步失败：%{1}', [$tlsEx->getMessage()]),
                    [],
                    'mail'
                );
            }

            return $baseMsg;
        } catch (\Throwable $e) {
            w_log_error(
                '[Mail] ' . __('DNS 通后入域名池失败：%{1}', [$e->getMessage()]),
                [],
                'mail'
            );

            return (string)__('DNS 已同步，但入域名池/证书失败：%{1}', [$e->getMessage()]);
        }
    }

    /**
     * CDN 默认 Cloudflare 账户已有可用 api_token（含手动全局 Token）时，邮局一键 DNS 无需再走 OAuth。
     */
    private function isCloudflareDefaultTokenReady(): bool
    {
        if (!class_exists(\Weline\Cdn\Service\AccountManager::class)) {
            return false;
        }
        try {
            /** @var \Weline\Cdn\Service\AccountManager $accounts */
            $accounts = ObjectManager::getInstance(\Weline\Cdn\Service\AccountManager::class);
            $account = $accounts->getDefaultAccount('cloudflare');
            if (!$account instanceof \Weline\Cdn\Model\Account) {
                return false;
            }
            /** @var \Weline\Cdn\Service\CloudflareOAuthService $oauth */
            $oauth = ObjectManager::getInstance(\Weline\Cdn\Service\CloudflareOAuthService::class);
            $credentials = $oauth->credentialsForAccount($account);

            return \Weline\Cdn\Adapter\Cloudflare::hasUsableCredentials($credentials);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 域名页实时探测公网 DNS，供清单打钩（fake 域跳过探测）。
     *
     * @param list<object> $domains
     * @return array<int, array{ok:bool,records:list<array<string,mixed>>}>
     */
    private function buildLiveDnsChecks(array $domains, string $fallbackOriginIp = ''): array
    {
        /** @var DnsRecordAdvisor $advisor */
        $advisor = ObjectManager::getInstance(DnsRecordAdvisor::class);
        /** @var MailOriginIpResolver $originResolver */
        $originResolver = ObjectManager::getInstance(MailOriginIpResolver::class);
        $out = [];
        foreach ($domains as $domain) {
            $id = (int)$domain->getId();
            $name = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            $host = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_HOSTNAME)));
            $engine = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_ENGINE)));
            if ($id <= 0 || $name === '' || $host === '') {
                continue;
            }
            $selector = (string)$domain->getData(MailDomain::schema_fields_DKIM_SELECTOR);
            if ($engine === 'fake' || $this->isFakeTestDomain($name)) {
                $expected = $advisor->expectedRecords($name, $host, $selector);
                foreach ($expected as &$row) {
                    $row['ok'] = true;
                    $row['detail'] = 'fake';
                }
                unset($row);
                $out[$id] = ['ok' => true, 'records' => $expected];
                continue;
            }
            $originIp = $originResolver->resolve((string)$domain->getData(MailDomain::schema_fields_ORIGIN_IP));
            if ($originIp === '') {
                $originIp = trim($fallbackOriginIp);
            }
            $result = $advisor->check(
                $name,
                $host,
                $selector,
                $originIp,
            );
            $out[$id] = [
                'ok' => (bool)$result['ok'],
                'records' => $result['records'],
            ];
        }

        return $out;
    }

    /**
     * @param list<object> $mailboxAccounts
     * @return array{
     *   open:bool,
     *   to:string,
     *   subject:string,
     *   body:string,
     *   source:string,
     *   source_id:int,
     *   account_id:int,
     *   account_locked:bool,
     *   can_pick_account:bool,
     *   customer_id:string,
     *   customer_display:string
     * }
     */
    private function resolveComposeContext(int $selectedAccountId, array $mailboxAccounts): array
    {
        $canPick = $this->canPickAnyLocalMailbox();
        $ownAccountId = $this->resolveOwnLocalAccountId($mailboxAccounts);
        $accountId = $selectedAccountId;
        $accountLocked = !$canPick;
        if (!$canPick && $ownAccountId > 0) {
            $accountId = $ownAccountId;
        }

        $to = trim((string)$this->request->getParam('to', ''));
        if ($to === '') {
            $to = trim((string)$this->request->getParam('compose_to', ''));
        }
        $subject = trim((string)$this->request->getParam('subject', ''));
        if ($subject === '') {
            $subject = trim((string)$this->request->getParam('compose_subject', ''));
        }
        $body = (string)$this->request->getParam('body', '');
        if ($body === '') {
            $body = (string)$this->request->getParam('compose_body', '');
        }
        $open = (string)$this->request->getParam('compose', '') === '1'
            || $to !== ''
            || $subject !== ''
            || $body !== ''
            || trim((string)$this->request->getParam('source', '')) !== '';

        $customerPick = $this->resolveComposeCustomerPick($to);

        return [
            'open' => $open,
            'to' => $customerPick['email'] !== '' ? $customerPick['email'] : $to,
            'subject' => $subject,
            'body' => $body,
            'source' => preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$this->request->getParam('source', '')))) ?: '',
            'source_id' => max(0, (int)$this->request->getParam('source_id', 0)),
            'account_id' => $accountId,
            'account_locked' => $accountLocked,
            'can_pick_account' => $canPick,
            'customer_id' => $customerPick['customer_id'],
            'customer_display' => $customerPick['customer_display'],
        ];
    }

    /**
     * Prefer an existing Customer match when compose deeplink carries an email
     * (or a bare customer id), so the mailbox compose UI can prefill
     * {@see \Weline\Customer\Taglib\AdminCustomerSelect}.
     *
     * @return array{customer_id:string,customer_display:string,email:string}
     */
    private function resolveComposeCustomerPick(string $toHint): array
    {
        $empty = ['customer_id' => '', 'customer_display' => '', 'email' => ''];
        $toHint = trim($toHint);
        if ($toHint === '') {
            return $empty;
        }

        try {
            $picker = ObjectManager::getInstance(
                \Weline\Customer\Service\CustomerAdminPickerService::class
            );
            if (ctype_digit($toHint)) {
                $item = $picker->resolve((int)$toHint);
            } else {
                $found = $picker->search($toHint, 1, 5);
                $item = null;
                foreach (($found['items'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $email = strtolower(trim((string)($row['email'] ?? '')));
                    if ($email !== '' && strcasecmp($email, $toHint) === 0) {
                        $item = $row;
                        break;
                    }
                }
                if ($item === null && isset($found['items'][0]) && is_array($found['items'][0])) {
                    $item = $found['items'][0];
                }
            }
            if (!is_array($item)) {
                return $empty;
            }
            $customerId = (int)($item['customer_id'] ?? $item['value'] ?? 0);
            if ($customerId <= 0) {
                return $empty;
            }

            return [
                'customer_id' => (string)$customerId,
                'customer_display' => trim((string)($item['label'] ?? '')),
                'email' => strtolower(trim((string)($item['email'] ?? ''))),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    private function canPickAnyLocalMailbox(): bool
    {
        return $this->isMailAclAllowed('Weline_Mail::mail_send_as');
    }

    private function isMailAclAllowed(string $source): bool
    {
        $userId = (int)($this->getLoginUserId() ?? 0);
        if ($userId === 1) {
            return true;
        }
        if ($userId <= 0 || $source === '') {
            return false;
        }
        $ctx = \Weline\Backend\Model\BackendUser::getAclContext($userId);
        $roleId = (int)($ctx['role_id'] ?? 0);
        if ($roleId <= 0) {
            return false;
        }

        try {
            return ObjectManager::getInstance(
                \Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface::class
            )->isSourceAllowed($roleId, $source);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<object> $mailboxAccounts
     */
    private function resolveOwnLocalAccountId(array $mailboxAccounts): int
    {
        $email = $this->resolveBackendUserEmail();
        if ($email === '') {
            return 0;
        }
        foreach ($mailboxAccounts as $account) {
            if (strtolower(trim((string)$account->getData(\Weline\Mail\Model\MailAccount::schema_fields_EMAIL))) === $email) {
                return (int)$account->getId();
            }
        }
        $resolved = ObjectManager::getInstance(\Weline\Mail\Service\MailSmtpAccountService::class)
            ->resolveLocalMailboxByEmail($email);
        if (!empty($resolved['local']) && is_array($resolved['mailbox'] ?? null)) {
            return (int)($resolved['mailbox']['account_id'] ?? 0);
        }

        return 0;
    }

    private function resolveBackendUserEmail(): string
    {
        $userId = (int)($this->getLoginUserId() ?? 0);
        if ($userId <= 0) {
            return '';
        }
        try {
            $user = ObjectManager::getInstance(\Weline\Backend\Model\BackendUser::class)->clear()->load($userId);
            if ($user->getId()) {
                return strtolower(trim((string)$user->getEmail()));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveSendAsAccountId(int $requestedAccountId): int
    {
        $smtp = ObjectManager::getInstance(\Weline\Mail\Service\MailSmtpAccountService::class);
        if ($this->canPickAnyLocalMailbox()) {
            return $smtp->getAccountConfig($requestedAccountId) !== null ? $requestedAccountId : 0;
        }
        $ownEmail = $this->resolveBackendUserEmail();
        $resolved = $smtp->resolveLocalMailboxByEmail($ownEmail);
        if (empty($resolved['local']) || !is_array($resolved['mailbox'] ?? null)) {
            return 0;
        }

        return (int)($resolved['mailbox']['account_id'] ?? 0);
    }
}
