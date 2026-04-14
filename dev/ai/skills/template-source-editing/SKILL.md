---
name: template-source-editing
description: 框架模板源码修改规范。`view/tpl` 目录是系统编译产物，只能阅读参考，禁止修改；模板变更必须定位并修改对应源文件。触发词：tpl、编译模板、模板被覆盖、源模板、phtml。
globs:
  - "app/code/**/view/tpl/**/*.phtml"
  - "app/code/**/view/templates/**/*.phtml"
  - "app/code/**/view/theme/**/*.phtml"
alwaysApply: false
---

# Template Source Editing

本技能用于 `E:\WelineFramework\DEV-workspace`，专门约束模板编译产物与源文件修改边界。

## 核心原则（硬约束）

1. `view/tpl/**` 是系统编译产物，只能阅读和对照，**绝对不能直接修改**。
2. 任何模板改动都必须回到对应源模板执行（如 `view/templates/**`、`view/theme/**`、模块源码模板）。
3. 直接改 `view/tpl/**` 会被系统实时重生成覆盖，属于无效改动。

## 标准执行流程

1. 先在 `view/tpl/**` 里定位当前运行时渲染结果（仅用于观察）。
2. 反查对应源文件位置（优先 `view/templates/**` 或主题源目录）。
3. 只修改源文件，不改编译产物。
4. 通过页面验证确认渲染结果已由编译流程同步生效。

## 反查建议

- 同名模板优先：`view/tpl/.../com_xxx.phtml` 通常对应 `view/templates/.../xxx.phtml`
- 结合控制器/Block/template 绑定链路定位源文件
- 必要时使用全局搜索定位模板片段来源

## 禁止

- 直接编辑 `app/code/**/view/tpl/**`
- 把 `view/tpl/**` 当作长期维护入口
- 遇到覆盖现象仍继续在编译产物上修补
