<?php

declare(strict_types=1);

namespace Weline\Smtp\Api;

/** Stable cross-module boundary for mail sent with one module's SMTP configuration. */
interface MailSenderInterface
{
    public function sender(
        string|array $from,
        string|array $to,
        string $subject,
        string $content,
        string $alt = '',
        string|array $attachment = '',
        string|array $reply_to = '',
        string|array $cc = '',
        string|array $bcc = '',
        string $module = 'Weline_Smtp'
    ): bool;
}
