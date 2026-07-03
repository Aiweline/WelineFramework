<?php
declare(strict_types=1);

namespace Weline\Trash\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Trash\Service\TrashService;

#[Acl('Weline_Trash::item', '回收站', 'mdi mdi-delete-restore', '管理回收站记录', 'Weline_Backend::system_maintenance')]
class Item extends BackendController
{
    public function __construct(
        private readonly TrashService $trashService
    ) {
    }

    #[Acl('Weline_Trash::item_listing', '回收站列表', 'mdi mdi-format-list-bulleted', '查看回收站记录')]
    public function getListing(): string
    {
        $params = $this->request->getParams();
        $result = $this->trashService->listItems([
            'code' => $params['code'] ?? '',
            'status' => $params['status'] ?? 'open',
            'search' => $params['search'] ?? '',
            'page' => $params['page'] ?? 1,
            'page_size' => $params['page_size'] ?? 20,
        ]);

        $this->assign('items', $result['items']);
        $this->assign('pagination', $result['pagination']);
        $this->assign('types', $this->trashService->listTypes());
        $this->assign('code', (string)($params['code'] ?? ''));
        $this->assign('status', (string)($params['status'] ?? 'open'));
        $this->assign('search', (string)($params['search'] ?? ''));

        return $this->fetch('listing');
    }

    #[Acl('Weline_Trash::item_detail', '回收站详情', 'mdi mdi-database-eye-outline', '查看回收站原始数据')]
    public function getDetail(): string
    {
        $trashId = (int)$this->request->getGet('trash_id', $this->request->getGet('id', 0));
        $item = $this->trashService->getItem($trashId);
        if ($item === null) {
            $this->getMessageManager()->addError(__('回收站记录不存在。'));
            return $this->redirect('trash/backend/item/listing');
        }

        $this->assign('item', $item);
        return $this->fetch('detail');
    }

    #[Acl('Weline_Trash::item_restore', '恢复回收站记录', 'mdi mdi-restore', '恢复回收站记录')]
    public function postRestore(): string
    {
        $data = $this->collectRequestData();
        $trashId = (int)($data['trash_id'] ?? $data['id'] ?? 0);

        try {
            $result = $this->trashService->restore($trashId, ['source' => 'backend']);
            if (!empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('恢复成功。')));
            } else {
                $this->getMessageManager()->addError((string)($result['message'] ?? __('恢复失败。')));
            }
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $trashId > 0
            ? $this->redirect('trash/backend/item/detail', ['trash_id' => $trashId])
            : $this->redirect('trash/backend/item/listing');
    }

    #[Acl('Weline_Trash::item_purge', '永久清理回收站记录', 'mdi mdi-delete-forever-outline', '永久清理回收站记录')]
    public function postPurge(): string
    {
        $data = $this->collectRequestData();
        $trashId = (int)($data['trash_id'] ?? $data['id'] ?? 0);

        try {
            $result = $this->trashService->purge($trashId, ['source' => 'backend']);
            if (!empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('已永久清理。')));
            } else {
                $this->getMessageManager()->addError((string)($result['message'] ?? __('永久清理失败。')));
            }
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $trashId > 0
            ? $this->redirect('trash/backend/item/detail', ['trash_id' => $trashId])
            : $this->redirect('trash/backend/item/listing');
    }

    /**
     * @return array<string,mixed>
     */
    private function collectRequestData(): array
    {
        $data = [];
        $post = $this->request->getPost();
        if (is_array($post)) {
            $data = array_merge($data, $post);
        }
        $params = $this->request->getParams();
        if (is_array($params)) {
            $data = array_merge($data, $params);
        }
        $body = $this->request->getBodyParams();
        if (is_array($body)) {
            $data = array_merge($data, $body);
        } elseif (is_string($body) && trim($body) !== '') {
            $parsed = [];
            parse_str($body, $parsed);
            if (is_array($parsed)) {
                $data = array_merge($data, $parsed);
            }
        }

        return $data;
    }
}
