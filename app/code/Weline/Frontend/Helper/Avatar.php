<?php

declare(strict_types=1);

namespace Weline\Frontend\Helper;

/**
 * 头像辅助类
 * 提供默认头像SVG生成
 */
class Avatar
{
    /**
     * 获取默认头像SVG代码（内联）
     * 
     * @param int $size 头像尺寸（宽高相同）
     * @param string $cssClass CSS类名
     * @return string SVG HTML代码
     */
    public static function getDefaultAvatarSvg(int $size = 100, string $cssClass = ''): string
    {
        $class = $cssClass ? ' class="' . htmlspecialchars($cssClass) . '"' : '';
        
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="{$size}" height="{$size}"{$class}>
  <defs>
    <linearGradient id="avatarGradient{$size}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#667eea;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#764ba2;stop-opacity:1" />
    </linearGradient>
    <filter id="avatarShadow{$size}">
      <feDropShadow dx="0" dy="2" stdDeviation="4" flood-opacity="0.3"/>
    </filter>
  </defs>
  <circle cx="100" cy="100" r="100" fill="url(#avatarGradient{$size})"/>
  <g fill="white" filter="url(#avatarShadow{$size})">
    <circle cx="100" cy="75" r="30"/>
    <path d="M 100 105 Q 70 105, 60 125 Q 55 135, 55 150 L 55 180 Q 55 185, 60 185 L 140 185 Q 145 185, 145 180 L 145 150 Q 145 135, 140 125 Q 130 105, 100 105 Z"/>
  </g>
  <circle cx="100" cy="100" r="98" fill="none" stroke="white" stroke-width="2" opacity="0.3"/>
</svg>
SVG;
    }
    
    /**
     * 判断是否使用默认头像
     * 
     * @param string|null $avatarUrl 头像URL
     * @return bool
     */
    public static function isDefaultAvatar(?string $avatarUrl): bool
    {
        if (empty($avatarUrl)) {
            return true;
        }
        
        // 判断是否是默认头像标记
        return in_array($avatarUrl, [
            'default',
            'default-svg',
            '/static/Weline_Frontend/img/default-avatar.svg',
            '/static/Weline_Frontend/img/default-avatar.png',
        ]);
    }
    
    /**
     * 获取头像HTML代码
     * 如果是默认头像则返回SVG，否则返回img标签
     * 
     * @param string|null $avatarUrl 头像URL
     * @param int $size 尺寸
     * @param string $cssClass CSS类名
     * @param string $alt 替代文本
     * @return string HTML代码
     */
    public static function getAvatarHtml(?string $avatarUrl, int $size = 100, string $cssClass = '', string $alt = ''): string
    {
        if (self::isDefaultAvatar($avatarUrl)) {
            return self::getDefaultAvatarSvg($size, $cssClass);
        }
        
        $class = $cssClass ? ' class="' . htmlspecialchars($cssClass) . '"' : '';
        $altText = $alt ?: __('用户头像');
        
        return sprintf(
            '<img src="%s" width="%d" height="%d"%s alt="%s">',
            htmlspecialchars($avatarUrl),
            $size,
            $size,
            $class,
            htmlspecialchars($altText)
        );
    }
}

