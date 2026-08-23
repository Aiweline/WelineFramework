<!-- weline:module-readme:auto-generated -->
# Weline_Meta 模块文档

> 本 README 由 `prepare_project 文档修复流程` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先调用项目 MCP `prepare_project`；返回 `ready` 后，使用 `resolve_task_context` 按任务从本 README、`需求.md`、`开发日志.md` 和专题文档取得必要上下文。

稳定公开仓储契约见 `app/code/Weline/Meta/doc/public-repository-contract.md`。

## 模块定位

- 模块代码：`Weline_Meta`
- 目录：`app/code/Weline/Meta`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 代码面概览

入口文件：
- `app/code/Weline/Meta/etc/module.xml`
- `app/code/Weline/Meta/etc/backend/menu.xml`

- `Api`：元数据/MetaConfig Repository、参数归一化接口和只读 DTO。
- `Console`：php bin/w 命令入口。 文件数：1
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：4
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：4
- `Helper`：模块内辅助能力。 文件数：2
- `Model`：ORM 模型与字段 schema。 文件数：3
- `Observer`：事件观察者与订阅逻辑。 文件数：3
- `Service`：业务编排与模块服务层。 文件数：2
- `Setup`：安装/升级装配。 文件数：2
- `Taglib`：模板标签扩展。 文件数：3
- `etc`：模块配置。 文件数：4
- `view/templates`：模块模板源文件。 文件数：6
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/Meta/doc/@meta.json规约文件说明.md`
- `app/code/Weline/Meta/doc/event/元数据路径扫描.md`
- `app/code/Weline/Meta/doc/w-meta标签使用说明.md`
- `app/code/Weline/Meta/doc/使用指南.md`
- `app/code/Weline/Meta/doc/完整实现方案.md`
- `app/code/Weline/Meta/doc/public-repository-contract.md`

## 跨模块契约

其他模块读写 Meta 数据时，只能依赖 `Weline\Meta\Api` 的 Repository/DTO，禁止引用 `Weline\Meta\Model`、内部 Service 或 Query Builder。详见 [`public-repository-contract.md`](./public-repository-contract.md)。

## MetaConfig 身份指纹迁移

`w_meta_config` 使用 `identity_fingerprint` 作为七字段身份的 SHA-256 唯一键。字段顺序固定为 `namespace`、`config_key`、`scope`、`locale`、`identify_id`、`meta_id`、`meta_identify`；编码保留原始 UTF-8 字节，并显式区分 `NULL`、空字符串、字符串与整数，不做 trim、Unicode 归一化或大小写折叠。各调用入口仍沿用自身既有的 trim 规则，然后再把最终落库值交给指纹生成器。

公共 DTO 的可选 owner 仍保持兼容语义：未提供的 `identify_id`、`meta_id` 或 `meta_identify` 是查询通配条件，不会直接被解释为既有记录的 SQL `NULL`。写入或删除会先用 context、locale 和已提供 owner 找候选，再以 PHP 原始字节复核；唯一候选沿用其完整七字段身份，多候选按 `config_id` 明确失败，零候选的新写入才把缺省 owner 落为 `NULL`。

版本 `1.0.1` 是两阶段迁移的第一阶段：声明层暂时允许该字段为 `NULL`，只为旧数据全表预检和可恢复回填保留迁移窗口。应用的 Repository、旧 `MetaConfig::setConfig()` 及直接 Model `save()` 写入都必须生成 64 位小写十六进制指纹。数据升级会在首次写入前校验已有指纹、精确重复身份和理论哈希碰撞，随后在同一事务中只回填 `NULL` 并再次全表断言。

旧 `getConfig/deleteConfig` 仍保留请求语言回退以及“缺 scope/locale 表示全部”的兼容外观，但候选行必须经 Repository 原字节复核。批量删除只能在 legacy Model 当前连接的一个写意图事务中按主键与指纹执行；连接错配、碰撞伪造或中途异常都整批回滚。

第一阶段稳定并确认所有环境 `NULL=0` 后，后续独立版本才把 `identity_fingerprint` 收紧为 `NOT NULL`；不要在本阶段手工提前收紧，也不要恢复旧的超长复合唯一索引。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
