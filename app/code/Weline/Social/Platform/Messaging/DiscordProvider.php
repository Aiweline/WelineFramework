<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class DiscordProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'discord',
        'title' => 'Discord Webhook',
        'family' => 'messaging',
        'region' => 'global',
        'auth_modes' => ['webhook'],
        'capabilities' => ['publish_text', 'publish_link'],
        'content_types' => ['text', 'link'],
        'required_config' => ['webhook_url'],
        'config_fields' => [
            ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['webhooks' => 'https://docs.discord.com/developers/platform/webhooks'],
        'status' => 'documented_adapter_pending',
    ];
}

