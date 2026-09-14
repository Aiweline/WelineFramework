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

#[Acl('Weline_Order::shipment_controller', '订单发货控制器', 'truck', '订单仓维履约管理', 'Weline_Backend::order_group')]
final class Shipment extends BackendController
{
    use OrderObjectAuthorizationTrait;

    private readonly OrderTradeAdminCommandService $commands;

    public function __construct(ObjectManager $objectManager)
    {
        $this->commands = $objectManager->getInstance(OrderTradeAdminCommandService::class);
    }

    #[Acl('Weline_Order::shipment_manage', '查看订单发货', 'list', '查看可履约单元和进度', 'Weline_Backend::order_group')]
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

    #[Acl('Weline_Order::shipment_execute', '提交发货', 'truck', '按仓维 CAS 提交部分或全部发货', 'Weline_Order::shipment_manage')]
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
                [
                    'tracking_number' => (string)$this->request->getPost('tracking_number', ''),
                    'carrier' => (string)$this->request->getPost('carrier', ''),
                    'notify_customer' => $this->request->getPost('notify_customer') !== null
                        && (string)$this->request->getPost('notify_customer') !== '0'
                        && (string)$this->request->getPost('notify_customer') !== '',
                ],
            );
            $notified = !empty($result['logistics']['notify_customer']);
            if (!empty($result['replayed'])) {
                $this->getMessageManager()->addSuccess((string)__('发货命令已幂等重放'));
            } elseif ($notified) {
                $this->getMessageManager()->addSuccess(
                    (string)__('发货已提交；已按勾选尝试发送「已发货」邮件给客户'),
                );
            } else {
                $this->getMessageManager()->addSuccess(
                    (string)__('发货已提交（未勾选通知客户，未发邮件）'),
                );
            }
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($this->humanizeShipError($exception->errorCode()));
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('发货操作失败，请稍后重试。'));
        }

        $returnUrl = trim((string)$this->request->getPost('return_url', ''));
        if ($returnUrl !== '' && preg_match('#^order/backend/order/edit\?id=[1-9][0-9]*(?:&|$)#', $returnUrl) === 1) {
            return $this->redirect($returnUrl);
        }

        return $this->redirect('order/backend/shipment/index');
    }

    private function humanizeShipError(string $code): string
    {
        return match ($code) {
            'shipment_tracking_required' => (string)__('请填写平台物流单号后再发货'),
            'shipment_tracking_too_long' => (string)__('物流单号过长，请控制在 100 字以内'),
            'shipment_carrier_too_long' => (string)__('承运商名称过长，请控制在 100 字以内'),
            default => $code !== '' ? $code : (string)__('发货操作失败，请稍后重试。'),
        };
    }
}
