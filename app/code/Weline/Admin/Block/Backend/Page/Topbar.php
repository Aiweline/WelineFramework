<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

use Weline\Admin\Session\AdminSession;
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
    private AdminSession $session;
    private ?BackendUser $user = null;

    public function __construct(Config $config, AdminSession $session, array $data = [])
    {
        $this->config  = $config;
        $this->session = $session;
        parent::__construct($data);
        $this->getUser();
    }

    public function __init()
    {
        parent::__init();
        $languages = $this->getI18n()->getLocalesWithFlagsDisplaySelf(Cookie::getLangLocal(), 0, 22);
        $this->assign('languages', $languages);
        $current_language = ['code' => 'zh_Hans_CN', 'name' => '中文', 'flag' => ''];
        if (isset($languages[Cookie::getLang()])) {
            $current_language         = $languages[Cookie::getLang()];
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
        /**@var BackendUser $user */
        $user   = $this->getUser();
        $avatar = $user->getAvatar();
        if (empty($avatar)) {
            if ($avatar = $this->config->getConfig('admin_default_avatar', 'Weline_Admin')) {
                $avatar = Template::getInstance()->fetchTagSourceFile(DataInterface::view_STATICS_DIR, $avatar);
            }
        }
        return $avatar;
    }

    public function getUser(): BackendUser|AbstractModel
    {
        if (empty($this->user)) {
            $this->user = $this->session->getLoginUser();
        }
        return $this->user;
    }
}
