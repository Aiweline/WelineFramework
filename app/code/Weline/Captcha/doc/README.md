<!-- weline:module-readme:auto-generated -->
# Weline_Captcha 模块文档

> 本 README 由 `prepare_project 文档修复流程` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前必须先完成 `prepare_project`；进入 `ready` 后调用 `resolve_task_context`，由 MCP 按当前任务返回本模块的最小文档集合。全局门禁见 `app/code/Weline/Ai/doc/AI开发治理.md`。

## 模块定位

- 模块代码：`Weline_Captcha`
- 目录：`app/code/Weline/Captcha`
- 默认实现：表单级 Captcha 默认关闭；入口显式启用后，Google reCAPTCHA Enterprise
  未启用或配置不完整时强制使用一次性本地图形挑战。
- 本地图形挑战固定为六位混合字符、有效期 5 分钟且成功/失败均一次性消费；字符集排除易混淆字符，图像使用块状字形、随机缩放/偏移/旋转、交叉遮挡曲线和密集噪声，兼顾人工可读性与 OCR 抗性。
- 框架接入：观察 `Weline_Framework::view::form::before_close`，只为显式
  `captcha=auto|required` 的 `<w:form>` 注入挑战。服务端必须使用同一 `intent` 通过
  `CaptchaManagerInterface` 复验。
- 兼容面：旧 `CaptchaProviderInterface` / `CaptchaService` 保留；旧独立配置只在
  SystemConfig 对应键为空时迁移，不覆盖管理员新配置。

## 代码面概览

入口文件：
- `app/code/Weline/Captcha/etc/backend/menu.xml`

- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：2
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Interface`：已发布接口契约；跨模块依赖优先使用这里。 文件数：1
- `Model`：ORM 模型与字段 schema。 文件数：3
- `Observer`：事件观察者与订阅逻辑。 文件数：1
- `Service`：业务编排与模块服务层。 文件数：1
- `etc`：模块配置。 文件数：2
- `i18n`：国际化资源。 文件数：2
- `view/templates`：模块模板源文件。 文件数：1
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/Captcha/doc/google-enterprise-and-w-form.md`
- `app/code/Weline/Captcha/doc/event/失败次数超限.md`
- `app/code/Weline/Captcha/doc/event/验证码验证前.md`
- `app/code/Weline/Captcha/doc/event/验证码验证后.md`
- `app/code/Weline/Captcha/doc/event/验证码验证失败.md`
- `app/code/Weline/Captcha/doc/hook/backend/layouts/login/captcha.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
