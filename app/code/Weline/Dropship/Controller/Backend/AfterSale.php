<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Dropship::commerce:dropship:aftersale', '售后异常', 'alert', '货源售后异常', 'Weline_Dropship::commerce:dropship:group')]
class AfterSale extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:aftersale_index', '查看售后异常', 'alert', '查看出库失败与跳过')]
    public function index(): string
    {
        $providerFilter = trim((string)$this->request->getGet('provider', ''));
        /** @var DropshipPushOutbox $o */
        $o = ObjectManager::getInstance(DropshipPushOutbox::class);
        $q = $o->clear()
            ->where(DropshipPushOutbox::schema_fields_STATUS, [DropshipPushOutbox::STATUS_ERROR, DropshipPushOutbox::STATUS_SKIPPED], 'IN');
        if ($providerFilter !== '') {
            $q->where(DropshipPushOutbox::schema_fields_PROVIDER_CODE, $providerFilter);
        }
        $rows = $q->order(DropshipPushOutbox::schema_fields_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray();
        $this->assign('page_title', __('售后异常'));
        $this->assign('rows', $rows ?: []);
        $this->assign('provider_filter', $providerFilter);

        return $this->fetch();
    }
}
