<?php

declare(strict_types=1);

namespace Weline\Social\Service;

/**
 * 各平台官方凭据与连接步骤说明，供后台 UI 与文档共用。
 */
class SocialPlatformCredentialGuide
{
    private const OAUTH_CALLBACK = '/weline_social/backend/oauth/callback';

    /**
     * @return array<string, string>
     */
    public function getGuideKeys(): array
    {
        $keys = [];
        foreach ($this->definitions() as $code => $def) {
            if (!empty($def['guide_key'])) {
                $keys[$code] = (string)$def['guide_key'];
            }
        }
        if (isset($keys['x'])) {
            $keys['twitter'] = $keys['x'];
        }
        return $keys;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGuide(string $platformCode): ?array
    {
        $code = strtolower(trim($platformCode));
        $def = $this->definitions()[$code] ?? null;
        if ($def === null) {
            return null;
        }

        return [
            'code' => $code,
            'auth_type' => (string)($def['auth_type'] ?? 'manual'),
            'auth_label' => (string)($def['auth_label'] ?? ''),
            'guide_key' => (string)($def['guide_key'] ?? ''),
            'system_config_title' => (string)($def['system_config_title'] ?? ''),
            'system_config_fields' => array_values((array)($def['system_config_fields'] ?? [])),
            'developer_steps' => array_values((array)($def['developer_steps'] ?? [])),
            'account_steps' => array_values((array)($def['account_steps'] ?? [])),
            'account_fields' => array_values((array)($def['account_fields'] ?? [])),
            'publish_note' => (string)($def['publish_note'] ?? ''),
            'docs' => array_values((array)($def['docs'] ?? [])),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllGuides(): array
    {
        $out = [];
        foreach (array_keys($this->definitions()) as $code) {
            $guide = $this->getGuide($code);
            if ($guide !== null) {
                $out[$code] = $guide;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        $oauthCb = self::OAUTH_CALLBACK;
        $oauthDev = [
            (string)__('在对应平台开发者后台创建应用，并把 OAuth 回调设为：%{1}', [$oauthCb]),
            (string)__('回调 URL 需包含你的后台域名与端口，例如 https://your-admin.example.com/jRax.../weline_social/backend/oauth/callback'),
        ];

        return [
            'facebook' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（OAuth）'),
                'guide_key' => 'social/platform/facebook/app_id',
                'system_config_title' => (string)__('Meta（Facebook）'),
                'system_config_fields' => ['App ID', 'App Secret', 'Graph API 版本'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('Meta 开发者后台启用 Facebook Login，申请 pages_manage_posts、pages_read_engagement 等 Page 权限'),
                ]),
                'account_steps' => [
                    (string)__('点「打开系统配置」填写 App ID/Secret'),
                    (string)__('回到本页点「一键授权」，用 Page 管理员账号登录并授权'),
                    (string)__('授权成功后自动写入 Page Access Token 与 Page ID；也可人工粘贴'),
                    (string)__('保存 → 检测 → 启用发布'),
                ],
                'account_fields' => ['Page Access Token', 'Page ID'],
                'publish_note' => (string)__('支持文本、链接、图片 URL 发布'),
                'docs' => [
                    ['label' => 'Graph API', 'url' => 'https://developers.facebook.com/docs/graph-api/'],
                    ['label' => 'Pages API', 'url' => 'https://developers.facebook.com/docs/pages-api/'],
                ],
            ],
            'instagram' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（Meta OAuth）'),
                'guide_key' => 'social/platform/instagram/app_id',
                'system_config_title' => (string)__('Meta（Instagram）'),
                'system_config_fields' => ['Instagram App ID/Secret（可留空复用 Facebook）'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('Instagram 业务账号需关联 Facebook Page，并开通 Content Publishing'),
                ]),
                'account_steps' => [
                    (string)__('配置 Facebook/Instagram 应用凭据'),
                    (string)__('一键授权后自动写入 Access Token 与 Instagram Business User ID'),
                    (string)__('发布需图片 URL；保存后检测并启用发布'),
                ],
                'account_fields' => ['Access Token', 'Instagram Business User ID'],
                'publish_note' => (string)__('支持图片 URL 发布'),
                'docs' => [
                    ['label' => 'Instagram API', 'url' => 'https://developers.facebook.com/docs/instagram-api/'],
                ],
            ],
            'youtube' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（Google OAuth）'),
                'guide_key' => 'social/platform/youtube/client_id',
                'system_config_title' => (string)__('Google（YouTube）'),
                'system_config_fields' => ['OAuth Client ID', 'OAuth Client Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('Google Cloud Console 启用 YouTube Data API v3'),
                ]),
                'account_steps' => [
                    (string)__('填写 Client ID/Secret 后一键授权'),
                    (string)__('授权后写入 Access Token；可选填 Channel ID'),
                    (string)__('第一期仅连通检测；视频上传发布二期支持'),
                ],
                'account_fields' => ['Access Token', 'Refresh Token', 'Channel ID'],
                'publish_note' => (string)__('第一期：连通检测；视频上传二期'),
                'docs' => [
                    ['label' => 'YouTube Data API', 'url' => 'https://developers.google.com/youtube/v3'],
                ],
            ],
            'tiktok' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（TikTok Login Kit）'),
                'guide_key' => 'social/platform/tiktok/client_key',
                'system_config_title' => (string)__('TikTok'),
                'system_config_fields' => ['Client Key', 'Client Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('TikTok 开发者平台启用 Login Kit 与 Content Posting API'),
                ]),
                'account_steps' => [
                    (string)__('配置 Client Key/Secret → 一键授权'),
                    (string)__('保存 Open ID / Refresh Token（自动或人工）'),
                    (string)__('第一期连通检测；视频上传二期'),
                ],
                'account_fields' => ['Access Token', 'Open ID', 'Refresh Token'],
                'publish_note' => (string)__('第一期：连通检测；视频上传二期'),
                'docs' => [
                    ['label' => 'Content Posting', 'url' => 'https://developers.tiktok.com/doc/content-posting-api-get-started/'],
                ],
            ],
            'whatsapp' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('人工凭据（Cloud API）'),
                'guide_key' => 'social/platform/whatsapp/waba_id',
                'system_config_title' => (string)__('WhatsApp Cloud API'),
                'system_config_fields' => ['默认 WABA ID（可选）'],
                'developer_steps' => [
                    (string)__('Meta Business 创建 WhatsApp Business 应用与 Cloud API 号码'),
                    (string)__('在 Meta 开发者后台获取 Phone Number ID 与永久 Access Token'),
                ],
                'account_steps' => [
                    (string)__('系统配置可填默认 WABA ID'),
                    (string)__('账户表单填写 Phone Number ID、Cloud API Access Token、默认收件人 E.164'),
                    (string)__('保存 → 检测 → 启用发布'),
                ],
                'account_fields' => ['Phone Number ID', 'Access Token', 'Default Recipient', 'WABA ID'],
                'publish_note' => (string)__('支持 Cloud API 文本消息'),
                'docs' => [
                    ['label' => 'Cloud API', 'url' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/'],
                ],
            ],
            'wechat' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('人工凭据（服务号）'),
                'guide_key' => 'social/platform/wechat/app_id',
                'system_config_title' => (string)__('微信公众号'),
                'system_config_fields' => ['默认 AppID', '默认 AppSecret'],
                'developer_steps' => [
                    (string)__('微信公众平台注册认证服务号，获取 AppID/AppSecret'),
                    (string)__('客服消息需目标用户 OpenID'),
                ],
                'account_steps' => [
                    (string)__('系统配置填默认 AppID/AppSecret（可选）'),
                    (string)__('账户填 AppID、AppSecret、OpenID；Access Token 可留空自动获取'),
                    (string)__('保存 → 检测 → 启用发布'),
                ],
                'account_fields' => ['AppID', 'AppSecret', 'OpenID', 'Access Token'],
                'publish_note' => (string)__('客服文本消息；需有效 OpenID'),
                'docs' => [
                    ['label' => '公众号文档', 'url' => 'https://developers.weixin.qq.com/doc/offiaccount/Getting_Started/Overview.html'],
                ],
            ],
            'x' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（OAuth 2.0 PKCE）'),
                'guide_key' => 'social/platform/x/client_id',
                'system_config_title' => (string)__('X / Twitter'),
                'system_config_fields' => ['Client ID', 'Client Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('X Developer Portal 创建 App，启用 OAuth 2.0 与 tweet.write'),
                ]),
                'account_steps' => [
                    (string)__('填写 Client ID/Secret → 一键授权'),
                    (string)__('授权后写入 Access Token / Refresh Token'),
                ],
                'account_fields' => ['Access Token', 'Refresh Token'],
                'publish_note' => (string)__('支持推文文本发布'),
                'docs' => [
                    ['label' => 'Twitter API', 'url' => 'https://developer.x.com/en/docs/twitter-api'],
                ],
            ],
            'linkedin' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（OAuth）'),
                'guide_key' => 'social/platform/linkedin/client_id',
                'system_config_title' => (string)__('LinkedIn'),
                'system_config_fields' => ['Client ID', 'Client Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('LinkedIn Developer 创建 App，申请 w_member_social 等权限'),
                ]),
                'account_steps' => [
                    (string)__('一键授权后还需填写 Author URN（urn:li:person:xxx）'),
                ],
                'account_fields' => ['Access Token', 'Author URN'],
                'publish_note' => (string)__('支持 UGC 文本帖'),
                'docs' => [
                    ['label' => 'Share API', 'url' => 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api'],
                ],
            ],
            'pinterest' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（OAuth）'),
                'guide_key' => 'social/platform/pinterest/app_id',
                'system_config_title' => (string)__('Pinterest'),
                'system_config_fields' => ['App ID', 'App Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('Pinterest Developer 创建 App 并申请 pins:write'),
                ]),
                'account_steps' => [
                    (string)__('一键授权后填写 Board ID'),
                ],
                'account_fields' => ['Access Token', 'Board ID'],
                'publish_note' => (string)__('Pin 发布需图片 URL'),
                'docs' => [
                    ['label' => 'Pinterest API', 'url' => 'https://developers.pinterest.com/docs/getting-started/'],
                ],
            ],
            'snapchat' => [
                'auth_type' => 'oauth',
                'auth_label' => (string)__('一键授权（OAuth）'),
                'guide_key' => 'social/platform/snapchat/client_id',
                'system_config_title' => (string)__('Snapchat'),
                'system_config_fields' => ['Client ID', 'Client Secret'],
                'developer_steps' => array_merge($oauthDev, [
                    (string)__('Snap Kit / Marketing API 创建 OAuth 应用'),
                ]),
                'account_steps' => [
                    (string)__('一键授权；可选填 Ad Account ID'),
                ],
                'account_fields' => ['Access Token', 'Refresh Token', 'Ad Account ID'],
                'publish_note' => (string)__('第一期连通检测；视频上传二期'),
                'docs' => [
                    ['label' => 'Marketing API', 'url' => 'https://developers.snap.com/api/marketing-api/'],
                ],
            ],
            'telegram' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Bot Token（人工）'),
                'guide_key' => '',
                'system_config_title' => '',
                'developer_steps' => [
                    (string)__('通过 @BotFather 创建 Bot 并获取 Token'),
                    (string)__('获取目标 Chat ID（群组/频道/用户）'),
                ],
                'account_steps' => [
                    (string)__('无需系统配置；直接在账户表单填 Bot Token 与 Chat ID'),
                    (string)__('保存 → 检测'),
                ],
                'account_fields' => ['Bot Token', 'Chat ID'],
                'publish_note' => (string)__('Adapter 待实发；凭据可先保存用于展示或联调'),
                'docs' => [
                    ['label' => 'Bot API', 'url' => 'https://core.telegram.org/bots/api'],
                ],
            ],
            'discord' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Webhook URL'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Discord 频道设置 → 集成 → Webhook → 复制 Webhook URL'),
                ],
                'account_steps' => [
                    (string)__('账户表单粘贴 Webhook URL → 保存 → 检测'),
                ],
                'account_fields' => ['Webhook URL'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Webhooks', 'url' => 'https://docs.discord.com/developers/platform/webhooks'],
                ],
            ],
            'wordpress' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Application Password'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('WordPress 用户 → 个人资料 → Application Passwords 生成密码'),
                ],
                'account_steps' => [
                    (string)__('填 Site URL、Username、Application Password'),
                ],
                'account_fields' => ['Site URL', 'Username', 'Application Password'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'REST Auth', 'url' => 'https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/'],
                ],
            ],
            'line' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Channel Access Token'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('LINE Developers 创建 Messaging API Channel，签发 Channel Access Token'),
                ],
                'account_steps' => [
                    (string)__('账户表单填 Channel Access Token'),
                ],
                'account_fields' => ['Channel Access Token'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Messaging API', 'url' => 'https://developers.line.biz/en/docs/messaging-api/sending-messages/'],
                ],
            ],
            'bluesky' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('App Password（AT Protocol）'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Bluesky 设置 → App Passwords 生成专用密码'),
                ],
                'account_steps' => [
                    (string)__('填 Handle（如 user.bsky.social）与 App Password'),
                ],
                'account_fields' => ['Handle', 'App Password'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'AT Protocol', 'url' => 'https://docs.bsky.app/docs/advanced-guides/atproto'],
                ],
            ],
            'tumblr' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('OAuth Client（人工）'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Tumblr 开发者注册 App，获取 Client ID/Secret'),
                ],
                'account_steps' => [
                    (string)__('填 Client ID、Client Secret、Blog Identifier'),
                ],
                'account_fields' => ['Client ID', 'Client Secret', 'Blog Identifier'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'API v2', 'url' => 'https://www.tumblr.com/docs/en/api/v2'],
                ],
            ],
            'ghost' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Admin API Key'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Ghost Admin → Integrations → 创建 Custom Integration，复制 Admin API URL 与 Key'),
                ],
                'account_steps' => [
                    (string)__('填 Admin API URL 与 Admin API Key'),
                ],
                'account_fields' => ['Admin API URL', 'Admin API Key'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Admin API', 'url' => 'https://docs.ghost.org/admin-api/'],
                ],
            ],
            'mastodon' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('实例 OAuth Client（人工）'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('在目标 Mastodon 实例注册 OAuth 应用（Development → 新建应用）'),
                ],
                'account_steps' => [
                    (string)__('填 Instance URL、Client ID、Client Secret；Token 流程待一键授权接入'),
                ],
                'account_fields' => ['Instance URL', 'Client ID', 'Client Secret'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'OAuth', 'url' => 'https://docs.joinmastodon.org/spec/oauth/'],
                ],
            ],
            'lemmy' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('JWT Token'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Lemmy 实例登录后从 API 获取 JWT（或使用 bot 账号）'),
                ],
                'account_steps' => [
                    (string)__('填 Instance URL 与 Access Token'),
                ],
                'account_fields' => ['Instance URL', 'Access Token'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Lemmy API', 'url' => 'https://join-lemmy.org/docs/en/contributors/04-api.html'],
                ],
            ],
            'misskey' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('API Token'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Misskey 设置 → API → 生成 Access Token'),
                ],
                'account_steps' => [
                    (string)__('填 Instance URL 与 Access Token'),
                ],
                'account_fields' => ['Instance URL', 'Access Token'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Misskey API', 'url' => 'https://misskey.io/api-doc'],
                ],
            ],
            'discourse' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('API Key'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Discourse Admin → API → 创建 API Key 并指定 API Username'),
                ],
                'account_steps' => [
                    (string)__('填 Base URL、API Key、API Username'),
                ],
                'account_fields' => ['Base URL', 'API Key', 'API Username'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Discourse API', 'url' => 'https://docs.discourse.org/'],
                ],
            ],
            'viber' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('Bot Token'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('Viber Admin Panel 创建 Public Account / Bot，获取 Token'),
                ],
                'account_steps' => [
                    (string)__('填 Bot Token 与 Receiver ID'),
                ],
                'account_fields' => ['Bot Token', 'Receiver ID'],
                'publish_note' => (string)__('Adapter 待实发'),
                'docs' => [
                    ['label' => 'Viber Bot API', 'url' => 'https://developers.viber.com/docs/api/rest-bot-api/'],
                ],
            ],
            'fake_browser' => [
                'auth_type' => 'manual',
                'auth_label' => (string)__('本地 Fake Token'),
                'guide_key' => '',
                'developer_steps' => [
                    (string)__('仅用于本地 smoke；不访问外部平台'),
                ],
                'account_steps' => [
                    (string)__('填任意 Fake Token，或运行 fake 浏览器验证自动创建'),
                ],
                'account_fields' => ['Fake Token'],
                'publish_note' => (string)__('本地 fake 发布验证'),
                'docs' => [],
            ],
        ];
    }
}
