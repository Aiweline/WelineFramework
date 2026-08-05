<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderPayment;
use Weline\Order\Model\RefundOutbox;

#[Acl('Weline_Order::records', '订单业务记录', 'mdi-format-list-bulleted', '订单关联业务记录', 'Weline_Backend::order_group')]
final class Records extends BackendController
{
    #[Acl('Weline_Order::payment_manage', '订单收款记录', 'mdi-credit-card', '订单收款记录', 'Weline_Backend::payment_group')]
    public function payment(): string
    {
        return $this->renderRecords('payment', __('订单收款记录'), OrderPayment::class, [
            'payment_id'=>__('支付 ID'),'order_id'=>__('订单 ID'),'payment_method'=>__('支付方式'),'amount'=>__('金额'),
            'currency'=>__('货币'),'transaction_id'=>__('交易号'),'status'=>__('状态'),'created_at'=>__('创建时间'),
        ]);
    }

    #[Acl('Weline_Order::exception_manage', '订单异常与补偿', 'mdi-alert-circle', '查看订单退款补偿异常', 'Weline_Order::order_manage')]
    public function exceptions(): string
    {
        return $this->renderRecords('exception', __('订单异常与补偿'), RefundOutbox::class, [
            'outbox_id'=>__('记录 ID'),'outbox_code'=>__('补偿编号'),'refund_case_uuid'=>__('退款案例'),
            'operation'=>__('操作'),'status'=>__('状态'),'error_code'=>__('错误码'),
            'attempt_count'=>__('尝试次数'),'created_at'=>__('创建时间'),
        ], RefundOutbox::STATUS_DEAD);
    }

    /** @param class-string $modelClass @param array<string,string> $columns */
    private function renderRecords(
        string $type,
        string $title,
        string $modelClass,
        array $columns,
        string $defaultStatus = ''
    ): string
    {
        $status = trim((string)$this->request->getParam('status', $defaultStatus));
        $records = [];
        $loadError = '';
        try {
            $model = ObjectManager::getInstance($modelClass);
            if ($status !== '') {
                $model->where('status', $status);
            }
            $model->pagination()->order('created_at', 'DESC')->select()->fetch();
            foreach ($model->getItems() as $item) {
                $row = [];
                foreach (array_keys($columns) as $field) {
                    $row[$field] = $item->getData($field);
                }
                $records[] = $row;
            }
        } catch (\Throwable $exception) {
            $loadError = $exception->getMessage();
        }
        $this->assign('record_type',$type);
        $this->assign('record_title',$title);
        $this->assign('columns',$columns);
        $this->assign('records',$records);
        $this->assign('status',$status);
        $this->assign('load_error',$loadError);
        return $this->fetch('Weline_Order::templates/Backend/Records/index.phtml');
    }
}
