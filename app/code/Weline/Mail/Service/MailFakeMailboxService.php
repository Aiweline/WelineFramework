<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Model\MailMessage;

class MailFakeMailboxService
{
    private const FOLDER_INBOX = 'inbox';
    private const FOLDER_SENT = 'sent';

    /**
     * @param MailAccount[] $accounts
     * @return MailAccount[]
     */
    public function getUsableAccounts(array $accounts): array
    {
        $usable = [];
        foreach ($accounts as $account) {
            if ($this->isUsableFakeAccount($account)) {
                $usable[] = $account;
            }
        }

        return $usable;
    }

    /**
     * @return MailMessage[]
     */
    public function getMessagesForCustomer(int $customerId, int $accountId, string $folder): array
    {
        if (!in_array($folder, [self::FOLDER_INBOX, self::FOLDER_SENT], true)) {
            return [];
        }

        $account = $this->loadOwnedAccount($customerId, $accountId);
        if (!$account || !$this->isUsableFakeAccount($account)) {
            return [];
        }

        /** @var MailMessage $messageModel */
        $messageModel = ObjectManager::getInstance(MailMessage::class);

        return $messageModel->clear()
            ->where(MailMessage::schema_fields_ACCOUNT_ID, $accountId)
            ->where(MailMessage::schema_fields_FOLDER, $folder)
            ->order(MailMessage::schema_fields_ID, 'DESC')
            ->limit(20)
            ->select()
            ->fetch()
            ->getItems();
    }

