<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：29/3/2024 10:57:29
 */

namespace Weline\BackendActivity\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class BackendActivityLog extends Model
{
    const fields_ID = 'backend_activity_log_id';
    const fields_backend_activity_log_id = 'backend_activity_log_id';
    const fields_request_id = 'request_id';
    const fields_name = 'name';
    const fields_user_id = 'user_id';
    const fields_acl_id = 'acl_id';

    const fields_request_method = 'request_method';
    const fields_request_params = 'request_params';
    const fields_request_data = 'request_data';
    const fields_host = 'host';
    const fields_path = 'path';
    const fields_module = 'module';
    const fields_url = 'url';
    const fields_ip = 'ip';
    const fields_user_agent = 'user_agent';
    const fields_response = 'response';
    const fields_response_code = 'response_code';
    const fields_response_time = 'response_time';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '日志ID')
                ->addColumn(self::fields_request_id, TableInterface::column_type_VARCHAR, 255, 'unique', '请求ID')
                ->addColumn(self::fields_acl_id, TableInterface::column_type_INTEGER, 11, 'not null default 0', '访问控制列表ID')
                ->addColumn(self::fields_host, TableInterface::column_type_VARCHAR, 255, 'not null', '请求主机')
                ->addColumn(self::fields_path, TableInterface::column_type_VARCHAR, 255, 'not null', '请求路径')
                ->addColumn(self::fields_module, TableInterface::column_type_VARCHAR, 255, 'not null', '模块')
                ->addColumn(self::fields_url, TableInterface::column_type_VARCHAR, 1024, 'not null', 'url')
                ->addColumn(self::fields_request_method, TableInterface::column_type_VARCHAR, 6, 'not null', '请求方法')
                ->addColumn(self::fields_request_params, TableInterface::column_type_TEXT, null, 'not null', '请求参数')
                ->addColumn(self::fields_request_data, TableInterface::column_type_TEXT, null, 'not null', '请求数据')
                ->addColumn(self::fields_ip, TableInterface::column_type_VARCHAR, 128, 'not null', 'ip')
                ->addColumn(self::fields_user_agent, TableInterface::column_type_VARCHAR, 255, 'not null', 'user_agent')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
                ->addColumn(self::fields_response, TableInterface::column_type_VARCHAR, 255, "default ''", '响应数据')
                ->addColumn(self::fields_response_code, TableInterface::column_type_INTEGER, 11, "default 0", '响应状态码')
                ->addColumn(self::fields_response_time, TableInterface::column_type_VARCHAR, 32, "default 0", '响应时间')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '日志名称')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_path', self::fields_path, '路径')
                ->addIndex(TableInterface::index_type_KEY, 'idx_ip', self::fields_ip, 'IP')
                ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_module, '模块')
                ->addIndex(TableInterface::index_type_KEY, 'idx_response_time', self::fields_response_time, '响应时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_response_code', self::fields_response_code, '响应状态码')
                ->create();
        }
    }

    function setRequestId(string $requestId):static
    {
        return $this->setData(self::fields_request_id, $requestId);
    }

    function getRequestId():string
    {
        return $this->getData(self::fields_request_id);
    }


    function setUserId(int $userId):static
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    function getUserId():int
    {
        return $this->getData(self::fields_user_id);
    }


    function setName(string $name):static
    {
        return $this->setData(self::fields_name, $name);
    }

    function getName():string
    {
        return $this->getData(self::fields_name);
    }

    function setAclId(int $aclId):static
    {
        return $this->setData(self::fields_acl_id, $aclId);
    }

    function getAclId():int
    {
        return intval($this->getData(self::fields_acl_id));
    }

    function setPath(string $path):static
    {
        return $this->setData(self::fields_path, $path);
    }

    function getPath():string
    {
        return $this->getData(self::fields_path);
    }

    function setModule(string $module):static
    {
        return $this->setData(self::fields_module, $module);
    }

    function getModule():string
    {
        return $this->getData(self::fields_module);
    }

    function setHost(string $host):static
    {
        return $this->setData(self::fields_host, $host);
    }

    function getHost():string
    {
        return $this->getData(self::fields_host);
    }

    function setUrl(string $url):static
    {
        return $this->setData(self::fields_url, $url);
    }

    function getUrl():string
    {
        return $this->getData(self::fields_url);
    }

    function setRequestMethod(string $requestMethod):static
    {
        return $this->setData(self::fields_request_method, $requestMethod);
    }

    function getRequestMethod():string
    {
        return $this->getData(self::fields_request_method);
    }

    function setRequestParams(string|array $requestParams):static
    {
        if(is_array($requestParams))
        {
            $requestParams = json_encode($requestParams);
        }
        return $this->setData(self::fields_request_params, $requestParams);
    }

    function getRequestParams():string
    {
        return $this->getData(self::fields_request_params);
    }

    function setRequestData(string|array $requestData):static
    {
        if(is_array($requestData))
        {
            $requestData = json_encode($requestData);
        }
        return $this->setData(self::fields_request_data, $requestData);
    }

    function getRequestData():string
    {
        return $this->getData(self::fields_request_data);
    }

    function setIp(string $ip):static
    {
        return $this->setData(self::fields_ip, $ip);
    }

    function getIp():string
    {
        return $this->getData(self::fields_ip);
    }

    function setUserAgent(string $userAgent):static
    {
        return $this->setData(self::fields_user_agent, $userAgent);
    }

    function getUserAgent():string
    {
        return $this->getData(self::fields_user_agent);
    }

    function setResponse(string $response):static
    {
        return $this->setData(self::fields_response, $response);
    }

    function getResponse():string
    {
        return $this->getData(self::fields_response);
    }

    function setResponseCode(int $responseCode):static
    {
        return $this->setData(self::fields_response_code, $responseCode);
    }

    function getResponseCode():int
    {
        return $this->getData(self::fields_response_code);
    }

    function setResponseTime(float|int $responseTime):static
    {
        return $this->setData(self::fields_response_time, $responseTime);
    }

    function getResponseTime():int|float
    {
        return $this->getData(self::fields_response_time);
    }
}
