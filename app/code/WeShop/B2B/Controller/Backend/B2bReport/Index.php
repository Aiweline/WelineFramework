<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\B2bReport;

use WeShop\B2B\Model\Credit;
use WeShop\B2B\Model\Receivable;
use WeShop\B2B\Service\ReceivableService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Index extends BaseController
{
    public function index(): string
    {
        /** @var Credit $credit */
        $credit = ObjectManager::getInstance(Credit::class);
        $creditLines = $credit->clear()->count();

        /** @var Receivable $rec */
        $rec = ObjectManager::getInstance(Receivable::class);
        $open = $rec->clear()
            ->where(Receivable::schema_fields_STATUS, [
                ReceivableService::STATUS_UNPAID,
                ReceivableService::STATUS_PARTIAL,
                ReceivableService::STATUS_OVERDUE,
            ], 'IN')
            ->count();
        $overdue = $rec->clear()
            ->where(Receivable::schema_fields_STATUS, ReceivableService::STATUS_OVERDUE)
            ->count();

        $this->assign([
            'title' => (string) __('B2B Credit & AR Summary'),
            'credit_lines' => $creditLines,
            'open_receivables' => $open,
            'overdue_receivables' => $overdue,
        ]);

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/b2b-report/index.phtml');
    }
}
