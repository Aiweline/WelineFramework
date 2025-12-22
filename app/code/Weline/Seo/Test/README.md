# Weline_Seo 模块测试说明

## 测试结构

```
Test/
├── Unit/                    # 单元测试
│   ├── Model/              # 模型测试
│   │   └── SeoSubjectTest.php
│   └── Service/            # 服务测试
│       └── FeedRegistryServiceTest.php
└── Integration/            # 集成测试
    └── Backend/
        └── DashboardTest.php
```

## 运行测试

### 运行所有测试

```bash
php bin/w p:r Weline_Seo
```

### 运行特定测试文件

```bash
php bin/w p:r app/code/Weline/Seo/Test/Unit/Model/SeoSubjectTest.php
```

## 测试覆盖范围

### 单元测试

- **Model 层**：
  - SeoSubject: 模型实例化、CRUD操作、Getters/Setters
  - SeoKeyword: 关键词管理
  - SeoKeywordTrend: 趋势数据管理
  - SeoSuggestion: AI建议管理

- **Service 层**：
  - FeedRegistryService: Feed提供者注册和发现
  - SubjectResolver: 主体解析
  - KeywordExtractorService: 关键词提取
  - TrendFetcherService: 趋势数据获取
  - SuggestionService: AI建议生成

### 集成测试

- **Controller 层**：
  - Dashboard: 后台面板功能
  - 路由和权限验证

- **Observer 层**：
  - StoreSaveAfter: Store保存后SEO处理

### Cron 测试

- KeywordTrendSync: 趋势同步任务执行

## 验收测试

### 功能验收

1. **模块安装**：
   - 运行 `php bin/w module:upgrade` 安装模块
   - 验证数据库表创建成功

2. **Store集成**：
   - 创建一个Store并保存
   - 验证SEO主体和关键词自动创建

3. **后台访问**：
   - 访问 `/admin/seo/backend/dashboard`
   - 验证SEO总览页面正常显示

4. **AI建议**：
   - 在主体详情页点击"刷新建议"
   - 验证AI建议生成成功

5. **Cron任务**：
   - 手动执行 `php bin/w cron:run seo_keyword_trend_sync`
   - 验证趋势数据同步成功

## 注意事项

- 测试需要数据库支持
- AI服务测试可能需要配置有效的AI模型
- 趋势平台适配器测试需要Mock或真实API配置

