<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/2 22:45:30
 */

namespace Weline\Smtp\Helper;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpSendLog;

class SmtpSender extends \Weline\Framework\App\Helper
{
    /**
     * @var \Weline\Smtp\Helper\Data
     */
    private Data $data;

    public function __construct(
        Data $data
    )
    {
        $this->data = $data;
    }


    public function getHelper(): Data
    {
        return $this->data;
    }

    /**
     * @DESC          # 发送邮件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/11/2 22:50
     * 参数区：
     *
     * @param string|array $from 发送者：demo@demo.com | 带名字的发送者：['email'=>'demo@demo.com','name'=>'Sender']
     * @param string|array $to 单个收件人：demo@demo.com | 带名字的收件人：['email'=>'demo@demo.com','name'=>'Sender'] | 多个收件人：[['email'=>'demo1@demo.com',
     *                                 'name'=>'Sender1'], ['email'=>'demo2@demo.com','name'=>'Sender2']]
     * @param string $subject 字符串：This is a test subject.
     * @param string $content 字符串：This is a test message content.
     * @param string $alt 字符串：Just is a test alt.
     * @param string|array $attachment 单个附件：/data/imgg/test.jpg | 带名字的附件：['path'=>'/data/imgg/test.jpg','name'=>'test.jpg'] | 多个附件:
     *                                 [['path'=>'/data/imgg/test1.jpg','name'=>'test1.jpg'],['path'=>'/data/imgg/test2.jpg','name'=>'test2.jpg']]
     * @param string|array $reply_to 单个回复：reply_to@demo.com | 带名字的回复：['email'=>'reply_to@demo.com','name'=>'Reply to'] |
     *                                 一次性回复多个邮件：[['email'=>'reply_to1@demo.com','name'=>'Reply to 1'], ['email'=>'reply_to2@demo.com',
     *                                 'name'=>'Reply to 2']]
     * @param string|array $cc 单个抄送：cc@demo.com | 带名字的抄送：['email'=>'cc@demo.com','name'=>'CC'] | 一次性抄送给多个邮件：[['email'=>'cc1@demo
     *                                 .com','name'=>'CC 1'], ['email'=>'cc2@demo.com','name'=>'CC 2']]
     * @param string|array $bcc 单个密送：bcc@demo.com | 带名字的抄送：['email'=>'bcc@demo.com','name'=>'BCC'] | 一次性抄送给多个邮件：[['email'=>'bcc1@demo
     *                                 .com','name'=>'BCC 1'], ['email'=>'bcc2@demo.com','name'=>'BCC 2']]
     * @param string $module 模型：Weline_Smtp。默认使用Weline_Smtp模组下的配置，你可以使用Weline\Smtp\Helper\Data设置或获取对应模组的Smtp配置
     *
     * @return bool
     * @throws \PHPMailer\PHPMailer\Exception
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function sender(
        string|array $from,
        string|array $to,
        string       $subject,
        string       $content,
        string       $alt = '',
        string|array $attachment = '',
        string|array $reply_to = '',
        string|array $cc = '',
        string|array $bcc = '',
        string       $module = 'Weline_Smtp'
    ): bool
    {
        $mail = $this->createMailer();
        $this->configureTransport(
            $mail,
            (string) $this->data->get($this->data::smtp_host, $module),
            (string) $this->data->get($this->data::smtp_username, $module),
            (string) $this->data->get($this->data::smtp_password, $module),
            (int) $this->data->get($this->data::smtp_port, $module),
            $this->data->get($this->data::smtp_auth, $module),
            $this->data->get($this->data::smtp_secure, $module)
        );
        $this->prepareMessage($mail, $from, $to, $subject, $content, $alt, $attachment, $reply_to, $cc, $bcc);
        $mail->send();
        $this->writeSendLog($mail, $module);
        return true;
    }

    /**
     * 使用显式配置数组发送（用于多发件人按 code 选择）
     *
     * @param array $config 必须含 smtp_host, smtp_port, smtp_username, smtp_password，可选 smtp_secure/smtp_auth
     */
    public function sendWithConfig(
        string|array $from,
        string|array $to,
        string $subject,
        string $content,
        string $alt = '',
        string|array $attachment = '',
        string|array $reply_to = '',
        string|array $cc = '',
        string|array $bcc = '',
        array $config = [],
        string $module = 'Weline_Smtp'
    ): bool {
        $host = trim((string)($config['smtp_host'] ?? ''));
        $username = trim((string)($config['smtp_username'] ?? ''));
        if ($host === '' || $username === '') {
            throw new Exception(__('发件人配置不完整：缺少 host 或 username'));
        }
        $mail = $this->createMailer();
        $this->configureTransport(
            $mail,
            $host,
            $username,
            (string) ($config['smtp_password'] ?? ''),
            (int) ($config['smtp_port'] ?? 465),
            $config['smtp_auth'] ?? '1',
            $config['smtp_secure'] ?? '1'
        );
        $this->prepareMessage($mail, $from, $to, $subject, $content, $alt, $attachment, $reply_to, $cc, $bcc);
        $mail->send();
        $this->writeSendLog($mail, $module, $config);
        return true;
    }

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->addCustomHeader('charset', 'UTF-8');
        $mail->addCustomHeader('Content-Transfer-Encoding', '8Bit');
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        return $mail;
    }

    private function configureTransport(
        PHPMailer $mail,
        string $host,
        string $username,
        string $password,
        int $port,
        mixed $smtpAuth,
        mixed $smtpSecure
    ): void {
        if ($host === '') {
            throw new Exception(__('SMTP Host 未配置'));
        }
        if ($username === '') {
            throw new Exception(__('SMTP 用户名未配置'));
        }
        $mail->Host = $host;
        $mail->SMTPAuth = $this->normalizeSmtpAuth($smtpAuth);
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port > 0 ? $port : 465;
        $mail->SMTPSecure = $this->resolveSmtpSecure($smtpSecure, $mail->Port);
    }

    private function normalizeSmtpAuth(mixed $smtpAuth): bool
    {
        $value = strtolower(trim((string) $smtpAuth));
        return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
    }

    private function resolveSmtpSecure(mixed $smtpSecure, int $port): string
    {
        $value = strtolower(trim((string) $smtpSecure));
        return match (true) {
            in_array($value, ['ssl', 'smtps', '1', 'true', 'on', 'yes', '465'], true) => PHPMailer::ENCRYPTION_SMTPS,
            in_array($value, ['tls', 'starttls', '2', '587'], true) => PHPMailer::ENCRYPTION_STARTTLS,
            in_array($value, ['none', 'off', 'false', '0', ''], true) => $port === 587 && $value === '0'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : '',
            $port === 465 => PHPMailer::ENCRYPTION_SMTPS,
            $port === 587 => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
    }

    private function prepareMessage(
        PHPMailer $mail,
        string|array $from,
        string|array $to,
        string $subject,
        string $content,
        string $alt = '',
        string|array $attachment = '',
        string|array $replyTo = '',
        string|array $cc = '',
        string|array $bcc = ''
    ): void {
        $this->applyFrom($mail, $from);
        $this->addEmailEntries($mail, $to, static fn(string $email, string $name = '') => $mail->addAddress($email, $name));
        if (!$mail->getToAddresses()) {
            throw new Exception(__('接受者邮箱为空：请正确设置接收邮箱！'));
        }
        $this->addEmailEntries($mail, $replyTo, static fn(string $email, string $name = '') => $mail->addReplyTo($email, $name));
        $this->addEmailEntries($mail, $cc, static fn(string $email, string $name = '') => $mail->addCC($email, $name));
        $this->addEmailEntries($mail, $bcc, static fn(string $email, string $name = '') => $mail->addBCC($email, $name));
        $this->addAttachments($mail, $attachment);
        $mail->Subject = $subject;
        $mail->Body = $content;
        $mail->AltBody = $alt;
        $mail->isHTML(true);
    }

    private function applyFrom(PHPMailer $mail, string|array $from): void
    {
        if (is_string($from)) {
            if (trim($from) === '') {
                throw new Exception(__('发送者邮箱为空：请正确设置发件邮箱！'));
            }
            $mail->setFrom($from);
            return;
        }

        $fromEmail = trim((string) ($from['email'] ?? ''));
        if ($fromEmail === '') {
            throw new Exception(__('发送者邮箱为空：请正确设置发件邮箱！'));
        }
        $mail->setFrom($fromEmail, (string) ($from['name'] ?? ''));
    }

    private function addEmailEntries(PHPMailer $mail, string|array $emails, callable $adder): void
    {
        if ($emails === '' || $emails === []) {
            return;
        }
        if (is_string($emails)) {
            $adder($emails);
            return;
        }
        if (isset($emails['email'])) {
            $adder((string) $emails['email'], (string) ($emails['name'] ?? ''));
            return;
        }
        foreach ($emails as $email) {
            if (is_array($email) && isset($email['email'])) {
                $adder((string) $email['email'], (string) ($email['name'] ?? ''));
            } elseif (is_string($email)) {
                $adder($email);
            }
        }
    }

    private function addAttachments(PHPMailer $mail, string|array $attachment): void
    {
        if ($attachment === '' || $attachment === []) {
            return;
        }
        if (is_string($attachment)) {
            $mail->addAttachment($attachment);
            return;
        }
        $attachments = isset($attachment['path']) ? [$attachment] : $attachment;
        foreach ($attachments as $attach) {
            if (!is_array($attach) || empty($attach['path'])) {
                continue;
            }
            $mail->addAttachment((string) $attach['path'], (string) ($attach['name'] ?? ''));
        }
    }

    private function writeSendLog(PHPMailer $mail, string $module, ?array $config = null): void
    {
        /** @var \Weline\Smtp\Model\SmtpSendLog $sendLog */
        $sendLog = ObjectManager::getInstance(SmtpSendLog::class);
        try {
            $sendLog->setData($sendLog::schema_fields_FROM_EMAIL, $mail->From)
                ->setData($sendLog::schema_fields_SENDER_NAME, $mail->FromName)
                ->setData($sendLog::schema_fields_TO_EMAIL, json_encode($mail->getToAddresses(), JSON_UNESCAPED_UNICODE))
                ->setData($sendLog::schema_fields_REPLY_TO, json_encode($mail->getReplyToAddresses(), JSON_UNESCAPED_UNICODE))
                ->setData($sendLog::schema_fields_SUBJECT, $mail->Subject)
                ->setData($sendLog::schema_fields_CONTENT, $mail->Body)
                ->setData($sendLog::schema_fields_ALT, $mail->AltBody)
                ->setData($sendLog::schema_fields_ATTACHMENT, json_encode($mail->getAttachments(), JSON_UNESCAPED_UNICODE))
                ->setData($sendLog::schema_fields_CC, json_encode($mail->getCcAddresses(), JSON_UNESCAPED_UNICODE))
                ->setData($sendLog::schema_fields_BCC, json_encode($mail->getBccAddresses(), JSON_UNESCAPED_UNICODE))
                ->setData($sendLog::schema_fields_IS_HTML, 1)
                ->setData($sendLog::schema_fields_PROXY, $config !== null ? ($config['smtp_username'] ?? '') : $this->data->get($this->data::smtp_username, $module))
                ->setData($sendLog::schema_fields_MODULE, $module)
                ->save();
        } catch (\ReflectionException|Exception|ModelException $e) {
            if (DEV) {
                throw new Exception($e->getMessage());
            }
        }
    }
}
