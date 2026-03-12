<?php
declare(strict_types=1);

/**
 * Weline_Server 模块事件规约
 * 
 * 按照国际标准设计的事件契约，使用事件解耦模块间依赖
 * 
 * 事件命名规范：
 * - 格式：模块名::事件类型::事件名称
 * - 示例：Weline_Server::domain::certificate_issued
 * 
 * 事件类型：
 * - domain: 领域事件（Domain Events）业务领域内的事件
 * - integration: 集成事件（Integration Events）跨模块/系统的事件
 * - application: 应用事件（Application Events）应用层事件
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

return [
    // ========== Domain Events (领域事件) ==========
    
    /**
     * 证书签发完成事件
     * 当 SSL 证书成功签发后触发
     */
    'Weline_Server::domain::certificate_issued' => [
        'name' => __('证书签发完成'),
        'description' => __('当 SSL 证书成功签发后触发，允许其他模块监听并处理证书签发后的逻辑，如同步 HTTPS 状态。'),
        'doc' => 'domain/certificate_issued.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'domain' => ['type' => 'string', 'required' => true, 'description' => '证书绑定的域名'],
            'cert_id' => ['type' => 'integer', 'required' => true, 'description' => '证书 ID'],
            'cert_path' => ['type' => 'string', 'required' => true, 'description' => '证书文件路径'],
            'key_path' => ['type' => 'string', 'required' => true, 'description' => '私钥文件路径'],
            'issuer' => ['type' => 'string', 'required' => true, 'description' => '证书颁发者'],
            'expires_at' => ['type' => 'string', 'required' => true, 'description' => '证书过期时间'],
            'cert_type' => ['type' => 'string', 'required' => true, 'description' => '证书类型 (exact/wildcard)'],
        ],
    ],
    
    /**
     * 证书禁用事件
     * 当 HTTPS 被禁用或证书失效时触发
     */
    'Weline_Server::domain::certificate_disabled' => [
        'name' => __('证书禁用'),
        'description' => __('当 HTTPS 被禁用或证书失效时触发，允许其他模块监听并回退 HTTPS 状态。'),
        'doc' => 'domain/certificate_disabled.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'domain' => ['type' => 'string', 'required' => true, 'description' => '域名'],
            'cert_id' => ['type' => 'integer', 'required' => false, 'description' => '证书 ID（如有）'],
            'reason' => ['type' => 'string', 'required' => false, 'description' => '禁用原因'],
        ],
    ],
    
    /**
     * 证书删除事件
     * 当 SSL 证书被删除（含批量删除、ssl:reload --clear 清理）时触发
     */
    'Weline_Server::domain::certificate_deleted' => [
        'name' => __('证书已删除'),
        'description' => __('当 SSL 证书被删除时触发，允许其他模块清除关联的 HTTPS 状态、可建站状态等。'),
        'doc' => 'domain/certificate_deleted.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'domain' => ['type' => 'string', 'required' => true, 'description' => '被删除证书的域名'],
            'cert_id' => ['type' => 'integer', 'required' => false, 'description' => '证书 ID（如有）'],
            'reason' => ['type' => 'string', 'required' => false, 'description' => '删除原因'],
        ],
    ],

    /**
     * 证书更新事件
     * 当证书续签或更新时触发
     */
    'Weline_Server::domain::certificate_renewed' => [
        'name' => __('证书更新'),
        'description' => __('当 SSL 证书续签或更新时触发，允许其他模块监听并处理证书更新后的逻辑。'),
        'doc' => 'domain/certificate_renewed.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'domain' => ['type' => 'string', 'required' => true, 'description' => '域名'],
            'cert_id' => ['type' => 'integer', 'required' => true, 'description' => '证书 ID'],
            'old_expires_at' => ['type' => 'string', 'required' => false, 'description' => '旧过期时间'],
            'new_expires_at' => ['type' => 'string', 'required' => true, 'description' => '新过期时间'],
        ],
    ],
    
    // ========== Integration Events (集成事件) ==========
    
    // ========== Service Events (服务层事件) ==========
    
    /**
     * Master 复活失败事件
     * 当 WLS 子进程尝试复活 Master 三次均失败时触发，用于向后台报错、等待人工干预
     */
    'Weline_Server::service::master_resurrection_failed' => [
        'name' => __('WLS Master 复活失败'),
        'description' => __('当 Master 进程异常退出后，子进程尝试复活三次均失败时触发；Worker 继续服务，需人工执行 server:start 或 server:restart。'),
        'doc' => 'service/master_resurrection_failed.md',
        'version' => '1.0.0',
        'type' => 'application',
        'data_contract' => [
            'instance_name' => ['type' => 'string', 'required' => true, 'description' => 'WLS 实例名称'],
            'attempts' => ['type' => 'integer', 'required' => true, 'description' => '复活尝试次数'],
            'message' => ['type' => 'string', 'required' => true, 'description' => '异常描述'],
        ],
    ],
    
    // ========== Integration Events (集成事件) ==========
    
    /**
     * 请求域名列表事件
     * 当 Server 模块需要获取可选域名列表时触发
     */
    'Weline_Server::integration::domain_list_requested' => [
        'name' => __('请求域名列表'),
        'description' => __('当 Server 模块需要获取可选域名列表时触发，允许其他模块（如 Websites）提供域名数据。'),
        'doc' => 'integration/domain_list_requested.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'filter' => ['type' => 'array', 'required' => false, 'description' => '过滤条件'],
            'domains' => ['type' => 'array', 'required' => false, 'description' => '域名列表（由观察者填充）'],
        ],
    ],
    'Weline_Server::integration::security_rules_updated' => [
        'name' => __('安全规则更新'),
        'description' => __('当 WLS 安全规则保存后触发，允许 CDN 模块同步边缘防护规则。'),
        'doc' => 'integration/security_rules_updated.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'rules' => ['type' => 'array', 'required' => true, 'description' => '保存时提交的规则'],
            'merged_rules' => ['type' => 'array', 'required' => true, 'description' => '合并默认值后的完整规则'],
            'instance' => ['type' => 'string', 'required' => false, 'description' => '实例名'],
        ],
    ],
];
