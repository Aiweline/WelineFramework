# Weline_StorageOss

`Weline_StorageOss` 是 `Weline_Storage` 的阿里云 OSS 驱动扩展，`etc/module.php` 当前版本为 `1.0.1`，Provider 代码为 `oss::aliyun`。

## 主要能力

- `AliyunOssProvider` 定义磁盘配置、敏感字段、命名空间指纹以及 Driver/URL Adapter 构造。
- `AliyunOssDriver` 实现流式读写、状态、删除、复制、移动和有界枚举。
- `AliyunOssMultipartUpload` 处理大对象分片上传；失败任务由清理记录、Processor 与 Cron 重试中止。
- `AliyunOssUrlAdapter` 生成公开、临时和图片变体 URL。

## 安全与运行时边界

- AccessKey Secret 与 Security Token 是敏感配置，不得写入文档、日志或返回给 AI。
- 指定 OSS 磁盘失败时不得静默回退到本地磁盘。
- SDK Client、临时文件和清理状态必须绑定当前 Request/Fiber 生命周期，不能被常驻 WLS Worker 泄漏复用。
- OSS SDK 是 Composer 依赖；启用模块前必须满足 `aliyuncs/oss-sdk-php ^2.7`。

## 文档

- [需求](需求.md)
- [开发日志](开发日志.md)
