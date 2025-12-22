# 营销落地页模板 - 快速启动指南

## 🚀 5分钟快速上手

本指南帮助您在 5 分钟内创建一个完整的营销落地页。

## 📋 前置准备

- [ ] 准备好 Logo 图片（推荐白色 Logo，PNG 格式）
- [ ] 准备展示图片（建议尺寸 1200x800，JPG/PNG 格式）
- [ ] 准备页面文案（标题、副标题、按钮文字）
- [ ] 确认跟踪代码（GA4、GTM、FB Pixel，可选）

## 步骤 1：创建页面（1分钟）

### 1.1 进入后台

导航至：**页面构建器 → 新建页面**

### 1.2 填写基本信息

```
页面句柄: money-calendar
页面类型: 自定义页面
页面名称: 【运营】金钱日历落地页
页面标题: Secret Money Calendar - 3 Stocks to Explode
```

### 1.3 填写 SEO 信息

```
SEO 标题: "Secret Money Calendar" Reveals 3 Stocks to Explode in the Coming Weeks
SEO 描述: Discover the secret trading calendar that's provided traders with the chance to make 100%+ potential gains. Sign up now to receive the full report.
SEO 关键词: trading calendar, stock tips, investment strategy, trading signals
```

### 1.4 上传 Logo（可选）

如不上传，将使用默认白色 Logo。

### 1.5 选择样式模板

在**页面样式**下拉框中选择：`marketing-landing`

### 1.6 保存草稿

点击**创建页面**按钮。

## 步骤 2：配置 Header（1分钟）

### 2.1 打开可视化配置

点击右侧悬浮按钮**可视化配置** (调色板图标)

### 2.2 展开 Header 配置

找到 `marketing-landing_header.phtml` 并点击展开

### 2.3 配置 Logo

```
Logo设置:
  ✓ 显示Logo: yes
  ✓ Logo位置: center
  ✓ Logo宽度: 120/200
```

### 2.4 配置主标题

```
主标题设置:
  ✓ 主标题文本: "Secret Money Calendar" Reveals 3 Stocks to Explode in the Coming Weeks
  ✓ 标题颜色: #ffffff
  ✓ 标题字号: 28/48
  ✓ 标题字重: 700
  ✓ 对齐方式: center
```

### 2.5 配置副标题

```
副标题设置:
  ✓ 副标题文本: 
     There's a **secret trading calendar…**
     
     That's provided traders with the **chance to make 100%+ potential gains…**
  
  ✓ 副标题颜色: #e0e0e0
  ✓ 副标题字号: 16/20
  ✓ 对齐方式: center
```

### 2.6 配置背景

```
样式设置:
  ✓ 背景颜色: #020818
  ✓ 使用渐变背景: no
```

点击**保存配置**

## 步骤 3：配置 Content（2分钟）

### 3.1 展开 Content 配置

找到 `marketing-landing_content.phtml` 并点击展开

### 3.2 配置布局

```
布局设置:
  ✓ PC端布局方向: image-right (图片在右侧)
  ✓ 元素间距: 20/40
  ✓ 最小高度: 0/224
```

### 3.3 配置表单

```
表单设置:
  ✓ 表单标题: Sign up below to receive full report
  ✓ 标题颜色: #ffffff
  ✓ 表单背景色: rgba(255, 255, 255, 0.05)
  ✓ 圆角大小: 8
```

### 3.4 配置输入框

```
输入框设置:
  ✓ 背景颜色: #ffffff
  ✓ 文字颜色: #333333
  ✓ 边框颜色: #dddddd
  ✓ 圆角大小: 4
  ✓ 输入框高度: 44/48
```

### 3.5 配置按钮

```
按钮设置:
  ✓ 按钮文本: Get Strategy For Free!
  ✓ 背景颜色: #f39c12
  ✓ 悬停背景色: #e67e22
  ✓ 文字颜色: #ffffff
  ✓ 按钮高度: 48/56
  ✓ 圆角大小: 6
```

### 3.6 配置图片

```
图片设置:
  ✓ 图片地址: https://your-cdn.com/images/money-calendar.jpg
  ✓ 图片描述: Money Calendar Strategy
  ✓ 圆角大小: 8
```

### 3.7 配置免责声明

```
免责声明设置:
  ✓ 免责声明文本: 
     By entering your info and clicking below, you agree to receive marketing communications. Messaging frequency varies. Reply STOP to opt-out.
  
  ✓ 文字颜色: #999999
  ✓ 字体大小: 11/12
```

点击**保存配置**

## 步骤 4：配置 Footer（1分钟）

### 4.1 展开 Footer 配置

找到 `marketing-landing_footer.phtml` 并点击展开

### 4.2 配置样式

```
样式设置:
  ✓ 背景颜色: #020818
  ✓ 文字颜色: #999999
  ✓ 链接颜色: #ffffff
  ✓ 显示顶部边框: yes
  ✓ 边框颜色: rgba(255, 255, 255, 0.1)
```

### 4.3 配置内容

