# IP白名单和用户代理限制实现方案

## 一、功能概述

为后端API提供IP白名单和用户代理（User-Agent）限制功能，增强API安全性，防止未授权的访问。

**重要安全要求**：完全公开接口（无Acl注解，不需要登录）不允许携带Cookie信息，以防止用户状态泄露。

## 二、功能需求

### 2.1 IP白名单功能

#### 2.1.1 功能说明
- 每个API用户可以配置允许访问的IP地址列表
- 支持单个IP、IP段（CIDR）、IP范围
- 如果未配置IP白名单，则不限制（允许所有IP）
- 如果配置了IP白名单，只允许白名单中的IP访问

#### 2.1.2 支持的IP格式

**单个IP**：
```
192.168.1.100
```

**IP段（CIDR）**：
```
192.168.1.0/24
10.0.0.0/8
```

**IP范围**：
```
192.168.1.1-192.168.1.100
10.0.0.1-10.0.0.255
```

#### 2.1.3 配置方式

在API用户管理界面：
- 启用/禁用开关
- 多行文本输入框（每行一个IP）
- 格式说明和示例
- 实时验证IP格式

### 2.2 用户代理限制功能

#### 2.2.1 功能说明
- 每个API用户可以配置允许的用户代理（User-Agent）列表
- 支持精确匹配和正则表达式匹配
- 如果未配置用户代理限制，则不限制（允许所有User-Agent）
- 如果配置了用户代理限制，只允许匹配的User-Agent访问

#### 2.2.2 支持的匹配方式

**精确匹配**：
```
MyApp/1.0
MyApp/2.0
```

**正则表达式匹配**：
```
/^MyApp\/\d+\.\d+$/
/^MyApp\/.*$/
```

**通配符匹配**（转换为正则）：
```
MyApp/*
*Bot*
```

## 三、数据库设计

### 3.1 扩展 w_api_user 表

```sql
ALTER TABLE w_api_user
ADD COLUMN ip_whitelist_enabled TINYINT(1) DEFAULT 0 COMMENT '是否启用IP白名单',
ADD COLUMN allowed_ips TEXT COMMENT '允许的IP地址列表（JSON格式）',
ADD COLUMN user_agent_restriction_enabled TINYINT(1) DEFAULT 0 COMMENT '是否启用用户代理限制',
ADD COLUMN allowed_user_agents TEXT COMMENT '允许的用户代理列表（JSON格式）',
ADD INDEX idx_ip_whitelist (ip_whitelist_enabled),
ADD INDEX idx_user_agent_restriction (user_agent_restriction_enabled);
```

### 3.2 数据格式

**allowed_ips 字段**（JSON格式）：
```json
[
  "192.168.1.100",
  "192.168.1.0/24",
  "10.0.0.1-10.0.0.255"
]
```

**allowed_user_agents 字段**（JSON格式）：
```json
[
  "MyApp/1.0",
  "/^MyApp\\/\\d+\\.\\d+$/",
  "MyApp/*"
]
```

## 四、实现方案

### 4.1 模型扩展

