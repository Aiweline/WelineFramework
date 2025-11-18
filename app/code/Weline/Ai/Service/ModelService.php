<?php
// ...existing code...
class ModelService {
    // ...existing code...

    /**
     * 复制模型：默认复用原始供应商引用；当 options['clone_supplier'] = true 时创建新的供应商记录并引用它。
     * @param int $modelId
     * @param array $options  可选键：name, config, proxy_info, is_active, clone_supplier
     * @return mixed 复制后的模型记录对象
     * @throws \Exception
     */
    public function copyModel(int $modelId, array $options = [])
    {
        // 获取源模型
        $src = $this->model->reset()->where('id', $modelId)->find()->fetch();
        if (!$src) {
            throw new \Exception(__('Model #%{id} not found', ['id' => $modelId]));
        }

        // 创建副本行
        $copy = $this->model->createRow();

        // 复制基础不可修改信息
        // 尝试兼容不同获取方式
        $getField = function($obj, $field, $getter = null) {
            if (is_array($obj) && array_key_exists($field, $obj)) return $obj[$field];
            if (is_object($obj)) {
                if ($getter && method_exists($obj, $getter)) return $obj->{$getter}();
                if (property_exists($obj, $field)) return $obj->{$field};
            }
            return null;
        };

        $copy->model_code = $getField($src, 'model_code', 'getModelCode');
        $copy->version = $getField($src, 'version', 'getVersion');

        // 处理供应商信息：支持 supplier 字符串字段和 supplier_id 关联
        $supplierId = $getField($src, 'supplier_id', 'getSupplierId');
        $supplierName = $getField($src, 'supplier', 'getSupplier');

        if ($supplierId) {
            // 如果需要克隆供应商为新记录
            if (!empty($options['clone_supplier']) && isset($this->supplierModel)) {
                $srcSup = $this->supplierModel->reset()->where('id', $supplierId)->find()->fetch();
                if ($srcSup) {
                    $newSup = $this->supplierModel->createRow();

                    // 复制常见字段，兼容 getter 或 属性
                    $newSup->name = $getField($srcSup, 'name', 'getName') ?? $supplierName ?? '';
                    // 可扩展复制其它字段（说明：按需添加）
                    if (property_exists($srcSup, 'config')) $newSup->config = $srcSup->config;

                    $newSup->created_time = date('Y-m-d H:i:s');
                    $newSup->save();

                    // 关联新创建的供应商
                    $newId = $getField($newSup, 'id', 'getId');
                    if ($newId) {
                        $copy->supplier_id = $newId;
                        $copy->supplier = $getField($newSup, 'name', 'getName');
                    } else {
                        // 回退为复用原始引用
                        $copy->supplier_id = $supplierId;
                        $copy->supplier = $supplierName;
                    }
                } else {
                    // 源供应商未找到，复用名称或清空
                    $copy->supplier_id = $supplierId;
                    $copy->supplier = $supplierName;
                }
            } else {
                // 默认复用原始供应商引用
                $copy->supplier_id = $supplierId;
                $copy->supplier = $supplierName;
            }
        } else {
            // 没有 supplier_id，复制 supplier 字符串
            $copy->supplier = $supplierName;
        }

        // 复制其它字段（允许通过 options 覆盖）
        $srcName = $getField($src, 'name', 'getName');
        $copy->name = $options['name'] ?? ($srcName ? $srcName . ' - Copy' : 'Copy of model ' . $modelId);

        $copy->config = $options['config'] ?? $getField($src, 'config', 'getConfig');
        $copy->max_tokens = $getField($src, 'max_tokens', 'getMaxTokens');
        $copy->input_cost = $getField($src, 'input_cost', 'getInputCost');
        $copy->output_cost = $getField($src, 'output_cost', 'getOutputCost');
        $copy->capabilities = $getField($src, 'capabilities', 'getCapabilities');
        $copy->proxy_info = $options['proxy_info'] ?? $getField($src, 'proxy_info', 'getProxyInfo');
        $copy->tags = $getField($src, 'tags', 'getTags');

        // 标记为复制模型
        $copy->is_copied = 1;
        $copy->is_active = $options['is_active'] ?? 0;
        $copy->status = $getField($src, 'status', 'getStatus') ?? 'active';
        $copy->created_time = date('Y-m-d H:i:s');

        $copy->save();

        return $copy;
    }

    /**
     * 删除模型：仅允许删除复制模型（is_copied=1），执行软删除（is_active=0, status='deleted'）
     * @param int $modelId
     * @return bool
     * @throws \Exception
     */
    public function deleteModel(int $modelId)
    {
        $rec = $this->model->reset()->where('id', $modelId)->find()->fetch();
        if (!$rec) {
            throw new \Exception(__('Model #%{id} not found', ['id' => $modelId]));
        }

        // 获取 is_copied 字段的值，兼容 getter 或属性
        $isCopied = null;
        if (method_exists($rec, 'getIsCopied')) {
            $isCopied = $rec->getIsCopied();
        } elseif (property_exists($rec, 'is_copied')) {
            $isCopied = $rec->is_copied;
        } elseif (isset($rec->is_copied)) {
            $isCopied = $rec->is_copied;
        } else {
            $isCopied = 0;
        }

        if (empty($isCopied)) {
            // 原始模型不允许删除
            throw new \Exception(__('该模型为系统原始模型，不能删除。如需移除请先取消保护或联系管理员。'));
        }

        // 执行软删除，优先使用模型实例的字段和 save 方法
        if (property_exists($rec, 'is_active')) $rec->is_active = 0;
        if (property_exists($rec, 'status')) $rec->status = 'deleted';
        if (property_exists($rec, 'updated_time')) $rec->updated_time = date('Y-m-d H:i:s');

        if (method_exists($rec, 'save')) {
            $rec->save();
            return true;
        }

        // 如果没有 save 方法，尝试使用模型层更新（兼容性回退）
        try {
            $this->model->reset()->where('id', $modelId)->update([
                'is_active' => 0,
                'status' => 'deleted',
                'updated_time' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (\Exception $e) {
            throw new \Exception(__('删除失败: %{msg}', ['msg' => $e->getMessage()]));
        }
    }

    // ...existing code...
}