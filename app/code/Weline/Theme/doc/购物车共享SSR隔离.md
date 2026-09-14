# 购物车共享 SSR 隔离

2026-09-13 实际匿名请求发现：新 worker 的首个 HTML 显示购物车 2 件和 ¥182，后续同连接请求为 0。对应编译 header 的 mini-cart 区域已是纯 HTML，数量和小计被写成字面量。该证据确认了请求摘要进入共享编译产物，不代表已证实跨用户商品明细泄漏。

`view/theme/frontend/widgets/header/mini-cart-icon/default.phtml` 会在部件编译阶段执行，不能调用 `HeaderCommerceData::resolveCartSummary()`，即使该 helper 只使用请求备忘。请求备忘不能改变调用方输出随后被共享的事实。

该模板现在只生成数量 0、当前上下文货币的零金额、空明细等中性首屏数据。公开服务端 `HeaderCommerceData` 查询接口保持原行为，购物车页面等私有消费者不受影响。模板布局、抽屉与数量/小计显示配置保持原样。

真实状态复用现有 `miniCartIcon` 模块：`bootMiniCartRoots()` 调用 `scheduleCartSync()`；`syncCartState()` 沿已有客户端摘要恢复规则，通过 `Weline.Api.resource('cart')` 的 `getCart` / `summary` 获取当前访客或会话状态，再由 `applySummary()` 更新。抽屉继续通过 `loadDrawer()` 加载当前购物车。没有增加接口、缓存或另一套前端请求。主题编辑器继续 `data-demo-chrome="0"`，使用同一条实时恢复链，不读取演示购物车。

旧编译产物与旧 header HTML 需要配套迁移：框架编译作用域版本 `context-env-v4`，header chrome 版本 `v12`，并将 mini-cart 源文件纳入已有嵌套 chrome 指纹。由统一部署流程编译和重载，不直接修改 `view/tpl`，不全局清池。

定向测试：

```bash
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --colors=never app/code/Weline/Theme/test/Unit/HeaderMiniCartGuestSsrTest.php
```

测试执行完整生产 PHP 模板：先在真实统一请求备忘中放入 2 件/$182 的摘要，再分别渲染前台和编辑器上下文，断言首屏为 0/$0、没有私有摘要，且原有 JS 恢复声明和交互边界保留。真实浏览器验收需核对空匿名首屏、现有购物车 API 完成后显示当前购物车，以及跨请求/worker 不重放旧摘要；由本轮统一运行验收记录补充。

## 2026-09-13 真实验收

本轮统一编译和重载后，隔离实例同一 worker 的第 1、2、3 个匿名请求均返回购物车数量 0、小计 ¥0.00；三次 footer 完整，错误 origin 链接均为 0。证据：`/tmp/weline-sept13-registry-cart-isolated-after-cart-check.json`。这验证了新 worker 首次编译及后续请求不再重放旧的 2 件/¥182 摘要。

已在 Weline Chrome 中禁用缓存验收：商品页筛选结果为 `1–24 of 104 products`，点击第 2 页后为 `25–48 of 104 products`；页面状态为 complete，footer 截图完整。现有购物车恢复链运行后，浏览器当前会话显示 2 件/¥172.00，与匿名 SSR 的中性状态区分正确。浏览器恢复连接后的 CDP 缓冲存在截断，未保留原始已认证接口响应体，因此接口结果以最终 DOM 和既有恢复链证据描述。记录：`/tmp/weline-sept13-registry-cart-browser.json`；截图：`/tmp/weline-sept13-registry-cart-browser-footer.png`。

功能验收通过，整体性能仍未通过。上述浏览器第 2 页 TTFB 为 12245.8 ms，load 为 21020.5 ms；匿名三次请求的服务端耗时分别为 27489.08、7405.27、7335.52 ms。不得以购物车隔离、分页及 footer 功能通过推断性能目标已经达成，剩余耗时由后续诊断继续处理。

默认实例最终控制面为 4/4 worker ready，未处于滚动重启、容量切换或关闭状态。证据：`/tmp/weline-default-final-control-status.summary.json`；该状态仅说明进程就绪，不替代业务性能验收。

验收地址：[商品筛选第 2 页](https://p05113ef3.test.weline.com:9555/en_US/products?af_hanfu_chao_dai=Ming+Style&page=2)。
