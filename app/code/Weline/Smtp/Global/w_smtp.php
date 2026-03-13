<?php
declare(strict_types=1);

/**
 * Module: Weline_Smtp
 * SMTP 邮件发送全局函数
 */

if (!function_exists('w_smtp')) {
    /**
     * 发送 SMTP 邮件（便捷全局函数）
     *
     * @param string|array $to 收件人：字符串或 ['email'=>'x@x.com','name'=>'名']
     * @param string $subject 主题
     * @param string $content HTML 正文
     * @param string|array $from 发件人，空则使用模块配置的发件人
     * @param string $module 使用哪一模块的 SMTP 配置，默认 Weline_Smtp
     * @param string $alt 纯文本备选
     * @param string|array $attachment 附件
     * @param string|array $cc 抄送
     * @param string|array $bcc 密送
     * @return bool 是否成功
     */
    function w_smtp(
        string|array $to,
        string $subject,
        string $content,
        string|array $from = '',
        string $module = 'Weline_Smtp',
        string $alt = '',
        string|array $attachment = '',
        string|array $cc = '',
        string|array $bcc = ''
    ): bool {
        $params = [
            'to' => $to,
            'subject' => $subject,
            'content' => $content,
            'module' => $module,
            'alt' => $alt,
            'attachment' => $attachment,
            'cc' => $cc,
            'bcc' => $bcc,
        ];
        if ($from !== '' && $from !== []) {
            $params['from'] = $from;
        }
        try {
            $result = w_query('smtp', 'send', $params);
            return (bool)($result['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
