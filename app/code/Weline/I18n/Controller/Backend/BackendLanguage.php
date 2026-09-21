<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\Message;

#[Acl('Weline_I18n::backend_default_language', '后台默认语言', 'language', '国际化', 'Weline_Backend::i18n_group')]
class BackendLanguage extends BackendController
{
    #[Acl('Weline_I18n::backend_default_language_index', '查看后台默认语言', 'language', '查看后台默认语言')]
    public function index(): string
    {
        if ($this->request->isPost()) {
            $this->savePostedLanguage();
        }

        $this->assign('page_title', __('后台默认语言'));
        $this->assign('backend_default_language', State::resolveBackendDefaultLanguage());

        return $this->fetch();
    }

    private function savePostedLanguage(): void
    {
        $code = \trim((string)$this->request->getPost('backend_default_language', ''));
        if (!State::isLanguageCodeShape($code)) {
            Message::error(__('请选择一个有效的后台默认语言'));
            return;
        }
        $code = \str_replace('-', '_', $code);

        $saved = Env::getInstance()->setConfig('backend_default_language', $code);
        if (!$saved) {
            Message::error(__('后台默认语言保存失败'));
            return;
        }

        WelineEnv::set('backend_default_language', $code, 'backend default language');
        Message::success(__('后台默认语言已保存。地址里没有写语言时，后台使用这个语言，与网站前台默认语言无关。'));
    }
}
