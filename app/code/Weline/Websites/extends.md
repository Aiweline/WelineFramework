# Weline_Websites 模块扩展点

## 域名商适配器 (Registrar)

### 概述

Weline_Websites 模块提供域名商适配器扩展点，允许第三方模块接入新的域名注册商 API。

### 接口

所有域名商适配器必须实现 `Weline\Websites\Api\DomainRegistrarInterface` 接口。

### 快速开始

1. 在你的模块中创建适配器文件：

```
your_module/extends/module/Weline_Websites/Registrar/YourRegistrar.php
```

2. 实现 `DomainRegistrarInterface` 接口：

```php
<?php
namespace YourVendor\YourModule\Extends\Module\Weline_Websites\Registrar;

use Weline\Websites\Api\DomainRegistrarInterface;

class YourRegistrar implements DomainRegistrarInterface
{
    public function getRegistrarCode(): string { return 'your_registrar'; }
    public function getRegistrarName(): string { return 'Your Registrar'; }
    // ... 实现其他方法
}
```

3. 运行 `php bin/m s:up` 注册扩展。

### 内置适配器

| 适配器 | 代码 | 说明 |
|--------|------|------|
| AWS Route53 | `aws_route53` | Amazon Web Services 域名服务 |
| 阿里云域名 | `aliyun_domain` | 阿里云域名注册服务 |
| Azure DNS | `azure_dns` | Microsoft Azure 域名服务 |
