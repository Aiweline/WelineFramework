# 对齐冻结 — 主题架构性能复审

- 日期：2026-09-22
- 主持：项目经理
- 参会：性能检查工程师、架构师、主题开发工程师
- 通道证据：各席回报（见 roster agent_id）

## 总判定

**架构性能：可接受需优（非架构性失败）**

- 已健康：主题身份 HotCache、已发布布局结构 HotCache（R3）、chrome/nav、预览 FPC 旁路、结构键去 lang。
- 需优：资源发现仍进程袋、模板路径每请求 `is_file`×链深、发布失效过宽、部件 SSR 串行体积。

## 冻结（硬 · 不可借优化破坏）

1. **路径模型**：`app/design` 链 → `Weline_Theme/view/theme` → 模块贡献；compat 三路径不变。
2. **三态身份**：参数 / Token / RequestContext；业务同构。
3. **缓存**：结构键禁 lang/currency；Policy∈Coordinator；失效∈Cleaner；草稿禁入共享结构池；禁恢复 theme_runtime 热路径 get/set；禁业务平行进程内袋。
4. **店面主链**：正式店面 = LayoutEntity + `r{releaseId}`；`getLayoutData` 仅编辑器/遗留。
5. **否决大拆**：合并全部 Preview*、合并 Catalog+DirectoryResolver、打散三态、重写 design 继承、Editor 拖拽协议上收。

## 好的调整（优先级）

| P | 项 | Owner | 本波 |
|---|-----|-------|------|
| P0 | 继续 R3 结构缓存契约补洞；店面主链纪律 | 主题+性能 | 纪律+文档 |
| P1 | 模板路径解析 `rememberForRequest` / HotCache（deps=theme） | 主题 | **本波施工** |
| P1 | ThemeResourceCatalog / getAreaDirectories 升 HotCache 投影 | 主题+架构 | 设计后下一波 |
| P1 | 发布失效收窄（已知 Scope → clearScoped） | 主题 | 下一波 |
| P2 | Path 双轨收敛到 DirectoryResolver | 主题 | 另票 |
| P2 | 废弃 PreviewManager | 主题 | 另票 |
| P3 | ThemeEditor / SlotRenderer 按 usecase 拆 | 主题+架构 | 条件同意、后置 |
| 消费方 | CachePool 批量 MGET、I18n 定向失效 | 后端 | 非 Theme 独做 |

## 性能席 stance

可接受需优；P0 无现行正确性漏洞。

## 架构师 stance

同意立刻小优化；条件同意大拆；否决打散三态/合并 Preview 上帝类。

## 主题席 stance

同意性能五方向；R3 已落地；下一刀路径/Catalog HotCache。
