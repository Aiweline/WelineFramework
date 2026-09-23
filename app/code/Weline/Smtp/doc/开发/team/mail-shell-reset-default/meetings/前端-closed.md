# 前端 closed — mail-shell-reset-default

date: 2026-09-23  
seat: Team:前端:  
plan_id: fe-reset-ux  
result: delivered  
notify_pm: true

@项目经理：本席已交付，请检查并更新 SESSION

## 结论

- UC-2：点击「重置为默认」弹出浏览器 `confirm`；文案明示将丢失自定义正文与页头/页尾壳改动；取消则 `return false` 不提交。  
- testid 保持 `smtp-template-reset`；未另造平行按钮。

## 改动文件

| 路径 | 说明 |
|------|------|
| `view/Backend/Template/edit.phtml` | reset form `onsubmit=return confirm(__())` |
| `test/Unit/MailTemplateUiSendContractTest.php` | 断言 confirm + 简中确认文案 |
| `i18n/zh_Hans_CN.csv` | 确认文案源串 |
| `i18n/en_US.csv` | 确认文案英译 |

## UT

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateUiSendContractTest.php
```

结果：**5 tests, 199 assertions, OK**

## 交接

- 翻译席 `i18n-reset`：请 `php bin/w i18n:collect` 收录本席已写模块 CSV。  
- 后端 `be-reset-shell` / 测试 `test-reset`：清壳与 WB 不属本席。
