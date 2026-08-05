<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

use Weline\Admin\Api\Localization\BackendLocaleCatalogInterface;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

class Topbar extends \Weline\Framework\View\Block
{
    public string $_template = 'Weline_Admin::backend/public/top-bar.phtml';
    private BackendConfigStore $config;
    private RuntimeProviderResolver $runtimeProviderResolver;
    private AuthenticatedSessionInterface $session;
    private ?BackendUserContext $user = null;
    private ?string $userCacheKey = null;

    public function __construct(
        BackendConfigStore $config,
        RuntimeProviderResolver $runtimeProviderResolver,
        array $data = [],
    ) {
        $this->config = $config;
        $this->runtimeProviderResolver = $runtimeProviderResolver;
        $this->session = SessionFactory::getInstance()->createBackendSession();
        parent::__construct($data);
    }

    public function __init()
    {
        parent::__init();
        $this->session = SessionFactory::getInstance()->createBackendSession();
        // 使用默认宽高24x18，autoSize=true使SVG自适应按钮大小
        $localeCatalog = $this->runtimeProviderResolver->resolve(BackendLocaleCatalogInterface::class);
        $languages = $localeCatalog instanceof BackendLocaleCatalogInterface
            ? $localeCatalog->selectable(Cookie::getLangLocal(), 24, 18, true)
            : [];
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

    public function getAvatar()
    {
        $user = $this->getUser();
        $avatar = $user->getAvatar();

        // 1. 用户自己上传了头像，直接返回
        if (!empty($avatar)) {
            return $avatar;
        }


        // 未上传头像时使用同源静态资源，保持严格 CSP（不依赖 data URI）。
        // 旧版本曾在读取路径把不存在的 logo.jpg 写入配置；只读兼容该历史值，
        // 不在页面渲染期间修改 SystemConfig。
        $avatar = trim((string)$this->config->getConfig('admin_default_avatar', 'Weline_Admin'));
        if ($avatar === '' || $avatar === 'Weline_Admin::img/logo.jpg') {
            $avatar = 'Weline_Admin::img/logo.png';
        }

        return Template::getInstance()->fetchTagSourceFile(DataInterface::view_STATICS_DIR, $avatar);
    }


    public function getUser(): BackendUserContext
    {
        $this->session = SessionFactory::getInstance()->createBackendSession();
        $cacheKey = (string)($this->session->getUserId() ?? 0) . '|' . (string)($this->session->getUsername() ?? '');
        if ($this->user instanceof BackendUserContext && $this->userCacheKey === $cacheKey) {
            return $this->user;
        }

        $provider = $this->runtimeProviderResolver->resolve(BackendUserContextProviderInterface::class);
        $this->user = $provider instanceof BackendUserContextProviderInterface
            ? $provider->current()
            : null;
        $this->user ??= new BackendUserContext(0, 'Guest', '', '', 0, false, false);
        $this->userCacheKey = $cacheKey;

        return $this->user;
    }
}
