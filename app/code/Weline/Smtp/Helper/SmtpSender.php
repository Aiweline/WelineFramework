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
    private PHPMailer $mail;

    public function __construct(
        Data $data
    )
    {
        $this->data = $data;
        //创建实例；传递“true”将启用异常
        $this->mail = new PHPMailer(true);
        $this->mail->addCustomHeader('charset', 'UTF-8');
        $this->mail->addCustomHeader('Content-Transfer-Encoding', '8Bit');
        $this->mail->SMTPDebug = SMTP::DEBUG_SERVER;                   //Enable verbose debug output
        $this->mail->isSMTP();                                         //使用SMTP发送
        $this->mail->CharSet = 'UTF-8';
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
        //服务器设置
        $this->mail->Host = $this->data->get($this->data::smtp_host, $module);

        //设置要发送的SMTP服务器
        $this->mail->SMTPAuth = true;                                                                                                                                                                                                                                                                                                                                                                     //Enable SMTP authentication
        $this->mail->Username = $this->data->get($this->data::smtp_username, $module);
        //SMTP 用户名
        $this->mail->Password = $this->data->get($this->data::smtp_password, $module);
        //SMTP 密码
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;                                                                                                                                                                                                                                                                                                                                            //启用隐式TLS加密
        $this->mail->Port = $this->data->get($this->data::smtp_port, $module);
        //要连接的TCP端口；如果已设置`SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS，请使用587`

        // 发送者
        if (is_string($from)) {
            $this->mail->setFrom($from);
        } else {
            $this->mail->setFrom($from['email'], $from['name'] ?? '');
        }
        // 接受者
        if (is_string($to)) {
            $this->mail->addAddress($to);               // 名字可选
        } else {
            if (isset($to['email'])) {
                $this->mail->addAddress($to['email'], $to['name'] ?? '');               //Name is optional
            } else {
                foreach ($to as $to_email) {
                    if (isset($to_email['email'])) {
                        $this->mail->addAddress($to_email['email'], $to_email['name'] ?? '');               //Name is optional
                    } elseif (is_string($to_email)) {
                        $this->mail->addAddress($to_email);               //Name is optional
                    }
                }
            }
        }
        if (!$this->mail->getToAddresses()) {
            throw new Exception(__('接受者邮箱为空：请正确设置接收邮箱！'));
        }

        // 回复地址
        if ($reply_to) {
            if (is_string($reply_to)) {
                $this->mail->addReplyTo($reply_to);               //Name is optional
            } else {
                if (isset($reply_to['email'])) {
                    $this->mail->addReplyTo($reply_to['email'], $reply_to['name'] ?? '');               //Name is optional
                } else {
                    foreach ($reply_to as $reply_to_email) {
                        if (isset($reply_to_email['email'])) {
                            $this->mail->addReplyTo($reply_to_email['email'], $reply_to_email['name'] ?? '');               //Name is optional
                        }
                    }
                }
            }
        }
        // 抄送
        if ($cc) {
            if (is_string($cc)) {
                $this->mail->addCC($cc);               //Name is optional
            } else {
                if (isset($cc['email'])) {
                    $this->mail->addCC($cc['email'], $cc['name'] ?? '');               //Name is optional
                } else {
                    foreach ($cc as $cc_email) {
                        if (isset($cc_email['email'])) {
                            $this->mail->addCC($cc_email['email'], $cc_email['name'] ?? '');               //Name is optional
                        }
                    }
                }
            }
        }
        // 密送
        if ($bcc) {
            if (is_string($bcc)) {
                $this->mail->addBCC($bcc);               //Name is optional
            } else {
                if (isset($bcc['email'])) {
                    $this->mail->addBCC($bcc['email'], $bcc['name'] ?? '');               //Name is optional
                } else {
                    foreach ($bcc as $bcc_email) {
                        if (isset($bcc_email['email'])) {
                            $this->mail->addBCC($bcc_email['email'], $bcc_email['name'] ?? '');               //Name is optional
                        }
                    }
                }
            }
        }

        // 附件
        if ($attachment) {
            if (is_string($attachment)) {
                $this->mail->addAttachment($attachment);
            } else {
                foreach ($attachment as $attach) {
                    $this->mail->addAttachment($attach['path'], $attach['name'] ?? '');
                }
            }
        }
        // 内容
        $this->mail->Subject = $subject;
        $this->mail->Body = $content;
        $this->mail->AltBody = $alt;
        $this->mail->isHTML(true);  // Html格式发送邮件
        $this->mail->send();
        $this->writeSendLog($module);
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
        $this->mail->Host = $host;
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $username;
        $this->mail->Password = (string)($config['smtp_password'] ?? '');
        $this->mail->Port = (int)($config['smtp_port'] ?? 465);
        $secure = (string)($config['smtp_secure'] ?? '1');
        $this->mail->SMTPSecure = $secure === '0' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;

        if (is_string($from)) {
            $this->mail->setFrom($from);
        } else {
            $this->mail->setFrom($from['email'], $from['name'] ?? '');
        }
        if (is_string($to)) {
            $this->mail->addAddress($to);
        } else {
            if (isset($to['email'])) {
                $this->mail->addAddress($to['email'], $to['name'] ?? '');
            } else {
                foreach ($to as $to_email) {
                    if (is_array($to_email) && isset($to_email['email'])) {
                        $this->mail->addAddress($to_email['email'], $to_email['name'] ?? '');
                    } elseif (is_string($to_email)) {
                        $this->mail->addAddress($to_email);
                    }
                }
            }
        }
        if (!$this->mail->getToAddresses()) {
            throw new Exception(__('接受者邮箱为空：请正确设置接收邮箱！'));
        }
        if ($reply_to) {
            if (is_string($reply_to)) {
                $this->mail->addReplyTo($reply_to);
            } elseif (isset($reply_to['email'])) {
                $this->mail->addReplyTo($reply_to['email'], $reply_to['name'] ?? '');
            }
        }
        if ($cc) {
            if (is_string($cc)) {
                $this->mail->addCC($cc);
            } elseif (isset($cc['email'])) {
                $this->mail->addCC($cc['email'], $cc['name'] ?? '');
            }
        }
        if ($bcc) {
            if (is_string($bcc)) {
                $this->mail->addBCC($bcc);
            } elseif (isset($bcc['email'])) {
                $this->mail->addBCC($bcc['email'], $bcc['name'] ?? '');
            }
        }
        if ($attachment) {
            if (is_string($attachment)) {
                $this->mail->addAttachment($attachment);
            } else {
                foreach (is_array($attachment) && isset($attachment['path']) ? [$attachment] : (array)$attachment as $a) {
                    $this->mail->addAttachment($a['path'] ?? '', $a['name'] ?? '');
                }
            }
        }
        $this->mail->Subject = $subject;
        $this->mail->Body = $content;
        $this->mail->AltBody = $alt;
        $this->mail->isHTML(true);
        $this->mail->send();
        $this->writeSendLog($module, $config);
        return true;
    }

    private function writeSendLog(string $module, ?array $config = null): void
    {
        /** @var \Weline\Smtp\Model\SmtpSendLog $sendLog */
        $sendLog = ObjectManager::getInstance(SmtpSendLog::class);
        try {
            $sendLog->setData($sendLog::schema_fields_FROM_EMAIL, $this->mail->From)
                ->setData($sendLog::schema_fields_SENDER_NAME, $this->mail->FromName)
                ->setData($sendLog::schema_fields_TO_EMAIL, json_encode($this->mail->getToAddresses()))
                ->setData($sendLog::schema_fields_REPLY_TO, json_encode($this->mail->getReplyToAddresses()))
                ->setData($sendLog::schema_fields_SUBJECT, $this->mail->Subject)
                ->setData($sendLog::schema_fields_CONTENT, $this->mail->Body)
                ->setData($sendLog::schema_fields_ALT, $this->mail->AltBody)
                ->setData($sendLog::schema_fields_ATTACHMENT, json_encode($this->mail->getAttachments()))
                ->setData($sendLog::schema_fields_CC, json_encode($this->mail->getCcAddresses()))
                ->setData($sendLog::schema_fields_BCC, json_encode($this->mail->getBccAddresses()))
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
