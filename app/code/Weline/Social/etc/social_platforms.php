<?php

declare(strict_types=1);

/**
 * Core social platform providers.
 * Display order is controlled by each provider DEFINITION['sort_order']
 * (lower = higher popularity heat). Keep this list in the same heat order
 * for readability; registry still sorts by sort_order at runtime.
 */
return [
    // Top 10 major platforms (documented adapters; live publish pending)
    \Weline\Social\Platform\Social\FacebookProvider::class,
    \Weline\Social\Platform\Video\YoutubeProvider::class,
    \Weline\Social\Platform\Social\InstagramProvider::class,
    \Weline\Social\Platform\Social\TiktokProvider::class,
    \Weline\Social\Platform\Messaging\WhatsappProvider::class,
    \Weline\Social\Platform\Messaging\WechatProvider::class,
    \Weline\Social\Platform\Social\XProvider::class,
    \Weline\Social\Platform\Social\LinkedinProvider::class,
    \Weline\Social\Platform\Social\PinterestProvider::class,
    \Weline\Social\Platform\Social\SnapchatProvider::class,
    // Existing documented / niche platforms
    \Weline\Social\Platform\Messaging\TelegramProvider::class,
    \Weline\Social\Platform\Messaging\DiscordProvider::class,
    \Weline\Social\Platform\Blog\WordPressProvider::class,
    \Weline\Social\Platform\Messaging\LineProvider::class,
    \Weline\Social\Platform\Social\BlueskyProvider::class,
    \Weline\Social\Platform\Blog\TumblrProvider::class,
    \Weline\Social\Platform\Blog\GhostProvider::class,
    \Weline\Social\Platform\Fediverse\MastodonProvider::class,
    \Weline\Social\Platform\Messaging\ViberProvider::class,
    \Weline\Social\Platform\Forum\DiscourseProvider::class,
    \Weline\Social\Platform\Fediverse\MisskeyProvider::class,
    \Weline\Social\Platform\Fediverse\LemmyProvider::class,
    \Weline\Social\Platform\Fake\FakeBrowserProvider::class,
];
