# 介绍
WelineFramework框架的Taglib标签实现提供数据表格功能模块。
# 使用
composer require weline/module-data-table

# field字段功能



# 使用示例
```phtml
<w:d-table model="WeShop\Store\Model\Store" scope="store-listing">
    <w:d-form for="add,edit" title="添加店铺" form-mode="modal" form-title="添加店铺">
        <w:field name="name" type="text" label="名称" placeholder="请输入名称"></w:field>
        <w:field name="status" type="select" label="状态" options="1:启用,0:禁用"></w:field>
    </w:d-form>
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用"></w:field>
    </w:t-filter>
    <w:t-header>
        <w:field belong="t-header" name="store_id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" display_orderable="false" sortable="true" width="200">名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
    </w:t-header>
</w:d-table>
```