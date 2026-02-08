<?php
declare(strict_types=1);

/**
 * Weline Framework - PHP 8.4+ Property Hooks 示例
 * 
 * 重要提示：此文件仅在 PHP 8.4+ 环境下可用！
 * 低版本 PHP 将因语法错误无法解析此文件。
 * 
 * Property Hooks 是 PHP 8.4 的新特性，允许在属性上直接定义 get/set 行为，
 * 无需编写完整的 getter/setter 方法。
 * 
 * 主要优势：
 * - 更简洁的语法
 * - 保持属性访问语义（$obj->name 而非 $obj->getName()）
 * - 自动验证和转换
 * - 更好的封装性
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 * @requires PHP 8.4+
 */

namespace Weline\Framework\Support;

// 运行时检查，防止低版本 PHP 意外加载
if (PHP_VERSION_ID < 80400) {
    throw new \RuntimeException(
        'Php84PropertyHooksExample 仅支持 PHP 8.4+，当前版本：' . PHP_VERSION
    );
}

/**
 * Property Hooks 示例类 - Model 层应用
 * 
 * 展示如何在 Model 中使用 Property Hooks 实现：
 * - 数据验证（trim、类型检查）
 * - 默认值处理
 * - 计算属性
 * - 懒加载
 */
class Php84PropertyHooksExample
{
    // ==================== 基础示例：字符串 trim ====================
    
    /**
     * 名称属性 - 自动 trim
     * 
     * set hook 会在赋值时自动去除首尾空格
     */
    public string $name {
        get => $this->name;
        set => $this->name = trim($value);
    }
    
    // ==================== 默认值示例 ====================
    
    /**
     * 状态属性 - 带默认值
     * 
     * get hook 在值为空时返回默认值 'pending'
     */
    private string $_status = '';
    
    public string $status {
        get => $this->_status ?: 'pending';
        set => $this->_status = $value;
    }
    
    // ==================== 数据验证示例 ====================
    
    /**
     * 邮箱属性 - 格式验证
     * 
     * set hook 验证邮箱格式，无效时抛出异常
     */
    private string $_email = '';
    