```php
// app/code/Weline/Api/Model/ApiUser.php

namespace Weline\Api\Model;

class ApiUser extends \Weline\Framework\Database\Model
{
    // ... 其他字段 ...
    
    public const fields_ip_whitelist_enabled = 'ip_whitelist_enabled';
    public const fields_allowed_ips = 'allowed_ips';
    public const fields_user_agent_restriction_enabled = 'user_agent_restriction_enabled';
    public const fields_allowed_user_agents = 'allowed_user_agents';
    
    /**
     * 是否启用IP白名单
     */
    public function isIpWhitelistEnabled(): bool
    {
        return (bool)$this->getData(self::fields_ip_whitelist_enabled);
    }
    
    /**
     * 获取允许的IP列表
     */
    public function getAllowedIps(): array
    {
        $ips = $this->getData(self::fields_allowed_ips);
        if (empty($ips)) {
            return [];
        }
        
        // 如果是JSON格式，解析
        if (is_string($ips)) {
            $decoded = json_decode($ips, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            // 如果不是JSON，按换行分隔
            return array_filter(array_map('trim', explode("\n", $ips)));
        }
        
        return is_array($ips) ? $ips : [];
    }
    
    /**
     * 设置允许的IP列表
     */
    public function setAllowedIps(array|string $ips): self
    {
        if (is_array($ips)) {
            $ips = json_encode($ips, JSON_UNESCAPED_UNICODE);
        }
        return $this->setData(self::fields_allowed_ips, $ips);
    }
    
    /**
     * 是否启用用户代理限制
     */
    public function isUserAgentRestrictionEnabled(): bool
    {
        return (bool)$this->getData(self::fields_user_agent_restriction_enabled);
    }
    
    /**
     * 获取允许的用户代理列表
     */
    public function getAllowedUserAgents(): array
    {
        $userAgents = $this->getData(self::fields_allowed_user_agents);
        if (empty($userAgents)) {
            return [];
        }
        
        // 如果是JSON格式，解析
        if (is_string($userAgents)) {
            $decoded = json_decode($userAgents, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            // 如果不是JSON，按换行分隔
            return array_filter(array_map('trim', explode("\n", $userAgents)));
        }
        
        return is_array($userAgents) ? $userAgents : [];
    }
    
    /**
     * 设置允许的用户代理列表
     */
    public function setAllowedUserAgents(array|string $userAgents): self
    {
        if (is_array($userAgents)) {
            $userAgents = json_encode($userAgents, JSON_UNESCAPED_UNICODE);
        }
        return $this->setData(self::fields_allowed_user_agents, $userAgents);
    }
}
```

### 4.2 IP验证服务

```php
// app/code/Weline/Api/Service/IpWhitelistService.php

namespace Weline\Api\Service;

class IpWhitelistService
{
    /**
     * 检查IP是否在白名单中
     * 
     * @param string $clientIp 客户端IP
     * @param array $allowedIps 允许的IP列表
     * @return bool
     */
    public function isIpAllowed(string $clientIp, array $allowedIps): bool
    {
        if (empty($allowedIps)) {
            return true; // 如果白名单为空，允许所有IP
        }
        
        foreach ($allowedIps as $allowedIp) {
            $allowedIp = trim($allowedIp);
            if (empty($allowedIp)) {
                continue;
            }
            
            // 单个IP匹配
            if ($clientIp === $allowedIp) {
                return true;
            }
            
            // CIDR匹配
            if (strpos($allowedIp, '/') !== false) {
                if ($this->isIpInCidr($clientIp, $allowedIp)) {
                    return true;
                }
            }
            
            // IP范围匹配
            if (strpos($allowedIp, '-') !== false) {
                if ($this->isIpInRange($clientIp, $allowedIp)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否在CIDR范围内
     */
    private function isIpInCidr(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    
    /**
     * 检查IP是否在范围内
     */
    private function isIpInRange(string $ip, string $range): bool
    {
        list($startIp, $endIp) = explode('-', $range);
        $ipLong = ip2long(trim($ip));
        $startLong = ip2long(trim($startIp));
        $endLong = ip2long(trim($endIp));
        
        if ($ipLong === false || $startLong === false || $endLong === false) {
            return false;
        }
        
        return $ipLong >= $startLong && $ipLong <= $endLong;
    }
}
```

### 4.3 用户代理验证服务

