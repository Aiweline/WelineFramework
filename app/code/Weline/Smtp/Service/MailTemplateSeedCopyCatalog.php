<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * Explicit mail-template seed copy for default-website locales beyond zh/en files.
 * Source of truth for generated locale files; do not rely on CLI __() locale.
 *
 * @phpstan-type CopyRow array{subject:string,body:string}
 */
final class MailTemplateSeedCopyCatalog
{
    public const LOCALE_ZH = 'zh_Hans_CN';
    public const LOCALE_EN = 'en_US';

    /** @var array<string, mixed>|null */
    private static ?array $json = null;

    /**
     * @return list<string>
     */
    public static function baselineLocales(): array
    {
        return [self::LOCALE_ZH, self::LOCALE_EN];
    }

    /**
     * Locales with maintained seed copy (must cover default-website languages).
     *
     * @return list<string>
     */
    public static function maintainedLocales(): array
    {
        return array_merge(self::baselineLocales(), array_keys(self::json()));
    }

    /**
     * @return CopyRow|null
     */
    public static function forSlug(string $slug, string $locale): ?array
    {
        $locale = trim($locale);
        $slug = trim($slug);
        if ($locale === '' || $slug === '') {
            return null;
        }
        if ($locale === self::LOCALE_ZH || $locale === self::LOCALE_EN) {
            return null; // file-backed
        }
        $pack = self::json()[$locale] ?? null;
        if (!is_array($pack)) {
            return null;
        }
        $body = self::renderBody($slug, $pack);
        $subject = self::renderSubject($slug, $pack);
        if ($body === '' || $subject === '') {
            return null;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @return array{need_help:string,visit:string,support:string,phone:string,hours:string,address:string,auto_footer:string,lang:string}|null
     */
    public static function shellCopy(string $locale): ?array
    {
        $pack = self::json()[trim($locale)] ?? null;
        if (!is_array($pack) || !is_array($pack['shell'] ?? null)) {
            return null;
        }
        /** @var array{need_help?:string,visit?:string,support?:string,phone?:string,hours?:string,address?:string,auto_footer?:string,lang?:string} $shell */
        $shell = $pack['shell'];

        return [
            'lang' => (string)($shell['lang'] ?? 'en'),
            'need_help' => (string)($shell['need_help'] ?? 'Need help?'),
            'visit' => (string)($shell['visit'] ?? 'Visit'),
            'support' => (string)($shell['support'] ?? 'Support:'),
            'phone' => (string)($shell['phone'] ?? 'Phone:'),
            'hours' => (string)($shell['hours'] ?? 'Hours:'),
            'address' => (string)($shell['address'] ?? 'Address:'),
            'auto_footer' => (string)($shell['auto_footer'] ?? ''),
        ];
    }

    /**
     * Materialize locale HTML/subject (+ shells) under module view/email trees.
     *
     * @return array{written:int,skipped:int}
     */
    public static function materializeFiles(?string $repoRoot = null): array
    {
        // Service → Smtp → Weline → code → app → repo
        $repoRoot = $repoRoot !== null && $repoRoot !== ''
            ? $repoRoot
            : dirname(__DIR__, 5);
        $written = 0;
        $skipped = 0;
        $targets = [
            ['Weline/Backend/view/email/notification', 'notification'],
            ['Weline/Visitor/view/email/notification', 'notification'],
            ['Weline/Websites/view/email/notification', 'notification'],
            ['Weline/Customer/view/email/password_reset', 'password_reset'],
            ['Weline/CustomerService/view/email/email_binding', 'email_binding'],
            ['Weline/Dropship/view/email/fulfillment_consolation', 'fulfillment_consolation'],
            ['Weline/Marketing/view/email/unpaid_order_reminder', 'unpaid_order_reminder'],
            ['Weline/Order/view/email/order_created', 'order_created'],
            ['Weline/Order/view/email/order_paid', 'order_paid'],
            ['Weline/Order/view/email/order_status_changed', 'order_status_changed'],
            ['Weline/Order/view/email/order_shipped', 'order_shipped'],
            ['Weline/Order/view/email/order_refund', 'order_refund'],
            ['Weline/Product/view/email/product_update', 'product_update'],
            ['Weline/Product/view/email/quote_reply', 'quote_reply'],
        ];
        foreach (array_keys(self::json()) as $locale) {
            foreach ($targets as [$rel, $slug]) {
                $copy = self::forSlug($slug, $locale);
                if ($copy === null) {
                    ++$skipped;
                    continue;
                }
                $dir = rtrim($repoRoot, '/\\') . '/app/code/' . $rel;
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    ++$skipped;
                    continue;
                }
                $bodyPath = $dir . '/' . $locale . '.html';
                $subjPath = $dir . '/' . $locale . '.subject.txt';
                if (@file_put_contents($bodyPath, $copy['body']) !== false) {
                    ++$written;
                } else {
                    ++$skipped;
                }
                if (@file_put_contents($subjPath, $copy['subject'] . "\n") !== false) {
                    ++$written;
                } else {
                    ++$skipped;
                }
            }
            // 壳已改 shell.phtml + <lang>；不再物化多语言静态 shell/*.html
        }

        return compact('written', 'skipped');
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function renderSubject(string $slug, array $pack): string
    {
        if ($slug === 'notification') {
            return (string)(($pack['notification']['subject'] ?? '') ?: '[{{var.type_label}}] {{var.title}}');
        }
        $row = $pack[$slug] ?? null;
        if (!is_array($row)) {
            return '';
        }

        return trim((string)($row['subject'] ?? ''));
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function renderBody(string $slug, array $pack): string
    {
        /** @var array<string, string> $shared */
        $shared = is_array($pack['shared'] ?? null) ? $pack['shared'] : [];
        $row = is_array($pack[$slug] ?? null) ? $pack[$slug] : [];
        $h1Style = "margin:0 0 16px;font-family:Georgia,'Iowan Old Style',Palatino,'Palatino Linotype',serif;font-size:26px;line-height:1.25;font-weight:600;color:#16333f;";
        $divStyle = 'font-size:15px;line-height:1.7;color:#33434c;';
        // Compact kv rows: label shrink-wraps; avoid width:38% middle void in mail clients.
        $tdL = 'padding:10px 16px 10px 0;border-bottom:1px solid #e6eef1;color:#5c6b74;font-size:13px;white-space:nowrap;width:1%;min-width:4.5em;vertical-align:top;';
        $tdR = 'padding:10px 0;border-bottom:1px solid #e6eef1;color:#1c2a32;font-size:13px;font-weight:600;vertical-align:top;word-break:break-word;';

        return match ($slug) {
            'notification' => self::notificationBody($h1Style, $divStyle, $tdL, $tdR, $shared),
            'password_reset' => self::passwordResetBody($row),
            'email_binding' => self::emailBindingBody($row, $h1Style, $divStyle),
            'fulfillment_consolation' => self::fulfillmentBody($row, $shared, $h1Style, $divStyle, $tdL, $tdR),
            'unpaid_order_reminder' => self::unpaidBody($row, $shared, $h1Style, $divStyle, $tdL, $tdR),
            'order_created', 'order_paid', 'order_status_changed', 'order_shipped', 'order_refund'
                => self::orderBody($row, $shared, $h1Style, $divStyle, $tdL, $tdR),
            'product_update', 'quote_reply' => self::simpleMessageBody($row, $h1Style, $divStyle),
            default => '',
        };
    }

    /**
     * @param array<string, string> $shared
     */
    private static function notificationBody(string $h1, string $div, string $tdL, string $tdR, array $shared): string
    {
        $topic = htmlspecialchars((string)($shared['topic'] ?? 'Topic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {{var.title}} -->
<h1 style="{$h1}">{{var.title}}</h1>
            <div style="{$div}">
<p style="margin:0 0 14px;"><span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#eef5f7;color:#16333f;font-size:12px;font-weight:700;">{{var.type_label}}</span></p>
<div style="margin:0;padding:16px 18px;background:#f6fafb;border-radius:12px;border:1px solid #e0e9ed;line-height:1.7;">{{var.content|raw}}</div>
            </div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 8px;border-collapse:collapse;"><tr><td style="{$tdL}">{$topic}</td><td style="{$tdR}">{{var.topic_code}}</td></tr></table>

HTML;
    }

    /** @param array<string, mixed> $row */
    private static function passwordResetBody(array $row): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p1 = (string)($row['p1'] ?? '');
        $p2 = htmlspecialchars((string)($row['p2'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cta = htmlspecialchars((string)($row['cta'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fallback = htmlspecialchars((string)($row['fallback'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="margin:0 0 14px;font-size:22px;line-height:1.35;font-weight:700;color:#16333f;">{$title}</h1>
            <p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#33434c;">{$p1}</p>
            <p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#33434c;">{$p2}</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 10px;">
              <tr>
                <td align="center" bgcolor="#e8a14a" style="background:#e8a14a;border:1px solid #d48f3a;">
                  <a href="{{var.reset_url}}" target="_blank" style="display:inline-block;padding:14px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#16333f !important;color:#16333f;text-decoration:none;border:0;">{$cta}</a>
                </td>
              </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#6a7a84;">{$fallback}<br>
              <a href="{{var.reset_url}}" style="color:#16333f;text-decoration:underline;word-break:break-all;">{{var.reset_url}}</a>
            </p>

HTML;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function emailBindingBody(array $row, string $h1, string $div): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p1 = (string)($row['p1'] ?? '');
        $p2 = htmlspecialchars((string)($row['p2'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fallback = htmlspecialchars((string)($row['fallback'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cta = htmlspecialchars((string)($row['cta'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="{$h1}">{$title}</h1>
            <div style="{$div}">
<p style="margin:0 0 12px;">{$p1}</p>
<p style="margin:0 0 12px;">{$p2}</p>
<p style="margin:16px 0 0;font-size:12px;color:#5c6b74;word-break:break-all;">{$fallback} {{var.verification_url}}</p>
            </div>


              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 8px;">
                <tr>
                  <td style="border-radius:999px;background:#e8a14a;">
                    <a href="{{var.verification_url}}" style="display:inline-block;padding:14px 28px;color:#16333f;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:0.02em;">{$cta}</a>
                  </td>
                </tr>
              </table>

HTML;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $shared
     */
    private static function fulfillmentBody(array $row, array $shared, string $h1, string $div, string $tdL, string $tdR): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p1 = htmlspecialchars((string)($row['p1'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $order = htmlspecialchars((string)($shared['order'] ?? 'Order'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="{$h1}">{$title}</h1>
            <div style="{$div}">
<p style="margin:0 0 12px;">{$p1}</p>
<p style="margin:0 0 12px;">{{var.message}}</p>
            </div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 8px;border-collapse:collapse;"><tr><td style="{$tdL}">{$order}</td><td style="{$tdR}">{{var.order_uuid}}</td></tr></table>

HTML;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $shared
     */
    private static function unpaidBody(array $row, array $shared, string $h1, string $div, string $tdL, string $tdR): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hello = htmlspecialchars((string)($shared['hello'] ?? 'Hello {{var.customer_name}},'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p2 = (string)($row['p2'] ?? '');
        $cta = htmlspecialchars((string)($row['cta'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $orderNo = htmlspecialchars((string)($shared['order_no'] ?? 'Order no.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $total = htmlspecialchars((string)($shared['total'] ?? 'Total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $placed = htmlspecialchars((string)($shared['placed_at'] ?? 'Placed at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="{$h1}">{$title}</h1>
            <div style="{$div}">
<p style="margin:0 0 12px;">{$hello}</p>
<p style="margin:0 0 12px;">{$p2}</p>
            </div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 8px;border-collapse:collapse;"><tr><td style="{$tdL}">{$orderNo}</td><td style="{$tdR}">{{var.order_number}}</td></tr><tr><td style="{$tdL}">{$total}</td><td style="{$tdR}">{{var.currency}} {{var.grand_total}}</td></tr><tr><td style="{$tdL}">{$placed}</td><td style="{$tdR}">{{var.created_at}}</td></tr></table>
<p style="margin:24px 0 0;">{{#if var.continue_pay_url}}<a href="{{var.continue_pay_url}}" style="display:inline-block;padding:12px 20px;background:#16333f;color:#fff;text-decoration:none;border-radius:4px;">{$cta}</a>{{/if}}</p>

HTML;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $shared
     */
    private static function orderBody(array $row, array $shared, string $h1, string $div, string $tdL, string $tdR): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hello = htmlspecialchars((string)($shared['hello'] ?? 'Hello {{var.customer_name}},'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p2 = htmlspecialchars((string)($row['p2'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $orderNo = htmlspecialchars((string)($shared['order_no'] ?? 'Order no.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $email = htmlspecialchars((string)($shared['email'] ?? 'Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = htmlspecialchars((string)($shared['status'] ?? 'Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $total = htmlspecialchars((string)($shared['total'] ?? 'Total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $itemsLabel = htmlspecialchars((string)($shared['items'] ?? 'Items'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="{$h1}">{$title}</h1>
            <div style="{$div}">
<p style="margin:0 0 12px;">{$hello}</p>
<p style="margin:0 0 12px;">{$p2}</p>
{{#if var.message}}<p style="margin:0 0 12px;">{{var.message}}</p>{{/if}}
{{#if var.comment}}<p style="margin:0;">{{var.comment}}</p>{{/if}}
            </div>
{{#if var.items_html}}
<div style="margin:18px 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.02em;color:#5c6b74;">{$itemsLabel}</div>
{{var.items_html|raw}}
{{/if}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 8px;border-collapse:collapse;"><tr><td style="{$tdL}">{$orderNo}</td><td style="{$tdR}">{{var.order_number}}</td></tr><tr><td style="{$tdL}">{$email}</td><td style="{$tdR}">{{var.customer_email}}</td></tr><tr><td style="{$tdL}">{$status}</td><td style="{$tdR}">{{var.status}}{{#if var.new_status}} / {{var.new_status}}{{/if}}</td></tr><tr><td style="{$tdL}">{$total}</td><td style="{$tdR}">{{var.grand_total}}</td></tr></table>

HTML;
    }

    /** @param array<string, mixed> $row */
    private static function simpleMessageBody(array $row, string $h1, string $div): string
    {
        $pre = htmlspecialchars((string)($row['preheader'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p1 = htmlspecialchars((string)($row['p1'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!-- mail:preheader: {$pre} -->
<h1 style="{$h1}">{$title}</h1>
            <div style="{$div}">
<p style="margin:0 0 12px;">{$p1}</p>
<div style="margin:0;padding:16px 18px;background:#f6fafb;border-radius:12px;border:1px solid #e0e9ed;">{{var.message}}</div>
            </div>

HTML;
    }

    /**
     * @param array{need_help:string,visit:string,support:string,phone:string,hours:string,address:string,auto_footer:string,lang:string} $shell
     */
    private static function renderShellHtml(array $shell): string
    {
        $lang = htmlspecialchars($shell['lang'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $need = htmlspecialchars($shell['need_help'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $visit = htmlspecialchars($shell['visit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $support = htmlspecialchars($shell['support'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $phone = htmlspecialchars($shell['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hours = htmlspecialchars($shell['hours'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $address = htmlspecialchars($shell['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footer = htmlspecialchars($shell['auto_footer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{var.brand_display_name}}</title>
<!-- Email-safe: tables + inline CSS only; no script. Colors from Theme brand_* tokens. -->
</head>
<body style="margin:0;padding:0;background:{{var.brand_canvas}};-webkit-text-size-adjust:100%;" data-weline-mail-shell="1">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{var.brand_canvas}};">{{PREHEADER}}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:{{var.brand_canvas}};margin:0;padding:0;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:{{var.brand_surface}};border:1px solid {{var.brand_border}};">
        <tr>
          <td bgcolor="{{var.brand_header_bg}}" style="background:{{var.brand_header_bg}};padding:22px 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="left" valign="middle" style="vertical-align:middle;">{{var.site_logo_img|raw}}</td>
                <td align="right" valign="middle" style="vertical-align:middle;font-family:Georgia,'Times New Roman',serif;padding-left:16px;">
                  <div style="font-size:17px;line-height:1.35;font-weight:700;letter-spacing:0.02em;color:{{var.brand_header_text}};">{{var.brand_display_name}}</div>
                  <div style="margin-top:6px;font-size:12px;line-height:1.45;font-family:Arial,Helvetica,sans-serif;color:{{var.brand_header_muted}};">{{var.site_description}}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td bgcolor="{{var.brand_accent}}" height="4" style="height:4px;background:{{var.brand_accent}};font-size:0;line-height:0;">&nbsp;</td>
        </tr>
        <tr>
          <td align="left" style="padding:28px 28px 12px;font-family:Arial,Helvetica,sans-serif;color:{{var.brand_text}};font-size:15px;line-height:1.7;">
{{MAIL_BODY}}
          </td>
        </tr>
        <tr>
          <td align="left" bgcolor="{{var.brand_footer_bg}}" style="padding:20px 28px 22px;font-family:Arial,Helvetica,sans-serif;background:{{var.brand_footer_bg}};border-top:1px solid {{var.brand_border}};">
            <div style="font-size:13px;font-weight:700;color:{{var.brand_footer_heading}};margin:0 0 8px;">{$need}</div>
            <div style="font-size:12px;line-height:1.75;color:{{var.brand_muted}};">
              {$visit} <a href="{{var.site_url}}" style="color:{{var.brand_link}};text-decoration:underline;">{{var.site_url}}</a><br>
              {$support} <a href="mailto:{{var.contact_email}}" style="color:{{var.brand_link}};text-decoration:underline;">{{var.contact_email}}</a><br>
              {$phone} {{var.contact_phone}}<br>
              {$hours} {{var.service_hours}}<br>
              {$address} {{var.contact_address}}
            </div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid {{var.brand_border}};font-size:11px;line-height:1.65;color:{{var.brand_muted}};">
              {{var.brand_display_name}}<br>
              {$footer}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>

HTML;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function json(): array
    {
        if (self::$json !== null) {
            return self::$json;
        }
        $path = __DIR__ . '/data/mail_template_seed_copy.json';
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            self::$json = [];

            return self::$json;
        }
        $decoded = json_decode($raw, true);
        self::$json = is_array($decoded) ? $decoded : [];

        return self::$json;
    }
}
