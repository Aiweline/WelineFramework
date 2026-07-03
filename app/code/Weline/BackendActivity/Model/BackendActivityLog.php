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
#[Index(name: 'idx_business_entity', columns: ['business_module', 'business_entity_type', 'business_entity_id'], comment: '业务实体索引')]
#[Index(name: 'idx_business_action', columns: ['business_module', 'business_action'], comment: '业务动作索引')]
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
    const fields_business_module = 'business_module';
    const fields_business_entity_type = 'business_entity_type';
    const fields_business_entity_id = 'business_entity_id';
    const fields_business_action = 'business_action';
    const fields_business_title = 'business_title';
    const fields_business_payload = 'business_payload';

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
    #[Col('varchar', 100, true, false, false, null, '业务模块')]
    public const schema_fields_business_module = 'business_module';
    #[Col('varchar', 100, true, false, false, null, '业务实体类型')]
    public const schema_fields_business_entity_type = 'business_entity_type';
    #[Col('varchar', 64, true, false, false, null, '业务实体ID')]
    public const schema_fields_business_entity_id = 'business_entity_id';
    #[Col('varchar', 80, true, false, false, null, '业务动作')]
    public const schema_fields_business_action = 'business_action';
    #[Col('varchar', 255, true, false, false, null, '业务标题')]
    public const schema_fields_business_title = 'business_title';
    #[Col('text', null, true, false, false, null, '业务上下文 JSON')]
    public const schema_fields_business_payload = 'business_payload';
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
    function setBusinessModule(string $businessModule):static
    {
        return $this->setData(self::schema_fields_business_module, $businessModule);
    }
    function getBusinessModule():string
    {
        return (string)$this->getData(self::schema_fields_business_module);
    }
    function setBusinessEntityType(string $businessEntityType):static
    {
        return $this->setData(self::schema_fields_business_entity_type, $businessEntityType);
    }
    function getBusinessEntityType():string
    {
        return (string)$this->getData(self::schema_fields_business_entity_type);
    }
    function setBusinessEntityId(string|int $businessEntityId):static
    {
        return $this->setData(self::schema_fields_business_entity_id, (string)$businessEntityId);
    }
    function getBusinessEntityId():string
    {
        return (string)$this->getData(self::schema_fields_business_entity_id);
    }
    function setBusinessAction(string $businessAction):static
    {
        return $this->setData(self::schema_fields_business_action, $businessAction);
    }
    function getBusinessAction():string
    {
        return (string)$this->getData(self::schema_fields_business_action);
    }
    function setBusinessTitle(string $businessTitle):static
    {
        return $this->setData(self::schema_fields_business_title, $businessTitle);
    }
    function getBusinessTitle():string
    {
        return (string)$this->getData(self::schema_fields_business_title);
    }
    function setBusinessPayload(string|array|null $businessPayload):static
    {
        if (is_array($businessPayload)) {
            $businessPayload = json_encode($businessPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        return $this->setData(self::schema_fields_business_payload, $businessPayload);
    }
    function getBusinessPayload():string
    {
        return (string)$this->getData(self::schema_fields_business_payload);
    }
}
