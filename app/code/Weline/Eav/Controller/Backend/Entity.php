<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/22 20:54:47
 */

namespace Weline\Eav\Controller\Backend;

use Weline\Eav\Model\EavEntity;
use Weline\Framework\Http\Cookie;

class Entity extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * @var \Weline\Eav\Model\EavEntity
     */
    private EavEntity $eavEntity;

    function __construct(
        EavEntity $eavEntity
    )
    {
        $this->eavEntity = $eavEntity;
    }

    function __init()
    {
        parent::__init();
        $this->eavEntity->addLocalDescription();
    }

    function index()
    {
        if ($search = $this->request->getGet('search')) {
            $this->eavEntity->where('concat(main_table.code,main_table.name,main_table.class,local.name)', "%$search%", 'like');
        }
        $entities = $this->eavEntity->pagination()->select()->fetchOrigin();
        $this->assign('entities', $entities);
        $this->assign('pagination', $this->eavEntity->getPagination());
        return $this->fetch();
    }

    function search(): string
    {
        if ($search = $this->request->getGet('search')) {
            $this->eavEntity->where('concat(code,name,class)', "%$search%", 'like');
        }
        return $this->fetchJson(['items'=>$this->eavEntity->select()->fetchOrigin()]);
    }
}