# Weline_StorageOss AI Index

- 模块边界：只实现 `oss::aliyun` Provider、Driver 和 URL Adapter；业务资源实体归 `Weline_FileManager`。
- 公共契约：`Weline_Storage` 的 `StorageDriverProviderInterface`。
- WLS 约束：SDK Client 为 Request/Fiber 级，流、临时文件和 Client lease 必须被 Storage 资源注册表清理。
- 配置实例：使用规范三段式磁盘代码，例如 `oss::aliyun::media_public`。
- 凭据、签名 URL 和完整对象路径不得进入日志、持久化或进程级缓存。
