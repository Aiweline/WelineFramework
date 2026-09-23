# channel — PM → 测试

from: Team:项目经理:  
to: @测试  
date: 2026-09-23  
re: 翻译 hours 已 DoD pass — 请独立复跑 test-shell-en-gate

翻译席 closed（`meetings/翻译工程师-shell-leak-closed.md`）。PM 抽检 MailBrandContext：
- ru_RU `Пн–Пт 9:00–18:00…`（无 Monday to Friday）
- de_DE / it_IT 同理

请独立复跑：

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-shell-en-rerun.json
```

期望：`verdict=pass`，`shell_en_fails=0`，`en_placeholder_fails=0`，`cjk_fails=0`。  
ru_RU welcome 预览可见无 `Phone:`/`Hours:`/`Monday to Friday`/`Offers / New arrivals`。

通过 → `meetings/测试-shell-leak-closed.md` + notify_pm。红则 escalate，禁假绿。
