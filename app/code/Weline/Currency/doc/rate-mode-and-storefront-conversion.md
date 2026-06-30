# 汇率模式与前台换算

## 背景

当前站点商品基准价按 `CNY` 存储，前台路由币种可能切换为 `USD` / `EUR` / `GBP`。为避免前台继续显示写死的人民币价格，`Weline_Currency` 需要同时承担两件事：

1. 后台明确配置汇率来源模式。
2. 前台基于当前路由币种，把 `CNY` 基准价转换后再格式化输出。

## 汇率模式

- `manual`
  - 汇率来源：后台货币列表里的 `rate`
  - 含义：每个币种的 `rate` 都表示“相对于 `CNY` 的汇率”
  - 例：`USD rate = 8.0` 表示 `1 USD = 8 CNY`
- `auto`
  - 汇率来源：第三方 API 导入到货币表里的 `rate`
  - `import_enabled` 只控制是否启用 Cron 自动拉取
  - 后台“手动导入”按钮仍可主动触发一次 API 更新

## 前台换算规则

- 若当前币种与基准币种相同，直接格式化原金额。
- 若商品基准价为 `CNY`，目标币种为 `USD`，且 `USD rate = 8.0`：
  - `9999 CNY -> 1249.875 USD`
  - 前台展示按币种格式化后输出，例如 `$1,249.88`

换算公式：

```text
amount_in_base = source == base ? amount : amount * source_rate
amount_in_target = target == base ? amount_in_base : amount_in_base / target_rate
```

## 当前实现落点

- 后台模式配置：`Weline\Currency\Controller\Backend\Config`
- 汇率模式配置模型：`Weline\Currency\Model\Config`
- 前台换算服务：`Weline\Currency\Service\CurrencyRateService`
- 静态格式化入口：`Weline\Currency\Helper\CurrencyFormatter`

## 注意

- `manual` 模式下禁止执行自动导入与基准币自动重算接口。
- 若基准币发生切换且仍处于 `manual` 模式，需要人工同步维护各币种 `rate`。
