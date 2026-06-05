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
        $domains = $domainModel->clear()->select()->fetch()->getItems();

        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        $accounts = $accountModel->clear()->select()->fetch()->getItems();

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
        $this->assign('messages', $messages);
        $this->assign('dns_result', $domain !== '' ? $dnsAdvisor->check($domain, $hostname) : null);
        $this->assign('client_settings', $domain !== '' && $hostname !== '' ? $engine->clientSettings($domain, $hostname) : null);
        return $this->fetch();
    }

    public function postCreateDomain(): string
    {
        $domain = strtolower(trim((string)$this->request->getParam('domain_name', '')));
        $hostname = strtolower(trim((string)$this->request->getParam('hostname', '')));
        $engine = strtolower(trim((string)$this->request->getParam('engine', 'stalwart')));
        $quota = max(128, (int)$this->request->getParam('default_quota_mb', 1024));

        if ($domain === '' || $hostname === '') {
            return $this->fetchJson(['code' => 400, 'msg' => __('域名和邮件主机名不能为空')]);
        }

        if (!in_array($engine, ['stalwart', 'fake'], true)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('邮件引擎参数无效')]);
        }

        if ($engine === 'fake' && !$this->isFakeTestDomain($domain)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('Fake 测试引擎只允许 .invalid 或 .test 域名')]);
        }

        /** @var MailDomain $model */
        $model = ObjectManager::getInstance(MailDomain::class);
        $existing = $model->clear()->where(MailDomain::schema_fields_DOMAIN_NAME, $domain)->find()->fetch();
        if ($existing->getId()) {
            return $this->fetchJson(['code' => 409, 'msg' => __('邮箱域名已存在')]);
        }

        $model->clear()
            ->setData(MailDomain::schema_fields_DOMAIN_NAME, $domain)
            ->setData(MailDomain::schema_fields_HOSTNAME, $hostname)
            ->setData(MailDomain::schema_fields_ENGINE, $engine)
            ->setData(MailDomain::schema_fields_STATUS, 'pending')
            ->setData(MailDomain::schema_fields_DEFAULT_QUOTA_MB, $quota)
            ->setData(MailDomain::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(MailDomain::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->fetchJson(['code' => 200, 'msg' => __('邮箱域名已创建')]);
    }

    public function postCreateAccount(): string
    {
        $domainId = (int)$this->request->getParam('domain_id', 0);
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $displayName = trim((string)$this->request->getParam('display_name', ''));
        $quota = max(128, (int)$this->request->getParam('quota_mb', 1024));

        if ($domainId <= 0 || $email === '') {
            return $this->fetchJson(['code' => 400, 'msg' => __('域名和邮箱地址不能为空')]);
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('邮箱域名不存在')]);
        }

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        if ($domainName === '' || !str_ends_with($email, '@' . $domainName)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('邮箱地址必须属于所选域名')]);
        }

        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $existing = $model->clear()->where(MailAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            return $this->fetchJson(['code' => 409, 'msg' => __('邮箱账号已存在')]);
        }

        $model->clear()
            ->setData(MailAccount::schema_fields_DOMAIN_ID, $domainId)
            ->setData(MailAccount::schema_fields_EMAIL, $email)
            ->setData(MailAccount::schema_fields_DISPLAY_NAME, $displayName)
            ->setData(MailAccount::schema_fields_QUOTA_MB, $quota)
            ->setData(MailAccount::schema_fields_STATUS, 'pending')
            ->setData(MailAccount::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->fetchJson(['code' => 200, 'msg' => __('邮箱账号已创建')]);
    }

    public function postSetDomainStatus(): string
    {
        $domainId = (int)$this->request->getParam('domain_id', 0);
        $status = (string)$this->request->getParam('status', '');

        if ($domainId <= 0 || !in_array($status, ['active', 'pending', 'suspended'], true)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('域名状态参数无效')]);
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('邮箱域名不存在')]);
        }

        $domain->setData(MailDomain::schema_fields_STATUS, $status)
            ->setData(MailDomain::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->fetchJson(['code' => 200, 'msg' => __('邮箱域名状态已更新')]);
    }

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }
}
