# 开发工具面板 - 快速参考

## 快速开始

### 1. 启用开发模式
```bash
php bin/m deploy:mode:set dev
```

### 2. 升级模块
```bash
php bin/m module:upgrade Weline_DeveloperWorkspace
```

### 3. 访问页面
访问任何前端或后端页面，在页面右侧会看到紫色的悬浮触发按钮 ◀

### 4. 使用面板
- 点击触发按钮展开/收起面板
- 切换 Frontend/Backend 标签查看不同类型的路由
- 使用搜索框快速查找模块或路由
- 点击路由项在新标签页中打开测试

## 特性亮点

✨ **智能悬浮** - 紧贴右侧边缘，不遮挡内容  
🎨 **美观设计** - 现代化渐变UI，视觉舒适  
📦 **模块分组** - 按模块组织，清晰明了  
🔍 **实时搜索** - 快速定位目标路由  
🔄 **状态记忆** - 自动保存面板状态  
🎯 **双模式** - Frontend/Backend 自由切换  

## 技术实现

```
DeveloperWorkspace 模块
├── Observer/DevToolPanelObserver.php  → 监听 footer 事件，注入面板
├── Controller/Api/Routes.php          → 提供路由数据 API
└── view/hooks/dev-tool-panel.phtml    → 面板 HTML/CSS/JS
```

## API 接口

```bash
# 获取 Frontend 路由
GET /dev/tool/api/routes?type=frontend

# 获取 Backend 路由  
GET /dev/tool/api/routes?type=backend

# 搜索路由
GET /dev/tool/api/routes/search?keyword=admin&type=frontend
```

## 配置文件

### etc/event.xml
```xml
<event name="Framework_View::footer">
    <observer name="Weline_DeveloperWorkspace::dev_tool_panel" 
              instance="Weline\DeveloperWorkspace\Observer\DevToolPanelObserver" 
              disabled="false" 
              shared="true"/>
</event>
```

## 禁用面板

### 方法一：切换到生产模式
```bash
php bin/m deploy:mode:set prod
```

### 方法二：禁用观察者
编辑 `etc/event.xml`，设置 `disabled="true"`

## 常见问题

**Q: 看不到面板？**  
A: 确保处于开发模式（DEV=true），并清除缓存

**Q: 面板显示不全？**  
A: 使用现代浏览器（Chrome/Firefox/Edge 最新版）

**Q: 搜索不工作？**  
A: 确保路由文件已生成，运行 `php bin/m module:upgrade`

## 完整文档

详细使用指南：`doc/开发工具面板使用指南.md`

---

**作者**: 秋枫雁飞  
**邮箱**: aiweline@qq.com  
**论坛**: https://bbs.aiweline.com

