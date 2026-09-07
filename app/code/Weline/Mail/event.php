<?php

declare(strict_types=1);

/**
 * Events dispatched by Weline_Mail.
 */
return [
    'Weline_Mail::mail_message_sent' => [
        'name' => __('企业邮箱邮件已发送'),
        'description' => __('后台代发或鉴权发信成功后派发；可携带 source / source_id 供业务闭环。'),
        'doc' => 'doc/event/mail_message_sent.md',
    ],
];
