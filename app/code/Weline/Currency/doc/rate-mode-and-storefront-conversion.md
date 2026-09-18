# 汇率模式与前台换算

## 背景

当前站点商品基准价按 `CNY` 存储，前台路由币种可能切换为 `USD` / `EUR` / `GBP`。为避免前台继续显示写死的人民币价格，`Weline_Currency` 需要同时承担两件事：

1. 后台明确配置汇率来源模式。
2. 前台基于当前路由币种，把 `CNY` 基准价转换后再格式化输出。

## 汇率模式

- `manual`
  - 汇率来源：后台货币列表里的 `rate`
  - 含义：每个币种的 `rate` 都表示“相对于当前基准货币的汇率”
  - 例：基准为 `CNY` 时，`USD rate = 8.0` 表示 `1 USD = 8 CNY`
  - **切换基准货币**：先预览「旧汇率 → 新汇率」对照表，客户确认后才写入；按已有 `rate` 比例换算（`新 rate = 旧 rate ÷ 新基准在旧基准下的 rate`），不依赖 API
- `auto`
  - 汇率来源：第三方 API 导入到货币表里的 `rate`
  - `import_enabled` 只控制是否启用 Cron 自动拉取
  - 后台“手动导入”按钮仍可主动触发一次 API 更新
  - **语义对齐**：`exchangerate-api.com/v4/latest/{base}` 返回「1 基准币 = r 外币」；导入时写入 `rate = 1/r`（即「1 外币 = rate 基准币」），与 manual 模式及前台换算公式一致
  - 切换基准货币时同样先预览对照表并确认后再写入；若要与官网最新价对齐，应用完成后再点「手动导入」

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
- WLS 共享缓存：通过 Framework `SharedCacheStateFactoryInterface` 解析可选 Provider；
  Currency 不引用 Server 实现，Provider 缺失或不可用时继续使用进程内定义缓存。

## 注意

- `manual` 模式下禁止「从第三方 API 自动导入」；但**切换基准货币**会先弹出汇率对照预览，确认后按已有 rate 比例写入。
- `auto` 模式切换基准货币同样先预览确认；需要最新市价时再执行「手动导入」或等待 Cron。
- 未带 `confirm_base_currency_apply` 的保存请求不会改基准币。
- **`rate <= 0` 视为不可换算**：`tryConvert()` 返回 `null`，`convert()` 抛错；调用方不得把原金额贴上目标币符号（避免「只改符号」）。
- 店面目录定价走 `tryConvert`：缺汇率时该展示币价格记为 unresolved，而不是用基准币数字冒充。
- 顶栏货币切换器**不展示** `rate<=0` 的币种（基准币除外）；保存货币汇率后会清定义缓存并 `notifyCatalogChanged`，避免 GBP 等仍显示 `0.00`。