```
内容设置:
  ✓ 显示版权信息: yes
  ✓ 版权文本: © 2024 Street Sense Daily. All Rights Reserved.
  
  ✓ 显示链接: yes
  ✓ 链接列表:
     Privacy Policy|/privacy
     Terms of Service|/terms
     Contact Us|/contact
  
  ✓ 显示免责声明: yes
  ✓ 免责声明:
     Trading and investing involve risk. Past performance is not indicative of future results. Please consult with a financial advisor before making investment decisions.
```

点击**保存配置**

## 步骤 5：发布页面

### 5.1 设置状态

在页面编辑界面，将**状态**改为：`已发布`

### 5.2 保存页面

点击**更新页面**按钮

### 5.3 访问页面

页面 URL: `/pagebuilder/page/view?handle=money-calendar`

或配置友好 URL: `/money-calendar`

## ✅ 检查清单

发布前请确认：

- [ ] 所有文字内容已填写正确
- [ ] Logo 显示正常
- [ ] 图片加载成功
- [ ] 表单可以正常提交
- [ ] 在移动端测试过（响应式布局）
- [ ] 在不同浏览器测试过
- [ ] SEO 信息完整
- [ ] 跟踪代码已添加（如需要）

## 📱 移动端测试

### Chrome DevTools

1. 按 `F12` 打开开发者工具
2. 点击设备图标切换到移动视图
3. 选择不同设备型号测试
4. 测试表单提交

### 真机测试

1. 在手机浏览器打开页面
2. 检查布局是否正常
3. 测试表单输入和提交
4. 检查字体大小是否合适

## 🎨 常用配色方案

### 方案 1：金融专业（当前）
```
背景: #020818
标题: #ffffff
副标题: #e0e0e0
按钮: #f39c12 → #e67e22
```

### 方案 2：科技蓝
```
背景: #0a1929
标题: #ffffff
副标题: #b0bec5
按钮: #00AFE9 → #0288d1
```

### 方案 3：商务绿
```
背景: #1a2f23
标题: #ffffff
副标题: #c8e6c9
按钮: #4caf50 → #388e3c
```

### 方案 4：活力橙
```
背景: #2c1810
标题: #ffffff
副标题: #ffccbc
按钮: #ff5722 → #e64a19
```

## 🔧 常见问题

### Q: Logo 看不见
**A**: Logo 可能是白色的，需要深色背景。检查 Header 背景色是否设置为深色。

### Q: 表单提交后没反应
**A**: 
1. 检查浏览器控制台是否有错误
2. 确认表单 action 地址正确
3. 检查邮箱和电话格式验证

### Q: 图片不显示
**A**: 
1. 确认图片 URL 可以访问
2. 检查图片格式（支持 JPG、PNG、WebP）
3. 确认图片大小不超过 5MB

### Q: 移动端布局乱了
**A**: 
1. 清除浏览器缓存
2. 检查响应式配置是否正确
3. 使用 Chrome DevTools 检查 CSS

### Q: 按钮颜色不对
**A**: 
1. 检查按钮背景色配置
2. 确认悬停色已设置
3. 清除浏览器缓存后重试

## 📊 查看表单提交数据

### 方法 1：后台查看

1. 进入：**页面构建器 → 表单提交记录**
2. 筛选页面：选择你的页面
3. 查看提交列表

### 方法 2：数据库查询

```sql
SELECT 
    email,
    phone,
    ip_address,
    create_time,
    status
FROM guolairen_page_builder_form_submission
WHERE page_id = YOUR_PAGE_ID
ORDER BY create_time DESC;
```

### 方法 3：导出数据

1. 在提交记录页面
2. 勾选要导出的记录
3. 点击**导出为 CSV**

## 🚀 进阶优化

### 添加 A/B 测试

1. 创建多个版本的页面
2. 使用不同的 handle
3. 使用重定向规则随机分配流量
4. 对比转化率数据

### 添加倒计时

在 header 副标题中添加：
```
Offer expires in: **24 hours**
```

然后用 JavaScript 实现动态倒计时。

### 添加社会证明

在表单上方添加：
```
✓ **10,000+** traders have already signed up
✓ **Featured in** Wall Street Journal, Forbes, CNBC
```

### 集成 CRM

编辑 `Controller/Frontend/Form.php`，添加 API 调用：
```php
// 同步到 Salesforce/HubSpot/Mailchimp
$this->crmService->createLead($email, $phone);
```

## 📈 转化率追踪

### Google Analytics 事件

在表单提交成功后触发事件：
```javascript
gtag('event', 'form_submit', {
  'event_category': 'Lead Generation',
  'event_label': 'Money Calendar',
  'value': 1
});
```

### Facebook Pixel 事件

```javascript
fbq('track', 'Lead', {
  content_name: 'Money Calendar',
  currency: 'USD',
  value: 10.00
});
```

## 🎯 下一步

- [ ] 设置自动回复邮件
- [ ] 配置 CRM 集成
- [ ] 添加感谢页面
- [ ] 设置再营销广告
- [ ] 创建邮件培育序列

---

**需要帮助？** 
- 查看完整文档：`readme.md`
- 技术支持：联系开发团队
- 在线文档：[PageBuilder 文档中心](../../doc/)

**祝您转化率爆表！** 🎉

