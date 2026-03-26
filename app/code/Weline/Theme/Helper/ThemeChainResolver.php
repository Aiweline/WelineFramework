<?php
declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€?
 * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 涓婚缁ф壙閾捐В鏋愬櫒
 * 
 * 鑱岃矗锛氳В鏋愪富棰樼户鎵块摼
 * 閬靛惊锛氬崟涓€鑱岃矗鍘熷垯 (SRP)
 */
class ThemeChainResolver implements ThemeChainResolverInterface
{
    /**
     * 鑾峰彇涓婚缁ф壙閾撅紙浠庡熀纭€鍒板綋鍓嶏細鐖朵富棰樺湪鍓嶏紝婵€娲讳富棰樺湪鍚庯級
     * 
     * @param WelineTheme $theme 褰撳墠涓婚
     * @return WelineTheme[] 涓婚缁ф壙閾炬暟缁?
     */
    public function getThemeChain(WelineTheme $theme): array
    {
        return $theme->getThemeChain();
    }
}
