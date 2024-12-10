<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\Framework\Manager\ObjectManager;

class Document extends \Weline\Framework\App\Controller\FrontendController
{
    public function index()
    {
        /**@var \Weline\DeveloperWorkspace\Model\Document $document*/
        $document = ObjectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document::class);
        $this->assign('document', $document->load($this->request->getParam('id')));
        return $this->fetch();
    }
}
