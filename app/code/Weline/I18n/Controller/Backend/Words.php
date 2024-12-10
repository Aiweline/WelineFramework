<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 21:40:22
 */

namespace Weline\I18n\Controller\Backend;

use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;
use Symfony\Component\Intl\Scripts;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

class Words extends \Weline\Framework\App\Controller\BackendController
{
    public function get()
    {
        /**@var I18n $I18n */
        $I18n        = ObjectManager::getInstance(I18n::class);
        $locals      = $I18n->getLocals(Cookie::getLangLocal());
        $need_locals = [];
        $cache_key   = 'getLocals' . 'need_locals' . Cookie::getLangLocal();
        if ($data = $I18n->i18nCache->get($cache_key)) {
            $need_locals = $data;
        } else {
            foreach ($locals as $code => $local_name) {
                $need_locals[] = [
                    Locals::fields_ID          => $code,
                    Locals::fields_TARGET_CODE => Cookie::getLangLocal(),
                    Locals::fields_NAME        => $local_name,
                    Locals::fields_FLAG        => $I18n->getCountryFlagWithLocal($code)['flag'] ?? '',
                ];
            }
        }
        $I18n->i18nCache->set($cache_key, $need_locals, 0);
        /**@var Locals $localsModel */
        $localsModel = ObjectManager::getInstance(Locals::class);
        $localsModel->insert($need_locals, Locals::fields_ID)->fetch();
        $localsModel->clearData();
        $locals = $localsModel->where(Locals::fields_TARGET_CODE, Cookie::getLangLocal())->pagination()->select()->fetch();
        $this->assign('locals', $locals->getItems());
        $this->assign('pagination', $locals->getPagination());
        $target_local = $localsModel->where(Locals::fields_CODE, Cookie::getLangLocal())->find()->fetch();
        $this->assign('target_local', $target_local);
        return $this->fetch();
    }
}
