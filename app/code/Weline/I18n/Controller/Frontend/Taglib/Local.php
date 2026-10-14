<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Frontend\Taglib;

use Weline\Framework\Manager\MessageManager;

/**
 * Frontend taglib local form — fail-closed (legacy online env mode removed).
 * Prefer backend Taglib/Local or storefront inline-edit session.
 *
 * @Extra type=fpc enabled=false
 */
class Local extends \Weline\Framework\App\Controller\FrontendController
{
    public function get()
    {
        MessageManager::add_error(__('店面前台本地编辑入口已关闭'));
        $this->redirect(404);

        return '';
    }

    public function post()
    {
        MessageManager::add_error(__('店面前台本地编辑入口已关闭'));
        $this->redirect(404);

        return '';
    }
}
