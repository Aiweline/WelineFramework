<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class TelegramProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'telegram',
        'title' => 'Telegram Bot',
        'family' => 'messaging',
        'region' => 'global',
        'auth_modes' => ['bot_token'],
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['bot_token', 'chat_id'],
        'config_fields' => [
            ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password', 'required' => true],
            ['key' => 'chat_id', 'label' => 'Chat ID', 'type' => 'text', 'required' => true],
        ],
        'docs' => ['bot_api' => 'https://core.telegram.org/bots/api'],
        'status' => 'documented_adapter_pending',
    ];
}

