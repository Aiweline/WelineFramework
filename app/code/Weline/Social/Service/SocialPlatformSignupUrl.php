<?php

declare(strict_types=1);

namespace Weline\Social\Service;

/**
 * 平台开号页。地址归平台定义；{email} 在打开时换成当前站点社媒邮箱。
 * 没有公开注册页的平台返回空串，界面仍复制邮箱，不伪造地址。
 */
final class SocialPlatformSignupUrl
{
    /**
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'youtube' => 'https://accounts.google.com/signup/v2/webcreateaccount?flowName=GlifWebSignIn&flowEntry=SignUp&Email={email}',
        'facebook' => 'https://www.facebook.com/r.php?email={email}',
        'instagram' => 'https://www.instagram.com/accounts/emailsignup/?email={email}',
        'x' => 'https://x.com/i/flow/signup',
        'tiktok' => 'https://www.tiktok.com/signup/phone-or-email/email',
        'linkedin' => 'https://www.linkedin.com/signup/cold-join?emailAddress={email}',
        'pinterest' => 'https://www.pinterest.com/business/create/',
        'snapchat' => 'https://accounts.snapchat.com/accounts/v2/signup',
        'bluesky' => 'https://bsky.app/',
        'discord' => 'https://discord.com/register',
        'tumblr' => 'https://www.tumblr.com/register',
        'wordpress' => 'https://wordpress.com/start/user?user_email={email}',
        'telegram' => 'https://web.telegram.org/a/',
        'mastodon' => 'https://mastodon.social/auth/sign_up',
        'whatsapp' => 'https://web.whatsapp.com/',
    ];

    public static function templateFor(string $platformCode): string
    {
        $code = \strtolower(\trim($platformCode));
        $template = self::TEMPLATES[$code] ?? '';
        if ($template !== '' && !\str_starts_with($template, 'https://')) {
            return '';
        }

        return $template;
    }

    public static function build(string $platformCode, string $email, string $templateOverride = ''): string
    {
        $template = \trim($templateOverride) !== '' ? \trim($templateOverride) : self::templateFor($platformCode);
        if ($template === '' || !\str_starts_with($template, 'https://')) {
            return '';
        }
        if (!\str_contains($template, '{email}')) {
            return $template;
        }
        $email = \trim($email);
        if ($email === '') {
            return '';
        }

        return \str_replace('{email}', \rawurlencode($email), $template);
    }
}
