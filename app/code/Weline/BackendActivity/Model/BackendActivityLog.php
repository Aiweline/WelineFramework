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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '后台活动日志表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_path', columns: ['path'], comment: '路径')]
#[Index(name: 'idx_ip', columns: ['ip'], comment: 'IP')]
#[Index(name: 'idx_module', columns: ['module'], comment: '模块')]
#[Index(name: 'idx_response_time', columns: ['response_time'], comment: '响应时间')]
#[Index(name: 'idx_response_code', columns: ['response_code'], comment: '响应状态码')]
class BackendActivityLog extends Model
{
    public const schema_primary_key = 'backend_activity_log_id';

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

    #[Col('int', null, false, true, true, null, '主键ID')]
    public const schema_fields_ID = 'backend_activity_log_id';
    #[Col('varchar', 64, true, false, false, null, '请求ID')]
    public const schema_fields_request_id = 'request_id';
    #[Col('int', null, true, false, false, null, '用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col('varchar', 255, true, false, false, null, '活动名称')]
    public const schema_fields_name = 'name';
    #[Col('int', null, true, false, false, null, 'ACL ID')]
    public const schema_fields_acl_id = 'acl_id';
    #[Col('varchar', 10, true, false, false, null, '请求方法')]
    public const schema_fields_request_method = 'request_method';
    #[Col('text', null, true, false, false, null, '请求参数 JSON')]
    public const schema_fields_request_params = 'request_params';
    #[Col('text', null, true, false, false, null, '请求数据 JSON')]
    public const schema_fields_request_data = 'request_data';
    #[Col('varchar', 255, true, false, false, null, 'Host')]
    public const schema_fields_host = 'host';
    #[Col('varchar', 500, true, false, false, null, '路径')]
    public const schema_fields_path = 'path';
    #[Col('varchar', 100, true, false, false, null, '模块')]
    public const schema_fields_module = 'module';
    #[Col('text', null, true, false, false, null, '完整URL')]
    public const schema_fields_url = 'url';
    #[Col('varchar', 45, true, false, false, null, 'IP 地址')]
    public const schema_fields_ip = 'ip';
    #[Col('text', null, true, false, false, null, 'User-Agent')]
    public const schema_fields_user_agent = 'user_agent';
    #[Col('text', null, true, false, false, null, '响应内容')]
    public const schema_fields_response = 'response';
    #[Col('int', null, true, false, false, null, '响应状态码')]
    public const schema_fields_response_code = 'response_code';
    #[Col('decimal', '10,4', true, false, false, null, '响应时间秒')]
    public const schema_fields_response_time = 'response_time';
    function setRequestId(string $requestId):static
    {
        return $this->setData(self::schema_fields_request_id, $requestId);
    }
    function getRequestId():string
    {
        return $this->getData(self::schema_fields_request_id);
    }
    function setUserId(int $userId):static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }
    function getUserId():int
    {
        return $this->getData(self::schema_fields_user_id);
    }
    function setName(string $name):static
    {
        return $this->setData(self::schema_fields_name, $name);
    }
    function getName():string
    {
        return $this->getData(self::schema_fields_name);
    }
    function setAclId(int $aclId):static
    {
        return $this->setData(self::schema_fields_acl_id, $aclId);
    }
    function getAclId():int
    {
        return intval($this->getData(self::schema_fields_acl_id));
    }
    function setPath(string $path):static
    {
        return $this->setData(self::schema_fields_path, $path);
    }
    function getPath():string
    {
        return $this->getData(self::schema_fields_path);
    }
    function setModule(string $module):static
    {
        return $this->setData(self::schema_fields_module, $module);
    }
    function getModule():string
    {
        return $this->getData(self::schema_fields_module);
    }
    function setHost(string $host):static
    {
        return $this->setData(self::schema_fields_host, $host);
    }
    function getHost():string
    {
        return $this->getData(self::schema_fields_host);
    }
    function setUrl(string $url):static
    {
        return $this->setData(self::schema_fields_url, $url);
    }
    function getUrl():string
    {
        return $this->getData(self::schema_fields_url);
    }
    function setRequestMethod(string $requestMethod):static
    {
        return $this->setData(self::schema_fields_request_method, $requestMethod);
    }
    function getRequestMethod():string
    {
        return $this->getData(self::schema_fields_request_method);
    }
    function setRequestParams(string|array $requestParams):static
    {
        if(is_array($requestParams))
        {
            $requestParams = json_encode($requestParams);
        }
        return $this->setData(self::schema_fields_request_params, $requestParams);
    }
    function getRequestParams():string
    {
        return $this->getData(self::schema_fields_request_params);
    }
    function setRequestData(string|array $requestData):static
    {
        if(is_array($requestData))
        {
            $requestData = json_encode($requestData);
        }
        return $this->setData(self::schema_fields_request_data, $requestData);
    }
    function getRequestData():string
    {
        return $this->getData(self::schema_fields_request_data);
    }
    function setIp(string $ip):static
    {
        return $this->setData(self::schema_fields_ip, $ip);
    }
    function getIp():string
    {
        return $this->getData(self::schema_fields_ip);
    }
    function setUserAgent(string $userAgent):static
    {
        return $this->setData(self::schema_fields_user_agent, $userAgent);
    }
    function getUserAgent():string
    {
        return $this->getData(self::schema_fields_user_agent);
    }
    function setResponse(string $response):static
    {
        return $this->setData(self::schema_fields_response, $response);
    }
    function getResponse():string
    {
        return $this->getData(self::schema_fields_response);
    }
    function setResponseCode(int $responseCode):static
    {
        return $this->setData(self::schema_fields_response_code, $responseCode);
    }
    function getResponseCode():int
    {
        return $this->getData(self::schema_fields_response_code);
    }
    function setResponseTime(float|int $responseTime):static
    {
        return $this->setData(self::schema_fields_response_time, $responseTime);
    }
    function getResponseTime():int|float
    {
        return $this->getData(self::schema_fields_response_time);
    }
}