    public function send(int $customerId, int $accountId, string $toEmail, string $subject, string $body): array
    {
        $account = $this->loadOwnedAccount($customerId, $accountId);
        if (!$account || !$this->isUsableFakeAccount($account)) {
            return ['success' => false, 'message' => __('请选择已启用的测试邮箱账号')];
        }

        $toEmail = strtolower(trim($toEmail));
        $subject = trim($subject);
        $body = trim($body);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => __('收件邮箱格式不正确')];
        }

        if ($subject === '' || $body === '') {
            return ['success' => false, 'message' => __('主题和正文不能为空')];
        }

        $fromEmail = (string)$account->getData(MailAccount::schema_fields_EMAIL);
        $now = date('Y-m-d H:i:s');
        $this->saveMessage($accountId, self::FOLDER_SENT, $fromEmail, $toEmail, $subject, $body, true, 'sent', $now);

        $recipient = $this->loadAccountByEmail($toEmail);
        $deliveredLocally = false;
        if ($recipient && $this->isUsableFakeAccount($recipient)) {
            $this->saveMessage((int)$recipient->getId(), self::FOLDER_INBOX, $fromEmail, $toEmail, $subject, $body, false, 'delivered', $now);
            $deliveredLocally = true;
        }

        return [
            'success' => true,
            'message' => $deliveredLocally ? __('测试邮件已发送并投递到本地收件箱') : __('测试邮件已写入发件箱'),
        ];
    }

    public function receiveTest(int $customerId, int $accountId): array
    {
        $account = $this->loadOwnedAccount($customerId, $accountId);
        if (!$account || !$this->isUsableFakeAccount($account)) {
            return ['success' => false, 'message' => __('请选择已启用的测试邮箱账号')];
        }

        $domain = $this->loadDomainForAccount($account);
        if (!$domain) {
            return ['success' => false, 'message' => __('邮箱域名不存在')];
        }

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        $toEmail = (string)$account->getData(MailAccount::schema_fields_EMAIL);
        $now = date('Y-m-d H:i:s');
        $this->saveMessage(
            (int)$account->getId(),
            self::FOLDER_INBOX,
            'postmaster@' . $domainName,
            $toEmail,
            __('测试收件：%{1}', [$now]),
            __('这是一封 fake 邮件服务生成的收件测试邮件，用于验证收件箱业务链路。'),
            false,
            'delivered',
            $now
        );

        return ['success' => true, 'message' => __('测试收件已写入收件箱')];
    }

    public function sendFromAccount(int $accountId, string|array $to, string $subject, string $body): array
    {
        if ($accountId <= 0) {
            return ['success' => false, 'message' => __('请选择已启用的测试邮箱账号')];
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId() || !$this->isUsableFakeAccount($account)) {
            return ['success' => false, 'message' => __('请选择已启用的测试邮箱账号')];
        }

        $recipients = $this->normalizeRecipients($to);
        $subject = trim($subject);
        $body = trim($body);

        if ($recipients === []) {
            return ['success' => false, 'message' => __('收件邮箱格式不正确')];
        }

        if ($subject === '' || $body === '') {
            return ['success' => false, 'message' => __('主题和正文不能为空')];
        }

        $fromEmail = (string)$account->getData(MailAccount::schema_fields_EMAIL);
        $toEmailList = implode(', ', $recipients);
        $now = date('Y-m-d H:i:s');
        $this->saveMessage($accountId, self::FOLDER_SENT, $fromEmail, $toEmailList, $subject, $body, true, 'sent', $now);

        $deliveredLocally = false;
        foreach ($recipients as $recipientEmail) {
            $recipient = $this->loadAccountByEmail($recipientEmail);
            if ($recipient && $this->isUsableFakeAccount($recipient)) {
                $this->saveMessage((int)$recipient->getId(), self::FOLDER_INBOX, $fromEmail, $recipientEmail, $subject, $body, false, 'delivered', $now);
                $deliveredLocally = true;
            }
        }

        return [
            'success' => true,
            'message' => $deliveredLocally ? __('测试邮件已发送并投递到本地收件箱') : __('测试邮件已写入发件箱'),
        ];
    }

    public function isUsableFakeAccount(MailAccount $account): bool
    {
        if ((string)$account->getData(MailAccount::schema_fields_STATUS) !== 'active') {
            return false;
        }

        $domain = $this->loadDomainForAccount($account);
        if (!$domain) {
            return false;
        }

        /** @var MailCustomerAccountService $accountService */
        $accountService = ObjectManager::getInstance(MailCustomerAccountService::class);
        return $accountService->isFakeDomain($domain);
    }

    private function loadOwnedAccount(int $customerId, int $accountId): ?MailAccount
    {
        if ($customerId <= 0 || $accountId <= 0) {
            return null;
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId() || (int)$account->getData(MailAccount::schema_fields_CUSTOMER_ID) !== $customerId) {
            return null;
        }

        return $account;
    }

    private function loadAccountByEmail(string $email): ?MailAccount
    {
        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)
            ->clear()
            ->where(MailAccount::schema_fields_EMAIL, $email)
            ->find()
            ->fetch();

        return $account->getId() ? $account : null;
    }

    private function loadDomainForAccount(MailAccount $account): ?MailDomain
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        if ($domainId <= 0) {
            return null;
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        return $domain->getId() ? $domain : null;
    }

    private function saveMessage(
        int $accountId,
        string $folder,
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $body,
        bool $isRead,
        string $deliveryStatus,
        string $createdAt
    ): void {
        /** @var MailMessage $message */
        $message = ObjectManager::getInstance(MailMessage::class);
        $message->clear()
            ->setData(MailMessage::schema_fields_ACCOUNT_ID, $accountId)
            ->setData(MailMessage::schema_fields_FOLDER, $folder)
            ->setData(MailMessage::schema_fields_FROM_EMAIL, $fromEmail)
            ->setData(MailMessage::schema_fields_TO_EMAIL, $toEmail)
            ->setData(MailMessage::schema_fields_SUBJECT, $this->limitSubject($subject))
            ->setData(MailMessage::schema_fields_BODY, $body)
            ->setData(MailMessage::schema_fields_IS_READ, $isRead ? 1 : 0)
            ->setData(MailMessage::schema_fields_DELIVERY_STATUS, $deliveryStatus)
            ->setData(MailMessage::schema_fields_CREATED_AT, $createdAt)
            ->save();
    }

    private function limitSubject(string $subject): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($subject, 0, 180);
        }

        return substr($subject, 0, 180);
    }

    /**
     * @return string[]
     */
    private function normalizeRecipients(string|array $to): array
    {
        $emails = [];
        if (is_string($to)) {
            $emails[] = $to;
        } elseif (isset($to['email'])) {
            $emails[] = (string)$to['email'];
        } else {
            foreach ($to as $entry) {
                if (is_array($entry) && isset($entry['email'])) {
                    $emails[] = (string)$entry['email'];
                } elseif (is_string($entry)) {
                    $emails[] = $entry;
                }
            }
        }

        $valid = [];
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $email;
            }
        }

        return array_values(array_unique($valid));
    }
}
