<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentObjectScopeService;
use Weline\Payment\Service\PaymentTransactionAccessService;

#[Acl('Weline_Payment::payment_transaction', '支付交易管理', 'cash', '支付交易记录管理', 'Weline_Backend::payment_group')]
class Transaction extends BackendController
{
    /**
     * 交易记录列表页
     */
    #[Acl('Weline_Payment::payment_transaction_index', '查看交易记录', 'list', '查看支付交易记录列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        $status = $this->request->getParam('status');
        $methodCode = $this->request->getParam('method_code');
        
        /** @var PaymentTransaction $transaction */
        $transaction = ObjectManager::getInstance(PaymentTransaction::class);
        $query = $transaction->select();
        
        if ($keyword) {
            $query->where(PaymentTransaction::schema_fields_TRANSACTION_NO, 'like', "%{$keyword}%")
                ->orWhere(PaymentTransaction::schema_fields_ORDER_ID, 'like', "%{$keyword}%");
        }
        
        if ($status) {
            $query->where(PaymentTransaction::schema_fields_STATUS, $status);
        }
        
        if ($methodCode) {
            $query->where(PaymentTransaction::schema_fields_METHOD_CODE, $methodCode);
        }
        
        $query->order(PaymentTransaction::schema_fields_CREATED_AT, 'DESC')->fetch();
        $candidates = $transaction->getItems();
        $transactions = [];
        $replayGrantVersions = [];
        $scopeService = ObjectManager::getInstance(PaymentObjectScopeService::class);
        $guard = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof PaymentTransaction) {
                continue;
            }
            try {
                $scope = $scopeService->fromPersistedScope(
                    (string)$candidate->getData(PaymentTransaction::schema_fields_SCOPE),
                );
            } catch (\Throwable) {
                continue;
            }
            if (!$guard->isAllowed(ObjectAction::LIST, $scope)) {
                continue;
            }
            $transactions[] = $candidate;
            $replayGrant = $guard->check(ObjectAction::REPLAY, $scope);
            if ($replayGrant->allowed) {
                $replayGrantVersions[(int)$candidate->getId()] = $replayGrant->matchedGrantVersion;
            }
        }
        $total = \count($transactions);
        $totalPages = (int)ceil($total / $limit);
        $transactions = \array_slice($transactions, ($page - 1) * $limit, $limit);
        
        $this->assign('transactions', $transactions);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('keyword', $keyword);
        $this->assign('status', $status);
        $this->assign('method_code', $methodCode);
        $this->assign('replay_grant_versions', $replayGrantVersions);
        
        return $this->fetch();
    }

    /**
     * 查看交易详情
     */
    #[Acl('Weline_Payment::payment_transaction_view', '查看交易详情', 'eye', '查看支付交易详情')]
    public function view()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('缺少交易ID'));
            return $this->redirect('*/backend/transaction/index');
        }
        
        $access = ObjectManager::getInstance(PaymentTransactionAccessService::class);
        $record = $access->find((int)$id);
        try {
            if ($record === null) {
                $this->objectAuthorizationGuard()->denyForQuery(
                    ObjectAction::VIEW,
                    \Weline\Framework\Runtime\ScopeIdentity::global(),
                );
            }
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::VIEW, $record['scope']);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        $transaction = $record['transaction'];
        
        $this->assign('transaction', $transaction);
        
        return $this->fetch();
    }

    /**
     * 查询支付状态
     */
    #[Acl('Weline_Payment::payment_transaction_query', '查询支付状态', 'refresh', '查询支付状态')]
    public function query()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            return $this->error(__('缺少交易ID'));
        }
        
        try {
            $access = ObjectManager::getInstance(PaymentTransactionAccessService::class);
            $record = $access->find((int)$id);
            if ($record === null) {
                $this->objectAuthorizationGuard()->denyForQuery(
                    ObjectAction::REPLAY,
                    \Weline\Framework\Runtime\ScopeIdentity::global(),
                );
            }
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::REPLAY,
                $record['scope'],
                $this->expectedGrantVersion(),
            );
            $access->queryStatus($record['transaction']);
            
            return $this->success(__('支付状态查询成功'));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        } catch (\Throwable) {
            return $this->error(__('支付状态查询失败'));
        }
    }

    private function objectAuthorizationGuard(): BackendObjectAuthorizationGuardInterface
    {
        return ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
    }

    private function expectedGrantVersion(): int
    {
        $value = $this->request->getParam('expected_grant_version', 0);
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
