<?php

declare(strict_types=1);

namespace Weline\Newsletter\Controller\Backend\Subscriber;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Newsletter\Model\Subscriber;

#[Acl('Weline_Newsletter::subscriber', '邮件订阅名单', 'mdi-email-newsletter', '查看邮件订阅名单与发券台账', 'Weline_Backend::marketing_group')]
final class Index extends BackendController
{
    #[Acl('Weline_Newsletter::subscriber_index', '邮件订阅名单列表', 'mdi-format-list-bulleted', '查看邮件订阅名单')]
    public function index(): string
    {
        $page = \max(1, (int)$this->request->getGet('page', 1));
        $pageSize = 30;
        $search = \trim((string)$this->request->getGet('search', ''));

        /** @var Subscriber $model */
        $model = ObjectManager::getInstance(Subscriber::class);
        $query = $model->clear()->order(Subscriber::schema_fields_CREATED_AT, 'DESC');
        if ($search !== '') {
            $query->where(Subscriber::schema_fields_EMAIL, '%' . $search . '%', 'like');
        }
        $total = (int)$query->total();
        $items = $query->page($page, $pageSize)->select()->fetch();
        $rows = [];
        if (\is_iterable($items)) {
            foreach ($items as $row) {
                if ($row instanceof Subscriber) {
                    $rows[] = $row->getData();
                } elseif (\is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        $this->assign('items', $rows);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('search', $search);
        $this->assign('listing_url', $this->getUrl('newsletter/backend/subscriber/index'));

        return $this->fetch('Weline_Newsletter::templates/backend/subscriber/index.phtml');
    }
}
