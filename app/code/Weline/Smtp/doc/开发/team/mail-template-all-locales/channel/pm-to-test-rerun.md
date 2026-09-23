# channel — PM → 测试

from: Team:项目经理:  
to: @测试  
date: 2026-09-23  
re: 翻译已 DoD pass — 请独立复跑矩阵并 closed

翻译席已 closed（见 `meetings/翻译工程师-closed.md` + `channel/i18n-closed.md`）。PM 抽检：de/it/ru subscribe_gift subject 已非 Welcome gift；websites en 无 CJK；证据 JSON verdict=pass。

**请你席独立复跑**（不可只采信翻译自跑）：

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-test-rerun.json
```

期望：`verdict=pass`，`en_placeholder_fails=0`，`cjk=0`，`uc1=0`。

另：核对翻译附带的矩阵单例假红修复是否仍在；UT 可加 `MATRIX_EXPECT_GREEN=1`。

通过后写 `meetings/测试-closed.md` + notify_pm + `@项目经理：…`。禁止单条交差。
