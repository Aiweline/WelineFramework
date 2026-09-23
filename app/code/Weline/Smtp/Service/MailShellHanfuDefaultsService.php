<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data as SmtpData;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Hanfu 激活主题下的邮件壳默认：实心深页头 + 朱砂字、宣纸画布、水墨 body/footer 背景图、品牌页尾。
 * 空 header/footer 表示回落 shell.phtml，不写回默认 HTML；仅 $force 或坏数据修复时才覆盖区域。
 */
final class MailShellHanfuDefaultsService
{
    public const SCOPE_DEFAULT = 'default.default.default';

    public const BG_BODY = 'mail/backgrounds/body/image.png';
    public const BG_FOOTER = 'mail/backgrounds/footer/image.png';

    public function isHanfuThemeActive(): bool
    {
        try {
            if (!class_exists(\Weline\Theme\Model\WelineTheme::class)) {
                return false;
            }
            /** @var \Weline\Theme\Model\WelineTheme $theme */
            $theme = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme('frontend');
            $path = strtolower(str_replace('\\', '/', (string)$theme->getData('path')));
            $name = strtolower((string)$theme->getData('name'));

            return str_contains($path, 'hanfu') || $name === 'hanfu';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{applied:bool,reason:string,scope:string}
     */
    public function ensure(string $storageScope = self::SCOPE_DEFAULT, bool $force = false): array
    {
        $storageScope = trim($storageScope) !== '' ? trim($storageScope) : self::SCOPE_DEFAULT;
        if (!$this->isHanfuThemeActive()) {
            return ['applied' => false, 'reason' => 'theme_not_hanfu', 'scope' => $storageScope];
        }

        /** @var SmtpData $data */
        $data = ObjectManager::getInstance(SmtpData::class);
        $bgs = $data->getMailShellBackgrounds($storageScope);
        // 页头：截图为实心炭黑，清空 header 图；body/footer 保留水墨底图
        $nextHeader = '';
        $nextBody = trim((string)($bgs['body'] ?? '')) !== '' ? (string)$bgs['body'] : self::BG_BODY;
        $nextFooter = trim((string)($bgs['footer'] ?? '')) !== '' ? (string)$bgs['footer'] : self::BG_FOOTER;
        if ($force || trim((string)($bgs['body'] ?? '')) === '' || trim((string)($bgs['footer'] ?? '')) === ''
            || trim((string)($bgs['header'] ?? '')) !== '') {
            $data->setMailShellBackgrounds($storageScope, [
                'header' => $nextHeader,
                'body' => $nextBody,
                'footer' => $nextFooter,
            ]);
        }

        /** @var MailShellRegionStore $regions */
        $regions = ObjectManager::getInstance(MailShellRegionStore::class);
        $current = $regions->get($storageScope);
        // 空 header/footer = 使用主题/模块 shell.phtml，禁止空头写回 defaultHeaderHtml。
        // 仅 $force 或检测到坏数据（!important / #ff5d05 / 硬编码验收 Host）时才覆盖区域。
        $headerHtml = (string)($current['header'] ?? '');
        $footerJoined = implode("\n", $current['footer'] ?? []);
        $needHeader = $force
            || str_contains($headerHtml, '!important')
            || str_contains($headerHtml, '#ff5d05');
        $needFooter = $force
            || str_contains($footerJoined, '!important')
            || str_contains($footerJoined, '#ff5d05')
            || str_contains($footerJoined, 'p05113ef3.test.weline.com');
        if ($needHeader || $needFooter) {
            $regions->save(
                $storageScope,
                $needHeader ? $this->defaultHeaderHtml() : $headerHtml,
                $needFooter ? [$this->defaultFooterHtml()] : ($current['footer'] ?? []),
            );
        }

        return ['applied' => true, 'reason' => 'ok', 'scope' => $storageScope];
    }

    public function defaultHeaderHtml(): string
    {
        return <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td align="left" valign="middle" style="vertical-align:middle;">
      {{var.site_logo_img|raw}}
    </td>
    <td align="right" valign="middle" style="vertical-align:middle;font-family:Georgia,'Times New Roman',serif;padding-left:16px;">
      <div style="font-size:17px;line-height:1.35;font-weight:700;letter-spacing:0.02em;color:{{var.brand_header_text}};">{{var.brand_display_name}}</div>
      <div style="margin-top:6px;font-size:12px;line-height:1.45;font-family:Arial,Helvetica,sans-serif;color:{{var.brand_header_muted}};">{{var.site_description}}</div>
    </td>
  </tr>
</table>
HTML;
    }

    public function defaultFooterHtml(): string
    {
        return <<<'HTML'
<div style="font-size:13px;font-weight:700;color:{{var.brand_footer_heading}};margin:0 0 8px;">需要帮助？</div>
<div style="font-size:12px;line-height:1.75;color:{{var.brand_muted}};">
  访问 <a href="{{var.site_url}}" style="color:{{var.brand_link}};text-decoration:underline;">{{var.site_url}}</a><br>
  客服邮箱：<a href="mailto:{{var.contact_email}}" style="color:{{var.brand_link}};text-decoration:underline;">{{var.contact_email}}</a><br>
  客服电话：{{var.contact_phone}}<br>
  服务时间：{{var.service_hours}}<br>
  地址：{{var.contact_address}}
</div>
<div style="margin-top:14px;padding-top:12px;border-top:1px solid {{var.brand_border}};font-size:11px;line-height:1.65;color:{{var.brand_muted}};">
  {{var.brand_display_name}}<br>
  此邮件由系统自动发送，请勿直接回复。如非本人操作，请忽略本邮件。
</div>
HTML;
    }
}
