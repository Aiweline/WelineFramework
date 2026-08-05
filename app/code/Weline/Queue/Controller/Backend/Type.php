<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：18/7/2023 10:36:12
 */

namespace Weline\Queue\Controller\Backend;

use Weline\Acl\Api\Authorization\AccessMode;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Response;

#[Acl('Weline_Queue::type_manager', '队列类型管理', 'mdi mdi-shape-outline', '管理队列类型', 'Weline_Queue::message_service')]
class Type extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Queue\Model\Queue\Type $type;

    public function __construct(
        \Weline\Queue\Model\Queue\Type $type,
    ) {
        $this->type = $type;
    }

    #[Acl('Weline_Queue::type_index', '队列类型列表', 'mdi mdi-format-list-bulleted', '查看队列类型列表')]
    public function index()
    {
        $this->assign('title', __('队列类型'));
        if ($search = $this->request->getGet('q')) {
            $this->type->where('concat(name,type_id)', "%$search%", 'LIKE');
        }
        $this->type->pagination()->select()->fetch();
        $this->assign('types', $this->type->getItems());
        $this->assign('pagination', $this->type->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Queue::type_manage', '启停队列类型', 'mdi mdi-toggle-switch', '启用或禁用队列类型', accessMode: AccessMode::EDIT)]
    public function enable()
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::type_manage', '启停队列类型', 'mdi mdi-toggle-switch', '启用或禁用队列类型', accessMode: AccessMode::EDIT)]
    public function disable()
    {
        return $this->legacyMutationGone();
    }

    private function legacyMutationGone(): Response
    {
        return Response::json([
            'code' => 410,
            'success' => false,
            'msg' => (string)__('旧 Queue 类型控制接口已停用，请使用后台页面操作。'),
        ], 410);
    }

    #[Acl('Weline_Queue::type_show', '查看队列类型', 'mdi mdi-eye', '查看队列类型详情')]
    function show()
    {
        $this->layoutType = 'default.blank';
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $this->getMessageManager()->addWarning(__('请选择要查看的队列类型'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('请选择要查看的队列类型'), 'reload' => 1]);
        }
        $this->type->load($id);
        if (!$this->type->getId()) {
            $this->getMessageManager()->addWarning(__('队列类型不存在'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列类型不存在'), 'reload' => 0]);
        }
        $this->assign('type', $this->type);
        return $this->fetch();
    }
}
