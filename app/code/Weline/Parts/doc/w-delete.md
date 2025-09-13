# w-delete 组件使用文档

## 概述

`w-delete` 是 WelineFramework 提供的一个智能删除确认组件，支持自动定位、自适应弹出方向，提供优雅的用户交互体验。

## 特性

- ✅ **智能定位**：根据触发按钮位置自动选择最佳显示位置
- ✅ **自适应方向**：水平和垂直方向智能自适应，避免挤压
- ✅ **边界检测**：自动检测视口边界，确保完全可见
- ✅ **响应式**：适应不同屏幕尺寸和滚动位置
- ✅ **事件委托**：支持动态添加的元素
- ✅ **多种HTTP方法**：支持 GET、POST、DELETE 等请求方式

## 基本用法

### 1. 引入组件

在页面底部引入 `w-delete` 组件：

```html
<js:part name="w-delete"/>
```

### 2. 基础删除按钮

```html
<a href="/api/delete/item/123" 
   w-delete="true"
   w-msg="确定要删除这个项目吗？"
   class="btn btn-danger">
   删除
</a>
```

### 3. 使用 w-delete="1"

```html
<button w-delete="1"
        w-url="/api/delete/item/123"
        w-msg="确定要删除这个项目吗？"
        class="btn btn-outline-danger">
   删除项目
</button>
```

## 属性说明

| 属性 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `w-delete` | `"true"` \| `"1"` | ✅ | 启用删除功能 |
| `w-msg` | `string` | ❌ | 自定义确认消息，默认为"确认删除吗？" |
| `w-url` | `string` | ❌ | 删除请求的URL（优先级：w-url > w-ajax > href） |
| `w-ajax` | `string` | ❌ | AJAX请求URL |
| `w-method` | `string` | ❌ | HTTP方法，默认为"DELETE" |
| `w-var-*` | `string` | ❌ | 额外的POST参数 |

## 高级用法

### 1. 自定义HTTP方法

```html
<a href="/api/items/123" 
   w-delete="true"
   w-method="POST"
   w-msg="确定要删除这个项目吗？此操作不可恢复！"
   class="btn btn-danger">
   删除
</a>
```

### 2. 传递额外参数

```html
<button w-delete="true"
        w-url="/api/delete/item"
        w-var-id="123"
        w-var-type="product"
        w-var-confirm="true"
        w-msg="确定要删除这个产品吗？"
        class="btn btn-danger">
   删除产品
</button>
```

### 3. 表单提交

```html
<form action="/api/delete/item" method="POST">
    <input type="hidden" name="id" value="123">
    <button w-delete="true"
            w-msg="确定要删除这个项目吗？"
            class="btn btn-danger">
       删除
    </button>
</form>
```

## 后端响应格式

### 成功响应

```json
{
    "success": true,
    "message": "删除成功"
}
```

### 失败响应

```json
{
    "success": false,
    "message": "删除失败：项目不存在"
}
```

## 定位逻辑

### 水平方向自适应

1. **优先右侧**：如果右侧有足够空间，在按钮右侧显示
2. **自动左侧**：如果右侧空间不够，自动切换到左侧
3. **居中显示**：如果左右都不够，居中显示在视口中央

### 垂直方向自适应

1. **优先下方**：如果下方有足够空间，在按钮下方显示
2. **自动上方**：如果下方空间不够，自动切换到上方
3. **居中显示**：如果上下都不够，显示在视口中央

## 样式自定义

### CSS 类名

- `.w-delete-confirm`：确认对话框容器
- `.w-delete-message`：消息提示容器
- `.w-delete-success`：成功消息样式
- `.w-delete-error`：错误消息样式

### 自定义样式示例

```css
.w-delete-confirm {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    padding: 20px;
    min-width: 300px;
}

.w-delete-message {
    border-radius: 6px;
    padding: 12px 16px;
    font-weight: 500;
}
```

## 实际应用示例

### 1. 表格中的删除按钮

```html
<table class="table">
    <tbody>
        <tr>
            <td>项目名称</td>
            <td>
                <a href="/api/delete/item/123" 
                   w-delete="true"
                   w-msg="确定要删除项目 '项目名称' 吗？此操作不可恢复！"
                   class="btn btn-sm btn-outline-danger">
                   删除
                </a>
            </td>
        </tr>
    </tbody>
</table>
```

### 2. 卡片中的删除按钮

```html
<div class="card">
    <div class="card-body">
        <h5 class="card-title">产品名称</h5>
        <p class="card-text">产品描述...</p>
        <button w-delete="true"
                w-url="/api/products/123"
                w-msg="确定要删除这个产品吗？"
                class="btn btn-danger">
           删除产品
        </button>
    </div>
</div>
```

### 3. 批量删除

```html
<button w-delete="true"
        w-url="/api/delete/batch"
        w-method="POST"
        w-var-ids="1,2,3,4,5"
        w-msg="确定要删除选中的 5 个项目吗？"
        class="btn btn-danger">
   批量删除 (5)
</button>
```

## 注意事项

1. **URL优先级**：`w-url` > `w-ajax` > `href`
2. **事件委托**：组件使用事件委托，支持动态添加的元素
3. **自动移除**：删除成功后会自动移除对应的表格行（如果存在）
4. **错误处理**：网络错误或服务器错误会显示相应的错误消息
5. **国际化**：确认和取消按钮支持 `__()` 函数进行国际化

## 兼容性

- ✅ 现代浏览器（Chrome 60+, Firefox 55+, Safari 12+, Edge 79+）
- ✅ 移动端浏览器
- ✅ 支持触摸操作

## 更新日志

### v2.0.0 (当前版本)
- 🆕 改进自适应定位算法
- 🆕 支持垂直方向自适应
- 🆕 优化边界检测逻辑
- 🆕 修复尺寸计算问题
- 🆕 提升用户体验

### v1.0.0
- 🆕 基础删除确认功能
- 🆕 水平方向自适应定位
- 🆕 支持多种HTTP方法
- 🆕 事件委托支持
