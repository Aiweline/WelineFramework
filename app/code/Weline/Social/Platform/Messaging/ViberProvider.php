<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class ViberProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'viber',
        'title' => 'Viber Bot',
        'family' => 'messaging',
        'region' => 'global',
        'auth_modes' => ['bot_token'],
        'capabilities' => ['publish_text', 'publish_image'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['bot_token', 'receiver'],
        'config_fields' => [
            ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password', 'required' => true],
            ['key' => 'receiver', 'label' => 'Receiver ID', 'type' => 'text', 'required' => true],
        ],
        'docs' => ['bot_api' => 'https://developers.viber.com/docs/api/rest-bot-api/'],
        'status' => 'documented_adapter_pending',
    ];
}

