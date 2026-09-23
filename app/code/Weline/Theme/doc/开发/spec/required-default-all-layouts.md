# 全布局必装部件店面验收

## 背景

用户纠偏：正确验证方式是**每个布局对应前端界面**检查 required 部件是否真实显示，而非单页（登录）抽检。

## 范围（本波店面）

| layout | URL |
|--------|-----|
| homepage | `/` |
| products | `/products` |
| category | `/category/women` |
| product | `/product/{slug}` |
| cart | `/cart` |
| checkout | `/checkout` |
| account/login | `/customer/account/login` |
| blog | `/blog` |
| not_found | `/__weline_required_injection_probe_404__` |

后端 dashboard / backend-order-* / mini-cart 抽屉：本波记录缺口，优先店面全页。

## 验收标准

对每页：HTML（去 style/script）含 `data-widget-code="{code}"` 或 `data-testid` / `data-w-component`。  
无 `user_deleted@{versionId}` 时缺失 = FAIL → 主题席返工。

## 工具

`php var/tmp/verify-required-injections-matrix.php` → `var/tmp/required-injection-matrix-result.json`
