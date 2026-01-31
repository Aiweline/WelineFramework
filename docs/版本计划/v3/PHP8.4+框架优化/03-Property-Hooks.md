# 03 - Property Hooks（属性钩子）⭐⭐

[← 索引](README.md)

**性能影响**：与 getter/setter 性能等价，无显著提升；主要收益为代码简化与可维护性。

---

## 特性说明

Property Hooks 允许在属性级别定义 `get` 和 `set` 行为，替代传统的 getter/setter 方法。

## 应用场景

| 场景 | 当前实现 | v3 实现 | 收益 |
|------|---------|---------|------|
| Model 字段访问 | `getData()`/`setData()` | Property Hooks | 代码量 -60% |
| 字段格式化 | 手动调用格式化方法 | `set` hook 自动处理 | 一致性 ↑ |
| 计算属性 | 额外方法 | 虚拟属性 `get` hook | 语义清晰 |
| 字段验证 | 分散的验证逻辑 | `set` hook 内置验证 | 可维护性 ↑ |

## Model 层实现规范

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Database\Model;

/**
 * v3 Model 基类 - 使用 Property Hooks
 */
abstract class AbstractModel
{
    // ==================== 核心属性 ====================
    
    /**
     * 主键 ID - 对外只读
     * 使用非对称可见性保护
     */
    public private(set) ?int $id = null;
    
    /**
     * 创建时间 - 自动设置，不可修改
     */
    public private(set) ?string $created_at {
        get => $this->created_at;
        set {
            if ($this->created_at === null) {
                $this->created_at = $value ?? date('Y-m-d H:i:s');
            }
            // 已设置则忽略（不可修改）
        }
    }
    
    /**
     * 更新时间 - 每次保存自动更新
     */
    public string $updated_at {
        get => $this->updated_at ?? date('Y-m-d H:i:s');
        set => date('Y-m-d H:i:s');  // 始终使用当前时间
    }
    
    // ==================== 数据容器 ====================
    
    /**
     * 原始数据（数据库读取）
     */
    protected private(set) array $originalData = [];
    
    /**
     * 当前数据
     */
    protected array $data = [];
    
    /**
     * 脏数据标记
     */
    public bool $isDirty {
        get => $this->data !== $this->originalData;
    }
    
    /**
     * 变更字段
     */
    public array $changedFields {
        get {
            $changed = [];
            foreach ($this->data as $key => $value) {
                if (!isset($this->originalData[$key]) || $this->originalData[$key] !== $value) {
                    $changed[] = $key;
                }
            }
            return $changed;
        }
    }
}
```

## 业务 Model 示例

```php
<?php
declare(strict_types=1);

namespace Weline\User\Model;

use Weline\Framework\Database\Model\AbstractModel;
use Weline\Framework\Database\Attribute\Table;
use Weline\Framework\Database\Attribute\Column;

#[Table('w_users')]
class User extends AbstractModel
{
    // ==================== 基础字段 ====================
    
    #[Column(type: 'varchar', length: 100)]
    public string $username {
        get => $this->data['username'] ?? '';
        set {
            // 用户名规范化：去除空格、转小写
            $this->data['username'] = strtolower(trim($value));
        }
    }
    
    #[Column(type: 'varchar', length: 255)]
    public string $email {
        get => $this->data['email'] ?? '';
        set {
            $email = strtolower(trim($value));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email: {$email}");
            }
            $this->data['email'] = $email;
        }
    }
    
    #[Column(type: 'varchar', length: 255)]
    public private(set) string $password {
        get => $this->data['password'] ?? '';
        set {
            // 自动哈希密码
            if (!password_get_info($value)['algo']) {
                $value = password_hash($value, PASSWORD_ARGON2ID);
            }
            $this->data['password'] = $value;
        }
    }
    
    // ==================== 虚拟属性（计算字段） ====================
    
    /**
     * 全名 - 虚拟属性，不存储
     */
    public string $fullName {
        get => trim(($this->data['first_name'] ?? '') . ' ' . ($this->data['last_name'] ?? ''));
    }
    
    /**
     * 是否已验证邮箱
     */
    public bool $isEmailVerified {
        get => !empty($this->data['email_verified_at']);
    }
    
    /**
     * 头像 URL - 带默认值
     */
    public string $avatarUrl {
        get {
            $avatar = $this->data['avatar'] ?? null;
            if ($avatar) {
                return "/media/avatars/{$avatar}";
            }
            // Gravatar 默认头像
            $hash = md5($this->email);
            return "https://www.gravatar.com/avatar/{$hash}?d=identicon";
        }
    }
    
    // ==================== JSON 字段 ====================
    
    #[Column(type: 'json')]
    public array $preferences {
        get => json_decode($this->data['preferences'] ?? '{}', true) ?: [];
        set => $this->data['preferences'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
    // ==================== 金额字段（分->元） ====================
    
    #[Column(type: 'bigint')]
    public float $balance {
        get => ($this->data['balance_cents'] ?? 0) / 100;
        set {
            if ($value < 0) {
                throw new \InvalidArgumentException('Balance cannot be negative');
            }
            $this->data['balance_cents'] = (int)round($value * 100);
        }
    }
    
    // ==================== 业务方法 ====================
    
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }
    
    public function setPassword(string $newPassword): void
    {
        $this->password = $newPassword;  // 自动哈希
    }
}
```

## 迁移指南

```php
// ❌ v2 旧写法
class User extends Model {
    public function getEmail(): string {
        return strtolower($this->getData('email') ?? '');
    }
    
    public function setEmail(string $email): self {
        return $this->setData('email', strtolower(trim($email)));
    }
    
    public function getFullName(): string {
        return $this->getData('first_name') . ' ' . $this->getData('last_name');
    }
}

// 使用
$user = new User();
$user->setEmail('TEST@Example.com');
echo $user->getEmail();      // test@example.com
echo $user->getFullName();   // John Doe

// ✅ v3 新写法
class User extends Model {
    public string $email {
        get => strtolower($this->data['email'] ?? '');
        set => $this->data['email'] = strtolower(trim($value));
    }
    
    public string $fullName {
        get => trim(($this->data['first_name'] ?? '') . ' ' . ($this->data['last_name'] ?? ''));
    }
}

// 使用（更自然）
$user = new User();
$user->email = 'TEST@Example.com';
echo $user->email;     // test@example.com
echo $user->fullName;  // John Doe
```
