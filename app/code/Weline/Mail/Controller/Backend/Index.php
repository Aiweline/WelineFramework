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

class Index extends BackendController
{
    public function index(): string
    {
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        /** @var DnsRecordAdvisor $dnsAdvisor */
        $dnsAdvisor = ObjectManager::getInstance(DnsRecordAdvisor::class);

        $domain = trim((string)$this->request->getParam('domain', ''));
        $hostname = trim((string)$this->request->getParam('hostname', $domain !== '' ? 'mail.' . $domain : ''));

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

        /** @var MailMessage $messageModel */
        $messageModel = ObjectManager::getInstance(MailMessage::class);
        $messages = $messageModel->clear()
            ->order(MailMessage::schema_fields_ID, 'DESC')
            ->limit(20)
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('environment', $engine->checkEnvironment());
        $this->assign('install_plan', $engine->buildInstallPlan());
        $this->assign('domains', $domains);
        $this->assign('accounts', $accounts);
        $this->assign('domain_lookup', $domainLookup);
        $this->assign('domain_account_counts', $domainAccountCounts);
        $this->assign('website_domain_options', $this->loadWebsiteDomainOptions($domains, $domainAccountCounts));
        $this->assign('messages', $messages);
        $this->assign('dns_result', $domain !== '' ? $dnsAdvisor->check($domain, $hostname) : null);
        $this->assign('client_settings', $domain !== '' && $hostname !== '' ? $engine->clientSettings($domain, $hostname) : null);
        return $this->fetch();
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
        $domainId = (int)$this->request->getParam('domain_id', 0);
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $localPart = strtolower(trim((string)$this->request->getParam('local_part', '')));
        $displayName = trim((string)$this->request->getParam('display_name', ''));
        $customerId = max(0, (int)$this->request->getParam('customer_id', 0));
        $quota = max(128, (int)$this->request->getParam('quota_mb', 1024));

        if ($domainId <= 0) {
            return $this->respondFormResult(400, __('请选择邮箱域名'));
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return $this->respondFormResult(404, __('邮箱域名不存在'));
        }

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        if ($email === '' && $localPart !== '') {
            $email = $localPart . '@' . $domainName;
        }
        if ($email === '') {
            return $this->respondFormResult(400, __('邮箱地址不能为空'));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respondFormResult(400, __('邮箱地址格式不正确'));
        }
        if ($domainName === '' || !str_ends_with($email, '@' . $domainName)) {
            return $this->respondFormResult(400, __('邮箱地址必须属于所选域名'));
        }

        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $existing = $model->clear()->where(MailAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            return $this->respondFormResult(409, __('邮箱账号已存在'));
        }

        $engine = (string)$domain->getData(MailDomain::schema_fields_ENGINE);
        $status = strtolower(trim((string)$this->request->getParam('status', '')));
        if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
            $status = $engine === 'fake' && $this->isFakeTestDomain($domainName) ? 'active' : 'pending';
        }
        $now = date('Y-m-d H:i:s');
        $model->clear()
            ->setData(MailAccount::schema_fields_DOMAIN_ID, $domainId)
            ->setData(MailAccount::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(MailAccount::schema_fields_EMAIL, $email)
            ->setData(MailAccount::schema_fields_DISPLAY_NAME, $displayName)
            ->setData(MailAccount::schema_fields_QUOTA_MB, $quota)
            ->setData(MailAccount::schema_fields_STATUS, $status)
            ->setData(MailAccount::schema_fields_CREATED_AT, $now)
            ->setData(MailAccount::schema_fields_UPDATED_AT, $now)
            ->save();

        return $this->respondFormResult(200, __('邮箱账号已创建'));
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

        return $this->redirect($this->_url->getBackendUrl('weline_mail/backend'));
    }

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }
}