```php
// app/code/Weline/Api/Service/UserAgentRestrictionService.php

namespace Weline\Api\Service;

class UserAgentRestrictionService
{
    /**
     * 检查用户代理是否匹配
     * 
     * @param string $userAgent 请求的用户代理
     * @param array $allowedUserAgents 允许的用户代理列表
     * @return bool
     */
    public function isUserAgentAllowed(string $userAgent, array $allowedUserAgents): bool
    {
        if (empty($allowedUserAgents)) {
            return true; // 如果限制为空，允许所有User-Agent
        }
        
        foreach ($allowedUserAgents as $allowed) {
            $allowed = trim($allowed);
            if (empty($allowed)) {
                continue;
            }
            
            // 精确匹配
            if ($userAgent === $allowed) {
                return true;
            }
            
            // 正则表达式匹配（以 / 开头和结尾）
            if (preg_match('/^\/.*\/$/', $allowed)) {
                $pattern = substr($allowed, 1, -1); // 去掉首尾的 /
                if (preg_match($pattern, $userAgent)) {
                    return true;
                }
            }
            
            // 通配符匹配（转换为正则）
            if (strpos($allowed, '*') !== false) {
                $pattern = '/^' . str_replace(['*', '.'], ['.*', '\.'], $allowed) . '$/';
                if (preg_match($pattern, $userAgent)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
```

### 4.4 Observer扩展

```php
// app/code/Weline/Api/Observer/ApiControllerInitBefore.php

namespace Weline\Api\Observer;

use Weline\Api\Model\ApiUser;
use Weline\Api\Service\IpWhitelistService;
use Weline\Api\Service\UserAgentRestrictionService;
use Weline\Api\Service\ApiSecurityService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class ApiControllerInitBefore implements ObserverInterface
{
    private Request $request;
    private IpWhitelistService $ipWhitelistService;
    private UserAgentRestrictionService $userAgentRestrictionService;
    private ApiSecurityService $apiSecurityService;
    
    public function __construct(
        Request $request,
        IpWhitelistService $ipWhitelistService,
        UserAgentRestrictionService $userAgentRestrictionService,
        ApiSecurityService $apiSecurityService
    ) {
        $this->request = $request;
        $this->ipWhitelistService = $ipWhitelistService;
        $this->userAgentRestrictionService = $userAgentRestrictionService;
        $this->apiSecurityService = $apiSecurityService;
    }
    
    public function execute(Event &$event): void
    {
        // 只处理API请求（后台和前台）
        if (!$this->request->isApiBackend() && !$this->request->isApiFrontend()) {
            return;
        }
        
        // 如果是API认证相关的接口，不需要验证登录状态和安全限制
        $currentUrl = $this->request->getRouteUrlPath();
        $authUrls = [
            'backend/api/auth/login',
            'backend/api/auth/exchange',
            'backend/api/auth/refresh',
            'backend/api/auth/token-info',
            'api/auth/login',
            'api/auth/exchange',
            'api/auth/refresh',
            'api/auth/token-info'
        ];
        
        if (in_array($currentUrl, $authUrls)) {
            return;
        }
        
        // 1. 检查是否为完全公开接口（无Acl，不需要登录）
        if ($this->apiSecurityService->isPublicApi($this->request)) {
            // 1.1 检查是否包含Cookie（公开接口不允许携带Cookie）
            if ($this->request->getHeader('Cookie')) {
                // 记录日志
                $this->logSecurityViolation(null, 'cookie_violation', [
                    'client_ip' => $this->request->clientIP(),
                    'user_agent' => $this->request->getHeader('User-Agent') ?? '',
                    'request_path' => $currentUrl
                ]);
                
                $this->returnError(400, __('公开接口不允许携带Cookie'));
                return;
            }
            // 公开接口不需要进一步验证，直接返回
            return;
        }
        
        // 2. 获取API Session实例（根据area选择）
        if ($this->request->isApiBackend()) {
            $apiSession = ObjectManager::getInstance(\Weline\Framework\App\Session\BackendApiSession::class);
        } else {
            $apiSession = ObjectManager::getInstance(\Weline\Framework\App\Session\FrontendApiSession::class);
        }
        
        // 3. 检查是否已登录
        if (!$apiSession->isLogin()) {
            $this->returnError(401, __('请先登录'));
            return;
        }
        
        // 4. 检查用户状态（仅后台API需要）
        if ($this->request->isApiBackend()) {
            $user = $apiSession->getApiUser();
            if (!$user || !$user->getIsEnabled()) {
                $this->returnError(403, __('用户已被禁用'));
                return;
            }
            
            // 获取API用户扩展信息
            /** @var ApiUser $apiUser */
            $apiUser = ObjectManager::getInstance(ApiUser::class);
            $apiUser->load($user->getId());
            
            if (!$apiUser->getId()) {
                $this->returnError(403, __('API用户配置不存在'));
                return;
            }
            
            // 5. 检查IP白名单
            if ($apiUser->isIpWhitelistEnabled()) {
                $allowedIps = $apiUser->getAllowedIps();
                $clientIp = $this->request->clientIP();
                
                if (!$this->ipWhitelistService->isIpAllowed($clientIp, $allowedIps)) {
                    // 记录日志
                    $this->logSecurityViolation($user->getId(), 'ip_whitelist', [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);
                    
                    $this->returnError(403, __('IP地址不在白名单中'), [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);
                    return;
                }
            }
            
            // 6. 检查用户代理限制
            if ($apiUser->isUserAgentRestrictionEnabled()) {
                $allowedUserAgents = $apiUser->getAllowedUserAgents();
                $userAgent = $this->request->getHeader('User-Agent') ?? '';
                
                if (!$this->userAgentRestrictionService->isUserAgentAllowed($userAgent, $allowedUserAgents)) {
                    // 记录日志
                    $this->logSecurityViolation($user->getId(), 'user_agent_restriction', [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);
                    
                    $this->returnError(403, __('用户代理不匹配'), [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);
                    return;
                }
            }
        }
    }
    
    /**
     * 返回错误响应
     */
    private function returnError(int $code, string $message, array $data = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => $code,
            'msg' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 记录安全违规日志
     */
    private function logSecurityViolation(?int $userId, string $type, array $details): void
    {
        // 记录到日志文件或数据库
        error_log(sprintf(
            '[API Security] User ID: %s, Type: %s, Details: %s, Time: %s, IP: %s',
            $userId ?? 'N/A',
            $type,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $this->request->clientIP()
        ));
    }
}
```

