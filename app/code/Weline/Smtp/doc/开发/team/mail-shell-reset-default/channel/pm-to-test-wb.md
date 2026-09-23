# pm-to-test-wb · mail-shell-reset-default

Team:项目经理: 本机站点已恢复（清掉僵死 lifecycle 锁后 `server:stop`→`server:start`；`127.0.0.1:9555` tcp_ok，workers 在听）。

请补做 WB-OP（此前 UT 已 pass 保留）：

1. Host：`https://p05113ef3.test.weline.com:9555/`（与 server:status 一致）
2. 后台模板编辑页 → 「重置为默认」：confirm 取消不提交；确认后壳区空、预览无库内炭黑朱砂页头覆盖
3. Browser：省略 position（非抢占）、禁缓存、抹 webdriver
4. 更新 `channel/测试-closed.md` 或写 `channel/测试-wb-closed.md`；notify_pm

勿宣称假 pass。
