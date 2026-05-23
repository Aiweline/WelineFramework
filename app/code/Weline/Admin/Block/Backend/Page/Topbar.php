<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Config;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\I18n\Model\I18n;

class Topbar extends \Weline\Framework\View\Block
{
    public string $_template = 'Weline_Admin::backend/public/top-bar.phtml';
    private Config $config;
    private AuthenticatedSessionInterface $session;
    private ?BackendUser $user = null;

    public function __construct(Config $config, array $data = [])
    {
        $this->config = $config;
        $this->session = SessionFactory::getInstance()->createBackendSession();
        parent::__construct($data);
        $this->getUser();
    }

    public function __init()
    {
        parent::__init();
        // 使用默认宽高24x18，autoSize=true使SVG自适应按钮大小
        $languages = $this->getI18n()->getLocalesWithFlagsDisplaySelf(Cookie::getLangLocal(), 24, 18, true, true);
        $websiteId = (int)($this->request->getData('website_id') ?? 0);
        if ($websiteId > 0) {
            $websiteLanguageCodes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
            if (is_array($websiteLanguageCodes) && !empty($websiteLanguageCodes)) {
                $allowedMap = [];
                foreach ($websiteLanguageCodes as $websiteLanguageCode) {
                    $websiteLanguageCode = (string)$websiteLanguageCode;
                    if ($websiteLanguageCode !== '') {
                        $allowedMap[$websiteLanguageCode] = true;
                    }
                }
                if (!empty($allowedMap)) {
                    $filteredLanguages = [];
                    foreach ($languages as $languageCode => $languageData) {
                        if (isset($allowedMap[(string)$languageCode])) {
                            $filteredLanguages[$languageCode] = $languageData;
                        }
                    }
                    if (!empty($filteredLanguages)) {
                        $languages = $filteredLanguages;
                    }
                }
            }
        }
        $this->assign('languages', $languages);
        $current_language = ['code' => 'zh_Hans_CN', 'name' => '中文', 'flag' => ''];
        if (isset($languages[Cookie::getLang()])) {
            $current_language = $languages[Cookie::getLang()];
            $current_language['code'] = Cookie::getLang();
        }
        $this->assign('current_language', $current_language);
    }

    public function getI18n(): I18n
    {
        return ObjectManager::getInstance(I18n::class);
    }

    public function getAvatar()
    {
        /** @var BackendUser $user */
        $user = $this->getUser();
        $avatar = $user->getAvatar();

        // 1. 用户自己上传了头像，直接返回
        if (!empty($avatar)) {
            return $avatar;
        }

        // 2. 没有上传头像时，如果有邮箱，则根据邮箱哈希生成首字母头像（SVG data URI）
        $email = method_exists($user, 'getEmail') ? ($user->getEmail() ?? '') : '';
        if ($email !== '') {
            return $this->generateLetterAvatar($user);
        }

        // 3. 再兜底使用系统配置的默认头像
        if ($avatar = $this->config->getConfig('admin_default_avatar', 'Weline_Admin')) {
            $avatar = Template::getInstance()->fetchTagSourceFile(DataInterface::view_STATICS_DIR, $avatar);
        } else {
            $this->config->setConfig('admin_default_avatar', 'Weline_Admin::img/logo.jpg', 'Weline_Admin');
            $avatar = Template::getInstance()->fetchTagSourceFile(DataInterface::view_STATICS_DIR, 'Weline_Admin::img/logo.jpg');
        }

        return $avatar;
    }

    /**
     * 根据管理员邮箱/用户名生成首字母头像（SVG Data URI）。
     *
     * - 颜色由邮箱哈希决定，保证同一邮箱颜色稳定
     * - 文本为邮箱（优先）或用户名的首字母
     */
    private function generateLetterAvatar(BackendUser $user): string
    {
        $email = method_exists($user, 'getEmail') ? ($user->getEmail() ?? '') : '';
        $name = method_exists($user, 'getUsername') ? ($user->getUsername() ?? '') : '';

        $base = trim($email !== '' ? $email : $name);
        if ($base === '') {
            $base = 'A';
        }

        // 取首字母（对多字节字符友好）
        $firstLetter = mb_substr($base, 0, 1, 'UTF-8');
        $firstLetter = mb_strtoupper($firstLetter, 'UTF-8');

        // 使用邮箱（优先）或用户名做哈希，映射到一组预设主题色
        $seed = strtolower($email !== '' ? $email : $name);
        $hash = crc32($seed);

        // 使用框架主题常用色系，避免随意自定义颜色
        $palette = [
            '#0bb197', // primary
            '#0ac074', // success
            '#4aa3ff', // info
            '#fcb92c', // warning
            '#ff3d60', // danger
            '#74788d', // secondary
        ];
        $color = $palette[$hash % count($palette)];

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">'
            . '<rect width="40" height="40" rx="20" ry="20" fill="%s"/>'
            . '<text x="50%%" y="50%%" dominant-baseline="central" text-anchor="middle" '
            . 'fill="#ffffff" font-size="18" font-family="system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif">%s</text>'
            . '</svg>',
            $color,
            htmlspecialchars($firstLetter, ENT_QUOTES, 'UTF-8')
        );

        // 转成 data URI，直接给 <img src="..."> 使用
        $encodedSvg = rawurlencode($svg);
        return 'data:image/svg+xml;charset=UTF-8,' . $encodedSvg;
    }

    public function getUser(): BackendUser|AbstractModel
    {
        if (empty($this->user)) {
            $user = $this->session->getLoginUser();
            if ($user instanceof BackendUser) {
                $this->user = $user;
            } else {
                $fallback = ObjectManager::getInstance(BackendUser::class);
                $fallback->setData('username', 'Guest');
                $fallback->setData('email', '');
                $this->user = $fallback;
            }
        }
        return $this->user;
    }
}