### 4.5 管理界面扩展

在API用户编辑界面添加：

```html
<!-- IP白名单配置 -->
<div class="form-group">
    <label>
        <input type="checkbox" name="ip_whitelist_enabled" value="1" 
               <?= $apiUser->isIpWhitelistEnabled() ? 'checked' : '' ?>>
        启用IP白名单
    </label>
    <textarea name="allowed_ips" rows="5" 
              placeholder="每行一个IP地址，支持格式：&#10;192.168.1.100&#10;192.168.1.0/24&#10;10.0.0.1-10.0.0.255"
              <?= $apiUser->isIpWhitelistEnabled() ? '' : 'disabled' ?>><?= implode("\n", $apiUser->getAllowedIps()) ?></textarea>
    <small class="form-text text-muted">
        支持的格式：单个IP、CIDR（如 192.168.1.0/24）、IP范围（如 192.168.1.1-192.168.1.100）
    </small>
</div>

<!-- 用户代理限制配置 -->
<div class="form-group">
    <label>
        <input type="checkbox" name="user_agent_restriction_enabled" value="1"
               <?= $apiUser->isUserAgentRestrictionEnabled() ? 'checked' : '' ?>>
        启用用户代理限制
    </label>
    <textarea name="allowed_user_agents" rows="5"
              placeholder="每行一个User-Agent，支持格式：&#10;MyApp/1.0&#10;/^MyApp\\/\\d+\\.\\d+$/&#10;MyApp/*"
              <?= $apiUser->isUserAgentRestrictionEnabled() ? '' : 'disabled' ?>><?= implode("\n", $apiUser->getAllowedUserAgents()) ?></textarea>
    <small class="form-text text-muted">
        支持的格式：精确匹配、正则表达式（以 / 开头和结尾）、通配符（*）
    </small>
</div>
```

