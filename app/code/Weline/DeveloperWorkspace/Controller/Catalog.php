<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\DeveloperWorkspace\Helper\Data;
use Weline\Framework\Manager\ObjectManager;

class Catalog extends BaseController
{
    private \Weline\DeveloperWorkspace\Model\Document\Catalog $catalog;

    public function __construct(
        \Weline\DeveloperWorkspace\Model\Document\Catalog $catalog
    ) {
        parent::__construct();
        $this->catalog = $catalog;
    }

    public function index()
    {
        $id = $this->request->getParam('id');
        $this->assign('catalog', $this->catalog->load($id));
        $this->assign('documents', Data::getDocumentsByCategoryId(intval($id)));
        return $this->fetch();
    }
}
