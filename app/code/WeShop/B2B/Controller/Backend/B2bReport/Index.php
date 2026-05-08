<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\B2bReport;

use WeShop\B2B\Model\Credit;
use WeShop\B2B\Model\Receivable;
use WeShop\B2B\Service\ReceivableService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('WeShop_B2B::b2b_report', 'B2B AR Summary', 'mdi mdi-chart-box-outline', 'View B2B AR summary report', 'Weline_Backend::customer_group')]
class Index extends BaseController
{
    #[Acl('WeShop_B2B::b2b_report_index', 'View B2B AR summary', 'mdi mdi-chart-line', 'View B2B AR summary report page')]
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