### 4.5 公开接口Cookie验证服务

```php
// app/code/Weline/Api/Service/ApiSecurityService.php

namespace Weline\Api\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Reflection\ReflectionClass;

class ApiSecurityService
{
    /**
     * 检查是否为完全公开接口（无Acl，不需要登录）
     */
    public function isPublicApi(Request $request): bool
    {
        // 获取控制器和方法信息
        $controller = $request->getController();
        $action = $request->getAction();
        
        if (!$controller || !$action) {
            return false;
        }
        
        // 使用反射检查方法是否有Acl注解
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod($action);
        
        // 检查是否有Acl注解
        $aclAttributes = $method->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (!empty($aclAttributes)) {
            return false; // 有Acl注解，不是公开接口
        }
        
        // 检查是否需要登录（通过检查方法注释或配置）
        // 这里可以根据实际需求实现，比如检查方法注释中的@requiresLogin标签
        // 或者检查配置文件中是否标记该接口需要登录
        
        // 暂时假设无Acl注解且不在需要登录的接口列表中，就是完全公开接口
        return true;
    }
}
```

## 五、测试用例

### 5.0 公开接口Cookie验证测试

**TC-000: 公开接口携带Cookie（应该拒绝）**
- 接口：完全公开接口（无Acl，不需要登录）
- 请求：包含Cookie头
- 预期：返回400错误，提示"公开接口不允许携带Cookie"

**TC-001: 公开接口不携带Cookie（应该允许）**
- 接口：完全公开接口（无Acl，不需要登录）
- 请求：不包含Cookie头
- 预期：正常处理请求

**TC-002: 需要登录的接口携带Cookie（应该允许）**
- 接口：需要登录的接口（无Acl，但需要登录）
- 请求：包含Cookie头
- 预期：正常处理请求（Cookie用于身份验证）

### 5.1 IP白名单测试

**TC-001: IP白名单验证（单个IP）**
- 配置：`192.168.1.100`
- 请求IP：`192.168.1.100`
- 预期：允许访问

**TC-002: IP白名单验证（CIDR）**
- 配置：`192.168.1.0/24`
- 请求IP：`192.168.1.50`
- 预期：允许访问

**TC-003: IP白名单验证（IP范围）**
- 配置：`192.168.1.1-192.168.1.100`
- 请求IP：`192.168.1.50`
- 预期：允许访问

**TC-004: IP白名单验证（不在白名单）**
- 配置：`192.168.1.100`
- 请求IP：`192.168.1.200`
- 预期：返回403错误

### 5.2 用户代理限制测试

**TC-005: 用户代理验证（精确匹配）**
- 配置：`MyApp/1.0`
- 请求User-Agent：`MyApp/1.0`
- 预期：允许访问

**TC-006: 用户代理验证（正则表达式）**
- 配置：`/^MyApp\/\d+\.\d+$/`
- 请求User-Agent：`MyApp/2.0`
- 预期：允许访问

**TC-007: 用户代理验证（通配符）**
- 配置：`MyApp/*`
- 请求User-Agent：`MyApp/1.0`
- 预期：允许访问

**TC-008: 用户代理验证（不匹配）**
- 配置：`MyApp/1.0`
- 请求User-Agent：`OtherApp/1.0`
- 预期：返回403错误

## 六、安全考虑

### 6.1 日志记录
- 记录所有被拒绝的请求
- 记录内容：用户ID、请求IP、User-Agent、拒绝原因、请求时间、请求路径
- 日志保留90天

### 6.2 性能优化
- IP验证结果可以缓存（缓存时间5分钟）
- 用户代理验证结果可以缓存（缓存时间5分钟）
- 使用索引优化数据库查询

### 6.3 错误信息
- 不暴露详细的配置信息（避免信息泄露）
- 错误信息对用户友好，但不暴露系统内部细节

---

**文档版本**: 1.0  
**创建日期**: 2025-01-XX  
**最后更新**: 2025-01-XX

