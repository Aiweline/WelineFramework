# Websites 模块待优化问题

## 问题列表

### 1. 观察者解析存入内存缓存（性能优化） ⚠️ 高优先级

**问题描述**：
当前 `DetectWebsite` 观察者在每次请求时都会查询数据库来匹配网站，这可能导致性能问题，特别是在高并发场景下。

**当前实现**：
- 每次请求都会执行数据库查询来匹配网站
- 网站数据在每次请求时都会重新加载
- 关联货币和语言数据在每次请求时都会重新查询

**优化方案**：
1. **内存缓存网站列表**：
   - 在应用启动时或首次访问时，将所有网站数据加载到内存缓存
   - 使用静态变量或缓存服务存储网站列表
   - 当网站数据发生变化时，清除缓存

2. **缓存网站匹配结果**：
   - 缓存URL到网站的映射关系
   - 使用最长匹配算法在内存中查找，避免数据库查询
   - 考虑使用Trie树或前缀树优化URL匹配性能

3. **缓存关联数据**：
   - 缓存每个网站的关联货币和语言列表
   - 在网站数据变化时更新缓存

4. **缓存失效机制**：
   - 监听网站数据的增删改事件
   - 自动清除相关缓存
   - 提供手动清除缓存的接口

**实现建议**：
```php
// 伪代码示例
class WebsiteCache
{
    private static array $websites = [];
    private static array $urlMap = [];
    private static bool $initialized = false;
    
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        
        // 加载所有网站到内存
        $websiteModel = ObjectManager::getInstance(Website::class);
        $websites = $websiteModel->select()->fetchArray();
        
        foreach ($websites as $website) {
            self::$websites[$website['website_id']] = $website;
            // 构建URL映射
            self::buildUrlMap($website);
        }
        
        self::$initialized = true;
    }
    
    public static function findWebsiteByUrl(string $url): ?Website
    {
        self::init();
        
        // 在内存中查找，避免数据库查询
        // 使用最长匹配算法
        return self::longestMatch($url);
    }
    
    public static function clearCache(): void
    {
        self::$websites = [];
        self::$urlMap = [];
        self::$initialized = false;
    }
}
```

**预期收益**：
- 减少数据库查询次数
- 提高网站匹配速度
- 降低服务器负载
- 提升系统响应速度

**注意事项**：
- 需要考虑缓存一致性问题
- 需要处理并发访问的线程安全问题
- 需要考虑内存使用情况
- 需要提供缓存预热机制

**相关文件**：
- `app/code/Weline/Websites/Observer/DetectWebsite.php`
- `app/code/Weline/Websites/Data/WebsiteData.php`
- `app/code/Weline/Websites/Model/Website.php`

**计划完成时间**：待定

---

## 问题提交规范

如需添加新的待优化问题，请按照以下格式：

```markdown
### N. 问题标题 ⚠️ 优先级

**问题描述**：
详细描述问题

**当前实现**：
说明当前的实现方式

**优化方案**：
提出优化建议

**预期收益**：
说明优化后的收益

**注意事项**：
需要注意的问题

**相关文件**：
列出相关文件路径

**计划完成时间**：日期
```

