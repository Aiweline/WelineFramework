# QA-06 / QA-09 · 框架 View 席证据

日期：2026-09-23  
席位：Team:框架/View  
仓库：`/Users/weline/Project/Official/框架`

## 结论

| ID | 根因 | 框架侧修复 | 状态 |
|---|---|---|---|
| QA-06 | `CodeGenerator::parseVarExpression` 对裸标识只加 `$` | 与 `parseSingleVariablePath` / `{{ident}}` 对齐 | **已修** |
| QA-09（根因之一） | `FormRenderer::BOOLEAN_ATTRIBUTES` 仅 `novalidate`，裸 `hidden` 丢弃 | 加入 `hidden` + reserved/normalize 对齐 | **已修** |

Checkout/Cart 状态机互斥（加载中 vs 空车 vs 有货）**不在本席范围**；本席只保证 `<w:form hidden>` SSR 真正输出 `hidden`，结账表单可被 CSS/属性隐藏。后续仍需 Checkout/Cart 席收敛三态。

## 改动列表

### QA-06

- `app/code/Weline/Framework/View/Taglib/Generator/CodeGenerator.php`
  - `parseVarExpression`：裸标识 / 点分路径改走 `parseSingleVariablePath`
  - 复合表达式内同样回退；已写 `$ident` 保持显式局部变量；函数名（后跟 `(`）不误伤
- `app/code/Weline/Framework/View/Taglib.php`
  - `COMPILER_GENERATION` → `20260923-if-condition-getdata-form-hidden-v4`（写入模板 compile hash，触发重编译）
- UT：`app/code/Weline/Framework/Test/Unit/View/Taglib/ConditionGetDataContractTest.php`（新建）
- 同步：`FormFiberCaptureContractTest` 代次断言对齐新 generation

### QA-09

- `app/code/Weline/Framework/View/Form/FormRenderer.php`
  - `BOOLEAN_ATTRIBUTES`：`novalidate` + **`hidden`**
  - `normalizeBoolean` / `isReservedLiteralAttributeValue` 纳入 `hidden`
- `app/code/Weline/Framework/View/Taglib.php`：`form` 声明属性补 `hidden => 0`
- 文档对齐：`doc/event/view/w-form.md`、`View/doc/Taglib/使用指南.md`
- UT：`FormRendererTest::testBareHiddenBooleanAttributeIsEmitted`（及 falsey 反例）

## 编译前后探针

```text
# 修前
<?php if($content): ?>…<?php elseif($other): ?>…

# 修后
<?php if(($content ?? $this->getData('content'))): ?>…
<?php elseif(($other ?? $this->getData('other'))): ?>…

# FormRenderer::open([…, 'hidden'=>'' …])
<form … class="c" hidden data-checkout-form data-weline-form="1" …>
```

## 单元测试

```bash
php bin/w phpunit:run --name=FormRendererTest
# 6/6 OK（含 bare hidden / falsey）

php bin/w phpunit:run --name=ConditionGetDataContractTest
# 3/3 OK

php bin/w phpunit:run --name=FormFiberCaptureContractTest
# 2/2 OK（generation 断言已更新）
```

## 布局重编译（勿 git clean）

官方路径（任选其一，优先 generation 自然失效）：

1. **已 bump `Taglib::COMPILER_GENERATION`**：下次渲染会因 compile hash 变化自动重编 `view/tpl`，无需 `git clean`。
2. 若需主动清模板编译缓存：`php bin/w template:clear`
3. 可选缓存：`php bin/w cache:clear`（非必须专清编译产物）

**禁止** `git clean` / `git restore` 擦编译目录。

## Checkout / Cart 后续（框架侧之外）

- Checkout：`view/frontend/checkout/index.phtml` 已写 `<w:form … hidden>`；重编译后 SSR 应带 `hidden`。仍需确认前端「加载中」卸 `hidden` 的时机与空车态互斥（QA-05/09 模块契约）。
- Cart：三态并存属 Cart 状态机，本席未改。
- 建议：模板代次生效后，Browser 复验 `/checkout`「加载中」与表单不同屏。
