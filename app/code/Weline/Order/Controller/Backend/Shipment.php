<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Service\OrderTradeAdminCommandException;
use Weline\Order\Service\OrderTradeAdminCommandService;

#[Acl('Weline_Order::shipment_controller', '订单发货控制器', 'mdi-truck', '订单仓维履约管理', 'Weline_Backend::order_group')]
final class Shipment extends BackendController
{
    use OrderObjectAuthorizationTrait;

    private readonly OrderTradeAdminCommandService $commands;

    public function __construct(ObjectManager $objectManager)
    {
        $this->commands = $objectManager->getInstance(OrderTradeAdminCommandService::class);
    }

    #[Acl('Weline_Order::shipment_manage', '查看订单发货', 'mdi-format-list-bulleted', '查看可履约单元和进度', 'Weline_Backend::order_group')]
    public function index(): string
    {
        $candidates = [];
        foreach ($this->commands->shipmentCandidates() as $row) {
            $grant = $this->orderActionGrant((int)$row['order_id'], ObjectAction::FULFILL);
            if (!$grant['allowed']) {
                continue;
            }
            $candidates[] = $row + ['expected_grant_version' => $grant['grant_version']];
        }
        $progress = [];
        foreach ($this->commands->shipmentProgress() as $row) {
            if ($this->orderActionGrant((int)$row['order_id'], ObjectAction::VIEW)['allowed']) {
                $progress[] = $row;
            }
        }
        $this->assign('candidates', $candidates);
        $this->assign('progress', $progress);

        return $this->fetch();
    }

    #[Acl('Weline_Order::shipment_execute', '提交发货', 'mdi-truck-fast', '按仓维 CAS 提交部分或全部发货', 'Weline_Order::shipment_manage')]
    public function execute(): mixed
    {
        $unitUuid = trim((string)$this->request->getPost('fulfillment_unit_uuid', ''));
        try {
            $context = $this->commands->shipmentContext($unitUuid);
            $this->requireOrderSubmit((int)$context['order_id'], ObjectAction::FULFILL);
            $result = $this->commands->ship(
                $unitUuid,
                (int)$this->request->getPost('qty_minor', 0),
                (int)$this->request->getPost('expected_version', -1),
                (string)$this->request->getPost('idempotency_key', ''),
            );
            $this->getMessageManager()->addSuccess((string)__(
                !empty($result['replayed']) ? '发货命令已幂等重放' : '发货进度已提交',
            ));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($exception->errorCode());
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('发货操作失败，请稍后重试。'));
        }

        return $this->redirect('weline_order/backend/shipment/index');
    }
}
