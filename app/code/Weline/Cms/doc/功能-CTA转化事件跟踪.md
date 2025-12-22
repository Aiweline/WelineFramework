# PageBuilder - CTA 转化事件跟踪功能

## 📋 功能概述

为 PageBuilder 页面添加了可自定义的 CTA（Call To Action）转化事件名称功能，支持 Google Analytics 4 和 Facebook Pixel 事件跟踪。

## 🎯 功能特性

### 1. 自定义事件名称
- ✅ 在页面编辑界面可以自定义 CTA 事件名称
- ✅ 如果未设置，自动使用默认规则生成：`{页面句柄}_form_submit`
- ✅ 支持任意自定义事件名称，方便追踪特定营销活动

### 2. 自动事件跟踪
- ✅ **Google Analytics 4 (GA4)** 事件跟踪
  - 事件类型：自定义事件
  - 事件类别：Lead Generation
  - 事件标签：Form Submission
  - 事件值：1
- ✅ **Facebook Pixel** 事件跟踪
  - 事件类型：Lead
  - 内容名称：自定义事件名
  - 状态：completed

### 3. 智能触发机制
- 表单提交成功后自动触发
- 只在配置了跟踪代码时触发
- 包含完整的错误处理机制
- 控制台输出调试信息

## 📝 使用说明

### 后台配置

1. 进入 PageBuilder 页面编辑界面
2. 找到「跟踪代码」区域
3. 配置必要的跟踪 ID：
   - **Google Analytics 4 ID**：格式如 `G-XXXXXXXXXX`
   - **Facebook Pixel ID**：格式如 `123456789012345`
4. 配置 **CTA 转化事件名称**（可选）
   - 留空则自动生成为：`{页面句柄}_form_submit`
   - 自定义示例：`money_calendar_signup`、`free_report_download`

### 配置示例

#### 示例 1：使用默认事件名
```
页面句柄：money-calendar
CTA 事件名称：(留空)

最终事件名：money-calendar_form_submit
```

#### 示例 2：自定义事件名
```
页面句柄：money-calendar
CTA 事件名称：money_calendar_signup

最终事件名：money_calendar_signup
```

## 🔧 技术实现

### 数据库结构

#### Page 表新增字段
```sql
ALTER TABLE `guolairen_page_builder_page` 
ADD COLUMN `cta_event_name` VARCHAR(100) NULL COMMENT 'CTA转化事件名称';
```

### 关键代码

#### 1. Page 模型常量
```php
// app/code/GuoLaiRen/PageBuilder/Model/Page.php
public const fields_CTA_EVENT_NAME = 'cta_event_name';
```

#### 2. 事件名称生成逻辑
```php
// app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/content.phtml

// CTA 事件名称（如果未设置，使用默认：handle_form_submit）
$ctaEventName = $page ? $page->getData('cta_event_name') : '';
if (empty($ctaEventName) && $page) {
    $handle = $page->getData('handle') ?: 'page';
    $ctaEventName = $handle . '_form_submit';
}
```

#### 3. GA4 事件跟踪
```javascript
// Google Analytics 4 事件跟踪
if (ga4Id && typeof gtag !== 'undefined') {
    gtag('event', ctaEventName, {
        'event_category': 'Lead Generation',
        'event_label': 'Form Submission',
        'value': 1
    });
    console.log('✅ GA4 事件已触发:', ctaEventName);
}
```

#### 4. Facebook Pixel 事件跟踪
```javascript
// Facebook Pixel 事件跟踪
if (fbPixelId && typeof fbq !== 'undefined') {
    fbq('track', 'Lead', {
        content_name: ctaEventName,
        status: 'completed'
    });
    console.log('✅ Facebook Pixel 事件已触发:', ctaEventName);
}
```

## 📊 事件数据结构

### GA4 事件参数
| 参数 | 值 | 说明 |
|------|------|------|
| event | `{cta_event_name}` | 自定义事件名 |
| event_category | `Lead Generation` | 事件类别 |
| event_label | `Form Submission` | 事件标签 |
| value | `1` | 事件值 |

### Facebook Pixel 事件参数
| 参数 | 值 | 说明 |
|------|------|------|
| event | `Lead` | 标准事件类型 |
| content_name | `{cta_event_name}` | 自定义事件名 |
| status | `completed` | 转化状态 |

## 🎨 界面展示

### 后台表单
在「跟踪代码」卡片中新增的输入框：

