<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/2 14:14:05
 */

namespace Weline\I18n\Controller\Backend;

use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;

class Dictionary extends BaseController
{
    private \Weline\I18n\Model\Dictionary $dictionary;

    function __construct(Locale $locale, I18n $i18n, \Weline\I18n\Model\Dictionary $dictionary)
    {
        parent::__construct($locale, $i18n);
        $this->dictionary = $dictionary;
    }

    function get()
    {
        $this->dictionary->pagination()->select()->fetch();
        $this->assign('dictionaries', $this->dictionary->getItems());
        $this->assign('pagination', $this->dictionary->getPagination());
        return $this->fetch();
    }

    function getDelete()
    {
        $this->dictionary->beginTransaction();
        try {
            $this->dictionary->load($this->request->getGet('word'))->delete();
            $this->dictionary->commit();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (\Exception $exception) {
            $this->dictionary->rollBack();
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->request->getReferer());
    }
}