    public string $email {
        get => $this->_email;
        set {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("无效的邮箱格式: {$value}");
            }
            $this->_email = strtolower(trim($value));
        }
    }
    
    // ==================== 价格/金额处理示例 ====================
    
    /**
     * 价格属性 - 保留两位小数
     * 
     * set hook 自动格式化为两位小数
     * get hook 返回格式化后的值
     */
    private float $_price = 0.0;
    
    public float $price {
        get => round($this->_price, 2);
        set => $this->_price = max(0, (float) $value); // 确保非负
    }
    
    /**
     * 格式化价格（带货币符号）
     * 
     * 只读计算属性 - 只有 get hook
     */
    public string $formattedPrice {
        get => '¥' . number_format($this->price, 2);
    }
    
    // ==================== 日期处理示例 ====================
    
    /**
     * 创建时间属性 - 自动转换
     * 
     * 接受 string|int|\DateTimeInterface，统一存储为 \DateTimeImmutable
     */
    private ?\DateTimeImmutable $_createdAt = null;
    
    public \DateTimeImmutable $createdAt {
        get => $this->_createdAt ?? new \DateTimeImmutable();
        set {
            if ($value instanceof \DateTimeImmutable) {
                $this->_createdAt = $value;
            } elseif ($value instanceof \DateTime) {
                $this->_createdAt = \DateTimeImmutable::createFromMutable($value);
            } elseif (is_int($value)) {
                $this->_createdAt = (new \DateTimeImmutable())->setTimestamp($value);
            } elseif (is_string($value)) {
                $this->_createdAt = new \DateTimeImmutable($value);
            } else {
                throw new \InvalidArgumentException('无效的日期格式');
            }
        }
    }
    
    // ==================== 数组/JSON 处理示例 ====================
    
    /**
     * 配置属性 - JSON 自动编解码
     * 
     * 内部存储为 JSON 字符串，外部访问为数组
     */
    private string $_configJson = '{}';
    
    public array $config {
        get => json_decode($this->_configJson, true) ?: [];
        set => $this->_configJson = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
    // ==================== 懒加载示例 ====================
    
    /**
     * 关联数据懒加载
     * 
     * 只在首次访问时加载，后续使用缓存
     */
    private ?array $_relatedItems = null;
    
    public array $relatedItems {
        get {
            if ($this->_relatedItems === null) {
                // 模拟从数据库加载关联数据
                $this->_relatedItems = $this->loadRelatedItems();
            }
            return $this->_relatedItems;
        }
    }
    
    private function loadRelatedItems(): array
    {
        // 实际项目中这里会查询数据库
        return ['item1', 'item2', 'item3'];
    }
    
    // ==================== 只读属性示例 ====================
    
    /**
     * ID 属性 - 只读（设置后不可修改）
     */
    private ?int $_id = null;
    
    public ?int $id {
        get => $this->_id;
        set {
            if ($this->_id !== null) {
                throw new \RuntimeException('ID 一旦设置不可修改');
            }
            $this->_id = $value;
        }
    }
    
    // ==================== 使用示例 ====================
    
    /**
     * 演示 Property Hooks 的使用
     */
    public static function demo(): void
    {
        $example = new self();
        
        // 自动 trim
        $example->name = '  张三  ';
        echo "Name: '{$example->name}'\n"; // 输出: Name: '张三'
        
        // 默认值
        echo "Status: {$example->status}\n"; // 输出: Status: pending
        
        // 邮箱验证（小写）
        $example->email = '  Test@Example.COM  ';
        echo "Email: {$example->email}\n"; // 输出: Email: test@example.com
        
        // 价格处理
        $example->price = 99.999;
        echo "Price: {$example->price}\n"; // 输出: Price: 100
        echo "Formatted: {$example->formattedPrice}\n"; // 输出: Formatted: ¥100.00
        
        // 日期处理（通过 set hook 自动转换字符串为 DateTimeImmutable）
        // @phpstan-ignore-next-line Property Hook 会处理类型转换
        $example->createdAt = new \DateTimeImmutable('2024-01-15 10:30:00');
        echo "Created: {$example->createdAt->format('Y-m-d H:i:s')}\n";
        
        // 配置（JSON）
        $example->config = ['key' => 'value', 'nested' => ['a' => 1]];
        print_r($example->config);
        
        // 懒加载
        print_r($example->relatedItems); // 首次访问时加载
        
        echo "\n✓ Property Hooks 示例执行完成\n";
    }
}

// ==================== 框架 Model 层应用建议 ====================

/**
 * 在实际 Model 中使用 Property Hooks 的建议：
 * 
 * 1. 字段自动处理：
 *    - trim() 处理字符串字段
 *    - strtolower() 处理邮箱、用户名等
 *    - round() 处理金额字段
 * 
 * 2. 默认值：
 *    - status 默认 'active'
 *    - sort_order 默认 0
 *    - created_at 默认当前时间
 * 
 * 3. 验证：
 *    - 邮箱格式验证
 *    - URL 格式验证
 *    - 数值范围验证
 * 
 * 4. 计算属性：
 *    - fullName = firstName + lastName
 *    - age = 当前年份 - birthYear
 *    - formattedPrice = 货币符号 + price
 * 
 * 5. 关联懒加载：
 *    - $order->items（首次访问时查询）
 *    - $user->roles（首次访问时查询）
 * 
 * 注意事项：
 * - Property Hooks 是 PHP 8.4+ 语法特性，无法在低版本使用
 * - 需要使用 backing property（如 $_name）存储实际值
 * - set hook 中的 $value 是自动传入的参数
 * - 可以只定义 get 或只定义 set
 * - 与 Weline 框架的 DataObject 配合使用效果更佳
 */