```
┌─────────────────────────────────────────┐
│ 跟踪代码                               │
├─────────────────────────────────────────┤
│ Google Analytics 4 ID                  │
│ [___________________] 例如: G-XXXXXXXXXX│
│                                         │
│ Google Tag Manager ID                  │
│ [___________________] 例如: GTM-XXXXXXX │
│                                         │
│ Facebook Pixel ID                      │
│ [___________________] 例如: 123456...  │
│                                         │
│ CTA 转化事件名称 ⓘ                    │
│ [___________________]                   │
│ 例如：money_calendar_signup (留空自动生成) │
│ 用于 Google Analytics 和 Facebook      │
│ Pixel 事件跟踪。留空时将自动生成为：    │
│ <页面句柄>_form_submit                 │
└─────────────────────────────────────────┘
```

## 🧪 测试验证

### 测试步骤

1. **配置页面**
   - 创建或编辑一个 PageBuilder 页面
   - 配置 GA4 ID 和/或 Facebook Pixel ID
   - 可选：设置自定义 CTA 事件名称

2. **前端测试**
   - 访问页面
   - 填写表单并提交
   - 打开浏览器控制台查看事件触发日志：
     ```
     ✅ GA4 事件已触发: money-calendar_form_submit
     ✅ Facebook Pixel 事件已触发: money-calendar_form_submit
     ```

3. **GA4 验证**
   - 登录 Google Analytics
   - 进入「实时」报告
   - 查看「事件」部分
   - 确认自定义事件出现

4. **Facebook Pixel 验证**
   - 使用 Facebook Pixel Helper 浏览器扩展
   - 提交表单后查看触发的事件
   - 确认 `Lead` 事件包含正确的 `content_name`

## ⚠️ 注意事项

### 1. 跟踪代码配置
- 必须先配置 GA4 ID 或 Facebook Pixel ID，事件跟踪才会生效
- 两个跟踪代码可以同时配置，将同时触发事件

### 2. 事件命名规范
- 事件名称建议使用小写字母、数字和下划线
- 避免使用特殊字符和空格
- 示例：`money_calendar_signup`、`free_trial_register`

### 3. 隐私合规
- 确保遵守 GDPR、CCPA 等隐私法规
- 在加载跟踪代码前获取用户同意（如果适用）
- 提供隐私政策和退出选项

### 4. 预览模式
- 预览模式下跟踪代码不会执行
- 避免污染生产环境的统计数据
- 控制台会显示调试信息

## 📚 相关文档

- [PageBuilder-页面配置与跟踪代码](./PageBuilder-页面配置与跟踪代码.md)
- [marketing-landing-快速启动](./marketing-landing-快速启动.md)
- [功能-表单异步提交和跳转](./功能-表单异步提交和跳转.md)

## 🔄 版本历史

### v1.0.0 (2025-10-27)
- ✅ 添加 `cta_event_name` 数据库字段
- ✅ 后台表单添加事件名称配置输入框
- ✅ 实现默认事件名生成逻辑（handle + _form_submit）
- ✅ 集成 GA4 事件跟踪
- ✅ 集成 Facebook Pixel 事件跟踪
- ✅ 添加控制台调试日志
- ✅ 完善错误处理机制

## 💡 最佳实践

### 1. 事件命名策略
```
页面类型_行动_目标

示例：
- landing_signup_newsletter (落地页-注册-Newsletter)
- product_download_trial (产品页-下载-试用版)
- webinar_register_live (网络研讨会-注册-直播)
```

### 2. 转化漏斗跟踪
结合不同事件名称，可以建立完整的转化漏斗：
```
landing_page_view → landing_form_view → landing_form_submit → thankyou_page_view
```

### 3. A/B 测试
为不同版本的页面设置不同的事件名称：
```
- money_calendar_v1_submit
- money_calendar_v2_submit
```

## 🎉 总结

CTA 转化事件跟踪功能为 PageBuilder 提供了强大的营销分析能力：

✅ **易用性**：自动生成默认事件名，无需手动配置  
✅ **灵活性**：支持自定义事件名称，满足特定需求  
✅ **完整性**：同时支持 GA4 和 Facebook Pixel  
✅ **可靠性**：包含完整的错误处理和调试信息  
✅ **合规性**：预览模式自动禁用跟踪代码  

通过这个功能，您可以精准跟踪每个页面的转化效果，优化营销策略，提高ROI！🚀

