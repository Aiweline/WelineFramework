<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class LineProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'line',
        'title' => 'LINE Official Account',
        'family' => 'messaging',
        'region' => 'global',
        'auth_modes' => ['channel_access_token'],
        'capabilities' => ['publish_text', 'publish_image', 'broadcast'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['channel_access_token'],
        'config_fields' => [
            ['key' => 'channel_access_token', 'label' => 'Channel Access Token', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['messaging_api' => 'https://developers.line.biz/en/docs/messaging-api/sending-messages/'],
        'status' => 'documented_adapter_pending',
    ];
}

