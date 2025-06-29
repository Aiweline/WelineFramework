# DataTable HTML结构说明

## 概述

DataTable模块现在使用正确的HTML结构，其中：
- `d-table` 内部是完整的 `<table>` 结构
- `t-header` 生成 `<tr>` 元素，内容直接放入 `<thead>` 中
- `t-filter` 是独立的过滤器区域，不是表格结构的一部分

## HTML结构

### 基本结构

```html
<div id="datatable-xxx" class="weline-datatable" data-model="ModelClass" data-scope="scope">
    <div class="datatable-container">
        <!-- 过滤器区域 -->
        <div class="datatable-filter" id="datatable-xxx-filter">
            <div class="datatable-filter-container">
                <div class="datatable-filter-toolbar">
                    <div class="datatable-filter-left">
                        <div class="datatable-filter-form">
                            <!-- t-filter的内容 -->
                        </div>
                    </div>
                    <div class="datatable-filter-right">
                        <button type="button" class="btn btn-primary btn-sm">搜索</button>
                        <button type="button" class="btn btn-secondary btn-sm">重置</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 表格区域 -->
        <div class="datatable-body">
            <div class="datatable-content">
                <table class="table table-striped table-bordered">
                    <thead>
                        <!-- t-header的内容 -->
                        <tr class="datatable-header-row">
                            <th data-field="id" data-sort-field="sort.id">ID</th>
                            <th data-field="name" data-sort-field="sort.name">名称</th>
                            <th data-field="status" data-sort-field="sort.status">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据行 -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 分页区域 -->
        <div class="datatable-footer">
            <div class="datatable-pagination"></div>
        </div>
    </div>
</div>
```

## 使用示例

### 完整的DataTable示例

```html
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table" editable="true" searchable="true">
    <!-- 过滤器区域 -->
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用"></w:field>
        <w:field belong="t-filter" name="created_at" type="date"></w:field>
    </w:t-filter>
    
    <!-- 表格头部 -->
    <w:t-header>
        <w:field belong="t-header" name="id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true" width="200">名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
        <w:field belong="t-header" name="created_at" sortable="true" width="150">创建时间</w:field>
    </w:t-header>
</w:d-table>
```

### 生成的HTML结构

```html
<div id="datatable-xxx" class="weline-datatable" data-model="Weline\Demo\Model\Demo" data-scope="demo-table">
    <div class="datatable-container">
        <!-- 过滤器区域 -->
        <div class="datatable-filter" id="datatable-xxx-filter">
            <div class="datatable-filter-container">
                <div class="datatable-filter-toolbar">
                    <div class="datatable-filter-left">
                        <div class="datatable-filter-form">
                            <div class="filter-field">
                                <input type="text" class="form-control form-control-sm" 
                                       id="filter-name" name="filter[name]" 
                                       data-field="name" placeholder="搜索名称" title="name">
                            </div>
                            <div class="filter-field">
                                <select class="form-control form-control-sm" 
                                        id="filter-status" name="filter[status]" 
                                        data-field="status" title="status">
                                    <option value="">请选择</option>
                                    <option value="1">启用</option>
                                    <option value="0">禁用</option>
                                </select>
                            </div>
                            <div class="filter-field">
                                <input type="date" class="form-control form-control-sm" 
                                       id="filter-created_at" name="filter[created_at]" 
                                       data-field="created_at" title="created_at">
                            </div>
                        </div>
                    </div>
                    <div class="datatable-filter-right">
                        <button type="button" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm">
                            <i class="fas fa-undo"></i> 重置
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 表格区域 -->
        <div class="datatable-body">
            <div class="datatable-content">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr class="datatable-header-row">
                            <th data-field="id" data-sort-field="sort.id" style="width: 80px;">
                                <a href="?current=id&sort.id=desc">ID <i class="fa fa-sort"></i></a>
                            </th>
                            <th data-field="name" data-sort-field="sort.name" style="width: 200px;">
                                <a href="?current=name&sort.name=desc">名称 <i class="fa fa-sort"></i></a>
                            </th>
                            <th data-field="status" data-sort-field="sort.status" style="width: 100px;">
                                <a href="?current=status&sort.status=desc">状态 <i class="fa fa-sort"></i></a>
                            </th>
                            <th data-field="created_at" data-sort-field="sort.created_at" style="width: 150px;">
                                <a href="?current=created_at&sort.created_at=desc">创建时间 <i class="fa fa-sort"></i></a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据行将通过JavaScript动态加载 -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
```

## 布局特点

### 1. 过滤器区域
- 独立的容器，不参与表格结构
- 水平布局，左侧是过滤字段，右侧是操作按钮
- 响应式设计，在小屏幕上变为垂直布局
- 紧凑的表单控件，适合工具栏使用

### 2. 表格头部
- 直接生成 `<tr>` 元素，内容放入 `<thead>` 中
- 每个字段生成 `<th>` 元素
- 支持排序链接和图标
- 可调整列宽和样式

### 3. 响应式设计
- 过滤器在小屏幕上重新排列
- 表格支持水平滚动
- 移动端友好的布局

## CSS类说明

### 过滤器相关
- `.datatable-filter-container`: 过滤器主容器
- `.datatable-filter-toolbar`: 过滤器工具栏
- `.datatable-filter-left`: 左侧过滤字段区域
- `.datatable-filter-right`: 右侧操作按钮区域
- `.filter-field`: 单个过滤字段容器

### 表格相关
- `.datatable-header-row`: 表头行
- `.datatable-content`: 表格内容区域
- `.datatable-body`: 表格主体区域

## JavaScript集成

DataTable会自动初始化以下功能：
- 过滤器搜索和重置
- 表格排序
- 分页
- 数据加载
- 响应式处理

## 注意事项

1. **belong属性**: 每个field标签必须指定 `belong="t-header"` 或 `belong="t-filter"`
2. **字段验证**: 所有字段必须在对应的Model中存在
3. **模板字段**: 模板中定义的字段默认可见/可搜索，其余字段需要用户配置
4. **响应式**: 布局会自动适应不同屏幕尺寸 