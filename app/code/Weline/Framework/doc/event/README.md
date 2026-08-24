# Framework 事件文档索引

> 按域浏览 `Framework/doc/event/` 下的事件说明书。实现前配合 [扩展点选型](../3-开发/扩展点选型.md) 与 [08-事件](../../2-快速开始/08-事件.md)。

## 使用方式

1. 按下方**域**找到场景相近的文档。
2. 打开对应 `.md` 查看**事件名**、触发时机、数据格式、`dispatch` 与 Observer 注册示例。
3. 用 MCP `search_project_knowledge` 或 `get_indexed_document` 按事件名精确检索。

## 域索引

| 域 | 目录 | 典型场景 |
|----|------|----------|
| app | [app/](./app/) | 应用运行、URL 解析、后端控制器、店面门禁 |
| router | [router/](./router/) | 路由前后、URI、白名单、未登录重定向 |
| http | [http/](./http/) | 响应、重定向、无路由、区域、最终响应 |
| controller | [controller/](./controller/) | REST/前端控制器初始化、模板获取 |
| model | [model/](./model/) | 模型加载/保存/删除前后 |
| database | [database/](./database/) | 模型更新、数据库索引器 |
| query | [query/](./query/) | 统一查询执行、动态查询事件 |
| view | [view/](./view/) | 视图头/底/位置、w-form |
| url | [url/](./url/) | 语言/币种/网站检测、URL 重写、SEO |
| module | [module/](./module/) | 模块安装/升级/卸载、控制器属性 |
| framework | [framework/](./framework/) | 系统消息通知、resource_changed |
| server | [server/](./server/) | 服务器启动/停止后 |
| phrase | [phrase/](./phrase/) | 翻译词典收集与组装 |
| template | [template/](./template/) | 模板编译、标签配置 |
| resource | [resource/](./resource/) | 资源编译 |
| setup | [setup/](./setup/) | 系统升级、SchemaDiff |
| deploy | [deploy/](./deploy/) | 部署模式切换 |
| acl | [acl/](./acl/) | ACL 分发 |
| cookie | [cookie/](./cookie/) | Cookie 语言本地化 |
| console | [console/](./console/) | 控制台编译 |
| fpc | [fpc/](./fpc/) | 全页缓存命中 |
| maintenance | [maintenance/](./maintenance/) | 维护模式 |
| register | [register/](./register/) | 注册安装器 |
| uninstall | [uninstall/](./uninstall/) | 卸载服务 |
| system | [system/](./system/) | 系统更新后 |

## 常用事件速查

| 事件名（以文档为准） | 文档 |
|----------------------|------|
| `Weline_Admin::msg` | [framework/系统消息通知.md](./framework/系统消息通知.md) |
| `Weline_Framework_Server::start_after` | [server/服务器启动后.md](./server/服务器启动后.md) |
| `Weline_Framework_Server::stop_after` | [server/服务器停止后.md](./server/服务器停止后.md) |
| `Weline_Framework_Http::error_page_render` | 见 Framework 需求与 http 域文档 |

## 注册与触发

- Observer：`app/code/Weline/{Module}/Observer/*.php` + `etc/event.xml`
- 触发：`EventsManager::dispatch('event_name', $data)`
- 升级后：`php bin/w module:upgrade`

## 相关

- [文档索引](../../../Ai/doc/文档索引.md)
- [AI 工程交付流程](../../../Ai/doc/AI工程交付流程.md)
