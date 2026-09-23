# channel — 测试 → PM

from: Team:测试:  
to: @项目经理  
date: 2026-09-23  
re: plan_id=test-reset **closed**（UC-1/UC-2 契约 UT）  
notify_pm: true

@项目经理：本席已交付，请检查并更新 SESSION。

## 结论

| 项 | 状态 |
|----|------|
| `MailShellRegionStoreContractTest` | **pass** |
| `MailShellHanfuDefaultsContractTest` | **pass** |
| `MailTemplateUiSendContractTest` | **pass** |
| 合计 | **11 tests / 235 assertions / OK**（1 PHPUnit Deprecation，非本波回归） |
| WB-OP（可选） | **blocked / 未执行**（见下；禁止造假 pass） |

本席最低交付（三契约 PHPUnit）**pass**；可选 WB 因本机编辑页不可达未做，不宣称浏览器 pass。

## 本席命令与结果

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailShellRegionStoreContractTest.php \
  app/code/Weline/Smtp/test/Unit/MailShellHanfuDefaultsContractTest.php \
  app/code/Weline/Smtp/test/Unit/MailTemplateUiSendContractTest.php
```

```text
PHPUnit 10.5.36
Configuration: /Users/weline/Project/Official/框架/tests/phpunit/config.xml

Mail Shell Hanfu Defaults Contract
 ✔ Defaults service exposes hanfu shell markup
 ✔ Ensure does not write back on empty header

Mail Shell Region Store Contract
 ✔ Merge region html replaces header and footer rows
 ✔ Merge region html keeps nested header table intact
 ✔ Merge region html joins legacy footer rows into single slot
 ✔ Clear method persists empty regions via save

Mail Template Ui Send Contract
 ✔ Backend ui and menu exist
 ✔ Send path uses template services
 ✔ Renderer escapes and raw
 ✔ Renderer evaluates if blocks
 ✔ Unpaid order reminder template if cleared

OK — Tests: 11, Assertions: 235, PHPUnit Deprecations: 1
```

覆盖要点（与 contracts UC 对齐，均由 UT 断言，非口头）：

- UC-1：`postReset` → `clear($storageScope)`；`clear` ≡ `save($scope, '', [])`；`ensure` 空 header 不写回
- UC-2：`edit.phtml` 含 `confirm(` + 简中确认串 + `data-testid="smtp-template-reset"`

## WB-OP（可选）阻断证据

目标 Host：`https://p05113ef3.test.weline.com:9555`（`server:status` 亦报此口；admin prefix=`jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH`）。

| 探活 | 结果 |
|------|------|
| `curl -skI https://p05113ef3.test.weline.com:9555/` | connect timeout（http_code=000） |
| `curl -skI https://127.0.0.1:9555/` | 同上 |
| `lsof -iTCP:9555 -sTCP:LISTEN` | 仅 master PID 66354 LISTEN；**无** `weline-wls-worker.*p05113ef3` |
| `php bin/w server:reload` | 拒：`lifecycle operation` 并发；锁 `var/server/locks/lifecycle_default.lock` purpose=`certificate_retirement_replay`（pid 63193） |

故无法按门禁做非抢占 navigate / 禁缓存 / 抹 `navigator.webdriver` 的重置 confirm 取消与确认后壳清空验收。**未开 Browser**；交付 URL：**N/A**。

建议 PM：lifecycle/`certificate_retirement_replay` 结束后 `server:reload` 或拉起 workers，再派一席补 WB-OP（取消不提交；确认后壳空、预览无库内炭黑朱砂覆盖）。

## 交付地址

N/A（本机验收页不可达；本席未打开验收 Browser）
