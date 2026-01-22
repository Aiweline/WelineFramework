<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * 操作记录控制器
 */

namespace Aws\Domains\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;
use Aws\Domains\Model\DomainOperation;

/**
 * 操作记录后台控制器
 */
#[AclAttribute('Aws_Domains::operation', '操作记录', 'mdi-history', '操作记录', '')]
class Operation extends BackendController
{
    private function getOperationModel(): DomainOperation
    {
        return ObjectManager::getInstance(DomainOperation::class);
    }

    /**
     * 操作记录列表
     */
    #[AclAttribute('Aws_Domains::operation_index', '查看操作记录', 'mdi-view-list', '查看操作记录')]
    public function index(): string
    {
        $page = max(1, (int)$this->request->getGet('page', 1));
        $pageSize = 20;

        $domainName = trim((string)$this->request->getGet('domain', ''));
        $operationType = trim((string)$this->request->getGet('type', ''));
        $status = trim((string)$this->request->getGet('status', ''));

        $query = $this->getOperationModel()->reset();

        if ($domainName !== '') {
            $query->where(DomainOperation::fields_DOMAIN_NAME, '%' . $domainName . '%', 'like');
        }

        if ($operationType !== '') {
            $query->where(DomainOperation::fields_OPERATION_TYPE, $operationType);
        }

        if ($status !== '') {
            $query->where(DomainOperation::fields_STATUS, $status);
        }

        // 获取总数
        $totalQuery = clone $query;
        $total = $totalQuery->count();

        // 获取分页数据
        $operations = $query->order(DomainOperation::fields_CREATED_AT, 'DESC')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->select()
            ->fetchArray();

        $totalPages = (int)ceil($total / $pageSize);

        $this->assign('operations', $operations);
        $this->assign('operation_types', DomainOperation::OPERATION_TYPES);
        $this->assign('statuses', DomainOperation::STATUSES);
        $this->assign('domain', $domainName);
        $this->assign('type', $operationType);
        $this->assign('status', $status);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('total', $total);
        $this->assign('total_pages', $totalPages);

        return $this->fetch();
    }

    /**
     * 操作详情
     */
    #[AclAttribute('Aws_Domains::operation_detail', '查看操作详情', 'mdi-information-outline', '查看操作详情')]
    public function detail(): string
    {
        $id = (int)$this->request->getGet('id');

        $operation = $this->getOperationModel()->reset()->load($id);

        if (!$operation->getId()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作记录不存在'),
            ]);
        }

        $requestData = $operation->getRequestDataArray();
        $responseData = $operation->getResponseDataArray();

        // 隐藏敏感信息
        if (isset($requestData['AuthCode'])) {
            $requestData['AuthCode'] = '******';
        }

        return $this->jsonResponse([
            'success' => true,
            'operation' => [
                'id' => $operation->getId(),
                'domain_name' => $operation->getData(DomainOperation::fields_DOMAIN_NAME),
                'operation_type' => $operation->getData(DomainOperation::fields_OPERATION_TYPE),
                'operation_type_name' => $operation->getOperationTypeDisplayName(),
                'status' => $operation->getData(DomainOperation::fields_STATUS),
                'status_name' => $operation->getStatusDisplayName(),
                'aws_operation_id' => $operation->getData(DomainOperation::fields_AWS_OPERATION_ID),
                'error_message' => $operation->getData(DomainOperation::fields_ERROR_MESSAGE),
                'operator_name' => $operation->getData(DomainOperation::fields_OPERATOR_NAME),
                'created_at' => $operation->getData(DomainOperation::fields_CREATED_AT),
                'updated_at' => $operation->getData(DomainOperation::fields_UPDATED_AT),
                'request_data' => $requestData,
                'response_data' => $responseData,
            ],
        ]);
    }

    /**
     * JSON 响应工具
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
