<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class BlueskyProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'bluesky',
        'title' => 'Bluesky / AT Protocol',
        'family' => 'social',
        'region' => 'global',
        'auth_modes' => ['atproto'],
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['handle', 'app_password'],
        'config_fields' => [
            ['key' => 'handle', 'label' => 'Handle', 'type' => 'text', 'required' => true],
            ['key' => 'app_password', 'label' => 'App Password', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['atproto' => 'https://docs.bsky.app/docs/advanced-guides/atproto', 'lexicon' => 'https://atproto.com/guides/lexicon'],
        'status' => 'documented_adapter_pending',
    ];
}

