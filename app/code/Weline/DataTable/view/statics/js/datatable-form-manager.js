/**
 * DataTable 表单管理器
 * 处理表单的初始化、字段生成、提交等功能
 *
 * @version 2.0.0
 * @author Weline Framework
 * @description 增强版表单管理器，支持字段验证、自动保存、文件上传等功能
 */

// 如果没有__函数，则定义一个
if (typeof __ === 'undefined') {
    function __(text) {
        return text;
    }
}

// DataTableFormManager 单例模式
(function () {
    'use strict';

    // 如果已经存在实例，直接返回
    if (window.DataTableFormManager && window.DataTableFormManager._instance) {
        return window.DataTableFormManager;
    }

    // 创建单例实例
    const DataTableFormManagerInstance = {
        _instance: true, // 标记为单例实例
        _initialized: false,

        // 表单实例存储
        instances: {},

        // 配置选项
        config: {
            apiUrl: (typeof window !== 'undefined' && typeof window.api === 'function')
                ? window.api('datatable/rest/v1')
                : (typeof window !== 'undefined' && window.site && window.site.api_host)
                    ? (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') + 'datatable/rest/v1'
                    : '/api/rest/v1/datatable',
            fieldApiUrl: (typeof window !== 'undefined' && typeof window.api === 'function')
                ? window.api('datatable/rest/v1/form/fields')
                : (typeof window !== 'undefined' && window.site && window.site.api_host)
                    ? (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') + 'datatable/rest/v1/form/fields'
                    : '/api/rest/v1/datatable/form/fields',
            validateOnChange: true,
            autoSave: false,
            showValidationErrors: true,
            debounceDelay: 300
        },

        /**
         * 初始化表单
         */
        initForm: function (formId, options) {
            console.log('初始化表单:', formId, options);

            // 存储表单实例
            this.instances[formId] = {
                id: formId,
                options: options,
                fields: [],
                autoFields: [],
                manualFields: []
            };

            // 提取手动设置的字段
            this.extractManualFields(formId);

            // 如果需要自动生成字段
            if (options.autoFields) {
                this.loadAutoFields(formId);
            } else {
                this.hideLoadingFields(formId);
            }

            // 如果是编辑模式，加载记录数据
            if (options.mode === 'edit' && options.recordId) {
                this.loadRecordData(formId, options.recordId);
            }

            // 绑定表单事件
            this.bindFormEvents(formId);
        },

        /**
         * 提取手动设置的字段
         */
        extractManualFields: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const manualFields = form.querySelectorAll('.w-form-field');
            const instance = this.instances[formId];

            manualFields.forEach(field => {
                const fieldName = field.querySelector('input, select, textarea')?.name;
                if (fieldName) {
                    instance.manualFields.push(fieldName);
                }
            });

            console.log('手动设置的字段:', instance.manualFields);
        },

        /**
         * 加载自动生成的字段
         */
        loadAutoFields: function (formId) {
            const instance = this.instances[formId];
            if (!instance) return;

            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);
            if (!autoFieldsContainer) return;

            // 构建请求参数
            const requestData = {
                form_id: formId,
                model: instance.options.model,
                scope: instance.options.scope,
                exclude_fields: instance.options.excludeFields,
                include_fields: instance.options.includeFields,
                manual_fields: instance.manualFields
            };

            // 发送请求获取字段信息
            const apiUrl = (typeof window !== 'undefined' && typeof window.api === 'function')
                ? window.api('datatable/rest/v1/form/fields')
                : this.config.fieldApiUrl;
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestData)
            })
                .then(response => response.json())
                .then(response => {
                    // 兼容两种响应格式：{success, data, message} 和 {msg, data, code}
                    const isSuccess = response.success || response.code === 200 || response.code === '200';
                    if (isSuccess) {
                        this.renderAutoFields(formId, response.data);
                    } else {
                        this.showError(formId, response.message || response.msg || __('加载字段失败'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('网络错误'));
                });
        },

        /**
         * 渲染自动生成的字段
         */
        renderAutoFields: function (formId, fieldsData) {
            const instance = this.instances[formId];
            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);

            if (!autoFieldsContainer) return;

            // 清空加载提示
            autoFieldsContainer.innerHTML = '';

            // 过滤字段
            let fields = fieldsData.fields || [];

            // 排除手动设置的字段
            fields = fields.filter(field => !instance.manualFields.includes(field.name));

            // 排除指定字段
            if (instance.options.excludeFields && instance.options.excludeFields.length > 0) {
                fields = fields.filter(field => !instance.options.excludeFields.includes(field.name));
            }

            // 只包含指定字段
            if (instance.options.includeFields && instance.options.includeFields.length > 0) {
                fields = fields.filter(field => instance.options.includeFields.includes(field.name));
            }

            // 渲染字段
            fields.forEach(field => {
                const fieldHtml = this.generateFieldHtml(field);
                autoFieldsContainer.insertAdjacentHTML('beforeend', fieldHtml);
            });

            // 存储自动字段信息
            instance.autoFields = fields;

            // 字段渲染完成后调整布局
            const modal = document.getElementById('w-form-modal-' + formId);
            if (modal) {
                this.adjustModalLayout(formId, modal);
            }

            console.log('自动生成的字段:', fields);
        },

        /**
         * 生成字段HTML
         */
        generateFieldHtml: function (field) {
            const fieldId = 'field-' + field.name;
            const requiredAttr = field.required ? 'required' : '';
            const readonlyAttr = field.readonly ? 'readonly' : '';
            const disabledAttr = field.disabled ? 'disabled' : '';
            const maxlengthAttr = field.maxlength > 0 ? 'maxlength="' + field.maxlength + '"' : '';
            const placeholder = field.placeholder || __('请输入%{1}', [field.label || field.name]);

            // 构建字段 CSS 类
            let fieldClasses = ['w-form-field', 'w-field-type-' + field.type];
            
            // 占满整行的字段类型
            const fullWidthTypes = ['textarea', 'file', 'image'];
            if (fullWidthTypes.includes(field.type)) {
                fieldClasses.push('w-field-full-width');
            }

            let fieldHtml = '<div class="' + fieldClasses.join(' ') + '" data-type="' + field.type + '" data-field="' + field.name + '">';

            // 字段标签
            if (field.label) {
                const requiredMark = field.required ? '<span class="w-required-mark">*</span>' : '';
                fieldHtml += '<label for="' + fieldId + '" class="w-field-label">' + field.label + requiredMark + '</label>';
            }

            // 字段控件
            fieldHtml += '<div class="w-field-control">';

            switch (field.type) {
                case 'textarea':
                    fieldHtml += '<textarea id="' + fieldId + '" name="' + field.name + '" class="w-form-control" rows="3" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + maxlengthAttr + '></textarea>';
                    break;

                case 'select':
                    fieldHtml += '<select id="' + fieldId + '" name="' + field.name + '" class="w-form-control" ' + requiredAttr + ' ' + disabledAttr + '>';
                    fieldHtml += '<option value="">' + __('请选择') + '</option>';
                    if (field.options && field.options.length > 0) {
                        field.options.forEach(option => {
                            fieldHtml += '<option value="' + option.value + '">' + option.label + '</option>';
                        });
                    }
                    fieldHtml += '</select>';
                    break;

                case 'checkbox':
                    fieldHtml += '<div class="w-checkbox-group">';
                    if (field.options && field.options.length > 0) {
                        field.options.forEach(option => {
                            fieldHtml += '<label class="w-checkbox-item">';
                            fieldHtml += '<input type="checkbox" name="' + field.name + '[]" value="' + option.value + '" ' + requiredAttr + ' ' + disabledAttr + '>';
                            fieldHtml += '<span class="w-checkbox-label">' + option.label + '</span>';
                            fieldHtml += '</label>';
                        });
                    } else {
                        fieldHtml += '<label class="w-checkbox-item">';
                        fieldHtml += '<input type="checkbox" name="' + field.name + '" value="1" ' + requiredAttr + ' ' + disabledAttr + '>';
                        fieldHtml += '<span class="w-checkbox-label">' + (field.label || field.name) + '</span>';
                        fieldHtml += '</label>';
                    }
                    fieldHtml += '</div>';
                    break;

                case 'radio':
                    fieldHtml += '<div class="w-radio-group">';
                    if (field.options && field.options.length > 0) {
                        field.options.forEach(option => {
                            fieldHtml += '<label class="w-radio-item">';
                            fieldHtml += '<input type="radio" name="' + field.name + '" value="' + option.value + '" ' + requiredAttr + ' ' + disabledAttr + '>';
                            fieldHtml += '<span class="w-radio-label">' + option.label + '</span>';
                            fieldHtml += '</label>';
                        });
                    }
                    fieldHtml += '</div>';
                    break;

                case 'date':
                    fieldHtml += '<input type="date" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + '>';
                    break;

                case 'datetime':
                    fieldHtml += '<input type="datetime-local" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + '>';
                    break;

                case 'number':
                    const minAttr = field.min ? 'min="' + field.min + '"' : '';
                    const maxAttr = field.max ? 'max="' + field.max + '"' : '';
                    const stepAttr = field.step ? 'step="' + field.step + '"' : '';
                    fieldHtml += '<input type="number" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + minAttr + ' ' + maxAttr + ' ' + stepAttr + '>';
                    break;

                case 'email':
                    fieldHtml += '<input type="email" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + maxlengthAttr + '>';
                    break;

                case 'password':
                    fieldHtml += '<input type="password" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + maxlengthAttr + '>';
                    break;

                case 'file':
                    fieldHtml += this.generateFileField(fieldId, field, requiredAttr, disabledAttr);
                    break;

                case 'image':
                    fieldHtml += this.generateImageField(fieldId, field, requiredAttr, disabledAttr);
                    break;

                default: // text
                    fieldHtml += '<input type="text" id="' + fieldId + '" name="' + field.name + '" class="w-form-control" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + maxlengthAttr + '>';
                    break;
            }

            fieldHtml += '</div>';

            // 帮助文本
            if (field.help) {
                fieldHtml += '<div class="w-field-help">' + field.help + '</div>';
            }

            fieldHtml += '</div>';

            return fieldHtml;
        },

        /**
         * 隐藏加载字段提示
         */
        hideLoadingFields: function (formId) {
            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);
            if (autoFieldsContainer) {
                autoFieldsContainer.style.display = 'none';
            }
        },

        /**
         * 加载记录数据（编辑模式）
         */
        loadRecordData: function (formId, recordId) {
            const instance = this.instances[formId];
            if (!instance) return;

            const recordApiUrl = (typeof window !== 'undefined' && typeof window.api === 'function')
                ? window.api('datatable/rest/v1/form/record')
                : (typeof window !== 'undefined' && window.site && window.site.api_host)
                    ? (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') + 'datatable/rest/v1/form/record'
                    : '/api/rest/v1/datatable/form/record';
            fetch(recordApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    form_id: formId,
                    model: instance.options.model,
                    record_id: recordId
                })
            })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        this.fillFormData(formId, response.data);
                    } else {
                        this.showError(formId, response.message || __('加载记录失败'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('网络错误'));
                });
        },

        /**
         * 填充表单数据
         */
        fillFormData: function (formId, data) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 填充所有表单字段
            Object.keys(data).forEach(fieldName => {
                const field = form.querySelector('[name="' + fieldName + '"]');
                if (field) {
                    if (field.type === 'checkbox') {
                        if (Array.isArray(data[fieldName])) {
                            // 多选复选框
                            field.checked = data[fieldName].includes(field.value);
                        } else {
                            // 单选复选框
                            field.checked = data[fieldName] == field.value;
                        }
                    } else if (field.type === 'radio') {
                        field.checked = field.value == data[fieldName];
                    } else {
                        field.value = data[fieldName] || '';
                    }
                }
            });

            console.log('表单数据已填充:', data);
        },

        /**
         * 绑定表单事件
         */
        bindFormEvents: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 表单提交事件
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                DataTableFormManager.submitForm(formId);
            });

            // 字段验证事件
            form.addEventListener('blur', function (e) {
                if (e.target.matches('.w-form-control')) {
                    DataTableFormManager.validateField(e.target);
                }
            }, true);

            // 实时验证
            form.addEventListener('input', function (e) {
                if (e.target.matches('.w-form-control')) {
                    DataTableFormManager.validateField(e.target);
                }
            }, true);
        },

        /**
         * 验证字段
         */
        validateField: function (field) {
            const fieldContainer = field.closest('.w-form-field');
            if (!fieldContainer) return;

            // 清除之前的验证信息
            const validationElement = fieldContainer.querySelector('.w-field-validation');
            if (validationElement) {
                validationElement.innerHTML = '';
                validationElement.className = 'w-field-validation';
            }

            // 基本验证
            let isValid = true;
            let errorMessage = '';

            // 必填验证
            if (field.required && !field.value.trim()) {
                isValid = false;
                errorMessage = '此字段为必填项';
            }

            // 邮箱验证
            if (field.type === 'email' && field.value && !this.isValidEmail(field.value)) {
                isValid = false;
                errorMessage = '请输入有效的邮箱地址';
            }

            // 数字验证
            if (field.type === 'number' && field.value) {
                const numValue = parseFloat(field.value);
                if (isNaN(numValue)) {
                    isValid = false;
                    errorMessage = '请输入有效的数字';
                } else {
                    if (field.min && numValue < parseFloat(field.min)) {
                        isValid = false;
                        errorMessage = '数值不能小于 ' + field.min;
                    }
                    if (field.max && numValue > parseFloat(field.max)) {
                        isValid = false;
                        errorMessage = '数值不能大于 ' + field.max;
                    }
                }
            }

            // 显示验证结果
            if (!isValid && validationElement) {
                validationElement.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + errorMessage;
                validationElement.className = 'w-field-validation w-field-error';
                fieldContainer.classList.add('w-field-error');
            } else {
                fieldContainer.classList.remove('w-field-error');
            }

            return isValid;
        },

        /**
         * 验证邮箱格式
         */
        isValidEmail: function (email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        /**
         * 提交表单
         */
        submitForm: function (formId) {
            const instance = this.instances[formId];
            const form = document.getElementById(formId);
            if (!instance || !form) return;

            // 验证所有字段
            const fields = form.querySelectorAll('.w-form-control');
            let isValid = true;

            fields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                }
            });

            if (!isValid) {
                this.showError(formId, __('请检查表单中的错误'));
                return;
            }

            // 收集表单数据
            const formData = new FormData(form);
            const data = {};

            for (let [key, value] of formData.entries()) {
                if (data[key]) {
                    // 处理多选字段
                    if (Array.isArray(data[key])) {
                        data[key].push(value);
                    } else {
                        data[key] = [data[key], value];
                    }
                } else {
                    data[key] = value;
                }
            }

            // 显示提交状态
            this.showSubmitting(formId);

            // 确定API操作类型和URL
            const isEdit = instance.options.mode === 'edit' && instance.options.recordId;
            const operation = isEdit ? 'update' : 'create';
            
            // 生成正确的API URL
            let apiUrl = form.action;
            if (typeof window.api === 'function') {
                apiUrl = window.api('datatable/rest/v1/data-table/' + operation);
            } else if (window.site && window.site.api_host) {
                apiUrl = (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') 
                       + 'datatable/rest/v1/data-table/' + operation;
            } else if (!apiUrl.includes('/datatable/rest/')) {
                // 如果action不是REST API路径，使用默认路径
                apiUrl = '/datatable/rest/v1/data-table/' + operation;
            }

            // 构建请求数据
            const requestData = {
                model: instance.options.model,
                data: data
            };
            
            // 编辑模式添加ID
            if (isEdit) {
                requestData.id = instance.options.recordId;
            }
            
            // 发送请求
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestData)
            })
                .then(response => response.json())
                .then(response => {
                    // 兼容两种响应格式：{success, data, message} 和 {msg, data, code}
                    const isSuccess = response.success || response.code === 200 || response.code === '200';
                    const message = response.message || response.msg;
                    
                    if (isSuccess) {
                        this.showSuccess(formId, message || __('保存成功'));
                        // 关闭模态框
                        this.closeModal(formId);
                        // 触发成功回调
                        if (typeof window.onFormSuccess === 'function') {
                            window.onFormSuccess(formId, response.data);
                        }
                        // 刷新DataTable（如果存在）
                        if (typeof window.DataTableManager !== 'undefined') {
                            window.DataTableManager.refreshAllTables && window.DataTableManager.refreshAllTables();
                        }
                    } else {
                        this.showError(formId, message || __('保存失败'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('网络错误'));
                })
                .finally(() => {
                    this.hideSubmitting(formId);
                });
        },

        /**
         * 打开模态框
         */
        openModal: function (formId, mode, recordId) {
            const modal = document.getElementById('w-form-modal-' + formId);
            if (!modal) return;

            // 如果是编辑模式，加载记录数据
            if (mode === 'edit' && recordId) {
                const instance = this.instances[formId];
                if (instance) {
                    instance.options.mode = 'edit';
                    instance.options.recordId = recordId;
                    this.loadRecordData(formId, recordId);
                }
            }

            // 显示模态框
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';

            // 根据字段数量自动调整布局
            this.adjustModalLayout(formId, modal);

            // 聚焦到第一个输入框
            setTimeout(() => {
                const firstInput = modal.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            }, 100);
        },

        /**
         * 根据字段数量调整弹窗宽度（字段使用流式布局自动排列）
         */
        adjustModalLayout: function (formId, modal) {
            const container = modal.querySelector('.w-form-modal-container');
            const fieldsContainer = modal.querySelector('.w-form-fields');
            
            if (!container || !fieldsContainer) return;

            // 计算可见字段数量
            const fields = fieldsContainer.querySelectorAll('.w-form-field');
            const fieldCount = fields.length;

            // 重置弹窗宽度类
            container.classList.remove('w-form-modal-wide', 'w-form-modal-extra-wide');

            // 根据字段数量调整弹窗宽度，流式布局会自动排列字段
            if (fieldCount > 2) {
                container.classList.add('w-form-modal-wide');
            }
            if (fieldCount > 8) {
                container.classList.add('w-form-modal-extra-wide');
            }
        },

        /**
         * 关闭模态框
         */
        /**
         * 重置表单
         */
        resetForm: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 重置表单数据
            form.reset();

            // 清除验证错误
            const validationElements = form.querySelectorAll('.w-field-validation');
            validationElements.forEach(function (element) {
                element.innerHTML = '';
                element.className = 'w-field-validation';
            });

            // 清除字段错误状态
            const errorFields = form.querySelectorAll('.w-form-field.w-field-error');
            errorFields.forEach(function (field) {
                field.classList.remove('w-field-error');
            });

            // 清除表单消息
            const messages = form.querySelectorAll('.w-form-message');
            messages.forEach(function (message) {
                message.remove();
            });

            console.log('表单已重置:', formId);
        },

        closeModal: function (formId) {
            const modal = document.getElementById('w-form-modal-' + formId);
            if (!modal) return;

            // 隐藏模态框
            modal.classList.remove('show');
            document.body.style.overflow = '';

            // 重置表单
            const form = document.getElementById(formId);
            if (form) {
                form.reset();
            }

            // 触发取消回调
            if (typeof window.onFormCancel === 'function') {
                window.onFormCancel(formId);
            }
        },

        /**
         * 取消表单
         */
        cancelForm: function (formId) {
            this.closeModal(formId);
        },

        /**
         * 显示提交状态
         */
        showSubmitting: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const submitBtn = form.querySelector('.w-btn-primary');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + __('保存中...');
            }
        },

        /**
         * 隐藏提交状态
         */
        hideSubmitting: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const submitBtn = form.querySelector('.w-btn-primary');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> 保存';
            }
        },

        /**
         * 显示成功消息
         */
        showSuccess: function (formId, message) {
            this.showMessage(formId, message, 'success');
        },

        /**
         * 显示错误消息
         */
        showError: function (formId, message) {
            this.showMessage(formId, message, 'error');
        },

        /**
         * 显示消息
         */
        showMessage: function (formId, message, type) {
            const container = document.getElementById('w-form-container-' + formId);
            if (!container) return;

            // 移除之前的消息
            const existingMessage = container.querySelector('.w-form-message');
            if (existingMessage) {
                existingMessage.remove();
            }

            // 创建消息元素
            const messageElement = document.createElement('div');
            messageElement.className = 'w-form-message w-form-message-' + type;
            messageElement.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;

            // 插入到表单头部
            const header = container.querySelector('.w-form-header');
            if (header) {
                header.insertAdjacentElement('afterend', messageElement);
            }

            // 自动隐藏成功消息
            if (type === 'success') {
                setTimeout(() => {
                    if (messageElement.parentNode) {
                        messageElement.remove();
                    }
                }, 3000);
            }
        },

        /**
         * 为表格行添加编辑按钮
         */
        addEditButtons: function (tableId, formId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            // 查找操作列或添加操作列
            let actionColumn = table.querySelector('.w-table-actions');
            if (!actionColumn) {
                // 如果没有操作列，在表头添加
                const thead = table.querySelector('thead tr');
                if (thead) {
                    const th = document.createElement('th');
                    th.className = 'w-table-actions';
                    th.textContent = __('操作');
                    thead.appendChild(th);
                }

                // 为每一行添加操作列
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach(row => {
                        const td = document.createElement('td');
                        td.className = 'w-table-actions';
                        row.appendChild(td);
                    });
                }
            }

            // 为每一行添加编辑按钮
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const actionCell = row.querySelector('.w-table-actions');
                if (actionCell) {
                    // 检查是否已经有编辑按钮
                    if (!actionCell.querySelector('.w-edit-btn')) {
                        const editBtn = document.createElement('button');
                        editBtn.className = 'w-btn w-btn-sm w-btn-secondary w-edit-btn';
                        editBtn.innerHTML = '<i class="fas fa-edit"></i> ' + __('编辑');
                        editBtn.onclick = function () {
                            // 获取行数据ID
                            const rowId = row.getAttribute('data-id') || row.querySelector('[data-id]')?.getAttribute('data-id');
                            if (rowId) {
                                DataTableFormManager.openModal(formId, 'edit', rowId);
                            }
                        };
                        actionCell.appendChild(editBtn);
                    }
                }
            });
        },

        /**
         * 字段验证
         * @param {string} formId 表单ID
         * @param {string} fieldName 字段名
         * @param {*} value 字段值
         * @returns {boolean} 验证结果
         */
        validateField: function (formId, fieldName, value) {
            const instance = this.instances[formId];
            if (!instance) return true;

            const field = instance.fields.find(f => f.name === fieldName);
            if (!field) return true;

            const fieldElement = document.querySelector(`#${formId} [name="${fieldName}"]`);
            const feedbackElement = fieldElement ? fieldElement.parentElement.querySelector('.invalid-feedback') : null;

            // 清除之前的错误状态
            if (fieldElement) {
                fieldElement.classList.remove('is-invalid');
            }
            if (feedbackElement) {
                feedbackElement.textContent = '';
            }

            // 必填验证
            if (field.required && (!value || value.toString().trim() === '')) {
                this.showFieldError(fieldElement, feedbackElement, __('此字段为必填项'));
                return false;
            }

            // 类型验证
            if (value && value.toString().trim() !== '') {
                switch (field.type) {
                    case 'email':
                        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('请输入有效的邮箱地址'));
                            return false;
                        }
                        break;

                    case 'number':
                        if (isNaN(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('请输入有效的数字'));
                            return false;
                        }
                        break;

                    case 'tel':
                        if (!/^[\d\-\+\(\)\s]+$/.test(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('请输入有效的电话号码'));
                            return false;
                        }
                        break;
                }
            }

            return true;
        },

        /**
         * 显示字段错误
         * @param {Element} fieldElement 字段元素
         * @param {Element} feedbackElement 反馈元素
         * @param {string} message 错误消息
         */
        showFieldError: function (fieldElement, feedbackElement, message) {
            if (fieldElement) {
                fieldElement.classList.add('is-invalid');
            }
            if (feedbackElement) {
                feedbackElement.textContent = message;
            }
        },

        /**
         * 验证整个表单
         * @param {string} formId 表单ID
         * @returns {boolean} 验证结果
         */
        validateForm: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return false;

            const formData = new FormData(form);
            let isValid = true;

            // 验证所有字段
            for (let [name, value] of formData.entries()) {
                if (!this.validateField(formId, name, value)) {
                    isValid = false;
                }
            }

            return isValid;
        },

        // 初始化多表分组
        initMultiTableGroups: function () {
            const multiTableGroups = document.querySelectorAll('.multi-table-group');

            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                const modelClass = group.getAttribute('data-model-class');

                // 添加分组样式
                group.classList.add('table-group-container');

                // 为分组添加展开/折叠功能
                const legend = group.querySelector('legend');
                if (legend) {
                    legend.style.cursor = 'pointer';
                    legend.innerHTML = `<i class="fas fa-chevron-down me-2"></i>${legend.textContent}`;

                    legend.addEventListener('click', function () {
                        const content = Array.from(group.children).filter(child => child.tagName !== 'LEGEND');
                        const icon = legend.querySelector('i');

                        if (group.classList.contains('collapsed')) {
                            group.classList.remove('collapsed');
                            icon.className = 'fas fa-chevron-down me-2';
                            content.forEach(el => el.style.display = '');
                        } else {
                            group.classList.add('collapsed');
                            icon.className = 'fas fa-chevron-right me-2';
                            content.forEach(el => el.style.display = 'none');
                        }
                    });
                }

                // 为字段添加表别名前缀
                const fields = group.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    const fieldName = field.getAttribute('name');
                    if (fieldName && !fieldName.startsWith(tableAlias + '.')) {
                        field.setAttribute('data-original-name', fieldName);
                        field.setAttribute('data-table-alias', tableAlias);
                    }
                });
            });
        },

        // 收集多表表单数据
        collectMultiTableData: function (formElement) {
            const data = {};
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 单表表单，使用原有逻辑
                const formData = new FormData(formElement);
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                return data;
            }

            // 多表表单，按表别名分组收集数据
            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                if (!tableAlias) return;

                data[tableAlias] = {};

                const fields = group.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    const fieldName = field.getAttribute('data-original-name') || field.getAttribute('name');
                    if (fieldName) {
                        // 移除表别名前缀
                        const cleanFieldName = fieldName.replace(tableAlias + '.', '');
                        data[tableAlias][cleanFieldName] = this.getFieldValue(field);
                    }
                });
            });

            return data;
        },

        // 获取字段值
        getFieldValue: function (field) {
            if (field.type === 'checkbox') {
                return field.checked ? field.value : '';
            } else if (field.type === 'radio') {
                return field.checked ? field.value : '';
            } else {
                return field.value || '';
            }
        },

        // 设置字段值
        setFieldValue: function (field, value) {
            if (field.type === 'checkbox') {
                field.checked = value == field.value;
            } else if (field.type === 'radio') {
                field.checked = value == field.value;
            } else {
                field.value = value || '';
            }
        },

        // 填充多表表单数据
        populateMultiTableForm: function (formElement, data) {
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 单表表单，使用原有逻辑
                Object.keys(data).forEach(fieldName => {
                    const field = formElement.querySelector('[name="' + fieldName + '"]');
                    if (field) {
                        this.setFieldValue(field, data[fieldName]);
                    }
                });
                return;
            }

            // 多表表单，按表别名分组填充数据
            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                if (!tableAlias || !data[tableAlias]) return;

                const tableData = data[tableAlias];
                const fields = group.querySelectorAll('input, select, textarea');

                fields.forEach(field => {
                    const fieldName = field.getAttribute('data-original-name') || field.getAttribute('name');
                    if (fieldName) {
                        const cleanFieldName = fieldName.replace(tableAlias + '.', '');
                        if (tableData.hasOwnProperty(cleanFieldName)) {
                            this.setFieldValue(field, tableData[cleanFieldName]);
                        }
                    }
                });
            });
        },

        // 验证多表表单
        validateMultiTableForm: function (formElement) {
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 单表表单，使用原有逻辑
                return this.validateForm(formElement.id);
            }

            let isValid = true;
            const errors = {};

            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                if (!tableAlias) return;

                const fields = group.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    const fieldName = field.getAttribute('data-original-name') || field.getAttribute('name');
                    if (fieldName) {
                        const cleanFieldName = fieldName.replace(tableAlias + '.', '');
                        const fieldValid = this.validateField(formElement.id, cleanFieldName, this.getFieldValue(field));

                        if (!fieldValid) {
                            isValid = false;
                            if (!errors[tableAlias]) {
                                errors[tableAlias] = {};
                            }
                            errors[tableAlias][cleanFieldName] = '字段验证失败';
                        }
                    }
                });
            });

            return { isValid, errors };
        },

        /**
         * 生成文件字段HTML
         */
        generateFileField: function (fieldId, field, requiredAttr, disabledAttr) {
            const accept = field.accept || '*/*';
            const multiple = field.multiple ? 'multiple' : '';
            const maxSize = field.maxSize || '10MB';

            let fieldHtml = '<div class="w-file-field">';

            // 文件输入框
            fieldHtml += '<input type="file" id="' + fieldId + '" name="' + field.name + '" class="w-file-input" accept="' + accept + '" ' + multiple + ' ' + requiredAttr + ' ' + disabledAttr + ' style="display: none;">';

            // 文件选择按钮
            fieldHtml += '<div class="w-file-selector">';
            fieldHtml += '<button type="button" class="w-btn w-btn-outline-primary w-file-btn" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-upload"></i> 选择文件';
            fieldHtml += '</button>';
            fieldHtml += '<span class="w-file-info">支持格式：' + accept + '，最大：' + maxSize + '</span>';
            fieldHtml += '</div>';

            // 文件列表
            fieldHtml += '<div class="w-file-list" id="w-file-list-' + fieldId + '">';
            fieldHtml += '<div class="w-file-placeholder">未选择文件</div>';
            fieldHtml += '</div>';

            // 上传进度
            fieldHtml += '<div class="w-upload-progress" id="w-upload-progress-' + fieldId + '" style="display: none;">';
            fieldHtml += '<div class="w-progress-bar">';
            fieldHtml += '<div class="w-progress-fill" style="width: 0%"></div>';
            fieldHtml += '</div>';
            fieldHtml += '<div class="w-progress-text">0%</div>';
            fieldHtml += '</div>';

            fieldHtml += '</div>';

            // 绑定文件选择事件
            setTimeout(() => {
                this.bindFileEvents(fieldId, field);
            }, 100);

            return fieldHtml;
        },

        /**
         * 生成图片字段HTML
         */
        generateImageField: function (fieldId, field, requiredAttr, disabledAttr) {
            const accept = field.accept || 'image/*';
            const multiple = field.multiple ? 'multiple' : '';
            const maxSize = field.maxSize || '5MB';

            let fieldHtml = '<div class="w-image-field">';

            // 图片输入框
            fieldHtml += '<input type="file" id="' + fieldId + '" name="' + field.name + '" class="w-image-input" accept="' + accept + '" ' + multiple + ' ' + requiredAttr + ' ' + disabledAttr + ' style="display: none;">';

            // 图片预览区域
            fieldHtml += '<div class="w-image-preview" id="w-image-preview-' + fieldId + '">';
            fieldHtml += '<div class="w-image-placeholder" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-image"></i>';
            fieldHtml += '<div class="w-placeholder-text">点击选择图片</div>';
            fieldHtml += '<div class="w-placeholder-info">支持格式：JPG、PNG、GIF，最大：' + maxSize + '</div>';
            fieldHtml += '</div>';
            fieldHtml += '</div>';

            // 图片操作按钮
            fieldHtml += '<div class="w-image-actions" style="display: none;">';
            fieldHtml += '<button type="button" class="w-btn w-btn-sm w-btn-outline-primary" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-edit"></i> 更换';
            fieldHtml += '</button>';
            fieldHtml += '<button type="button" class="w-btn w-btn-sm w-btn-outline-danger" onclick="DataTableFormManager.clearImageField(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-trash"></i> 删除';
            fieldHtml += '</button>';
            fieldHtml += '</div>';

            // 上传进度
            fieldHtml += '<div class="w-upload-progress" id="w-upload-progress-' + fieldId + '" style="display: none;">';
            fieldHtml += '<div class="w-progress-bar">';
            fieldHtml += '<div class="w-progress-fill" style="width: 0%"></div>';
            fieldHtml += '</div>';
            fieldHtml += '<div class="w-progress-text">0%</div>';
            fieldHtml += '</div>';

            fieldHtml += '</div>';

            // 绑定图片选择事件
            setTimeout(() => {
                this.bindImageEvents(fieldId, field);
            }, 100);

            return fieldHtml;
        },

        /**
         * 触发文件选择
         */
        triggerFileSelect: function (fieldId) {
            const fileInput = document.getElementById(fieldId);
            if (fileInput) {
                fileInput.click();
            }
        },

        /**
         * 清空图片字段
         */
        clearImageField: function (fieldId) {
            const fileInput = document.getElementById(fieldId);
            const preview = document.getElementById('w-image-preview-' + fieldId);
            const actions = preview.parentElement.querySelector('.w-image-actions');

            if (fileInput) {
                fileInput.value = '';
            }

            if (preview) {
                preview.innerHTML = '<div class="w-image-placeholder" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">' +
                    '<i class="fas fa-image"></i>' +
                    '<div class="w-placeholder-text">点击选择图片</div>' +
                    '<div class="w-placeholder-info">支持格式：JPG、PNG、GIF，最大：5MB</div>' +
                    '</div>';
            }

            if (actions) {
                actions.style.display = 'none';
            }
        },

        /**
         * 绑定文件事件
         */
        bindFileEvents: function (fieldId, field) {
            const fileInput = document.getElementById(fieldId);
            const fileList = document.getElementById('w-file-list-' + fieldId);

            if (!fileInput || !fileList) return;

            fileInput.addEventListener('change', (e) => {
                const files = e.target.files;
                if (files.length === 0) {
                    fileList.innerHTML = '<div class="w-file-placeholder">未选择文件</div>';
                    return;
                }

                let listHtml = '';
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileSize = this.formatFileSize(file.size);
                    listHtml += '<div class="w-file-item">';
                    listHtml += '<i class="fas fa-file"></i>';
                    listHtml += '<span class="w-file-name">' + file.name + '</span>';
                    listHtml += '<span class="w-file-size">(' + fileSize + ')</span>';
                    listHtml += '<button type="button" class="w-file-remove" onclick="DataTableFormManager.removeFileItem(this, \'' + fieldId + '\')">';
                    listHtml += '<i class="fas fa-times"></i>';
                    listHtml += '</button>';
                    listHtml += '</div>';
                }

                fileList.innerHTML = listHtml;
            });
        },

        /**
         * 绑定图片事件
         */
        bindImageEvents: function (fieldId, field) {
            const imageInput = document.getElementById(fieldId);
            const preview = document.getElementById('w-image-preview-' + fieldId);
            const actions = preview.parentElement.querySelector('.w-image-actions');

            if (!imageInput || !preview) return;

            imageInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;

                // 验证文件类型
                if (!file.type.startsWith('image/')) {
                    // 使用 toast 提示替代 alert
                    const toastHtml = `<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 11000;">
                        <div class="toast show bg-danger text-white" role="alert">
                            <div class="toast-body d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${__('请选择图片文件')}
                                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="toast"></button>
                            </div>
                        </div>
                    </div>`;
                    const toastContainer = document.createElement('div');
                    toastContainer.innerHTML = toastHtml;
                    document.body.appendChild(toastContainer);
                    setTimeout(() => toastContainer.remove(), 3000);
                    imageInput.value = '';
                    return;
                }

                // 读取并显示图片预览
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="' + __('预览图片') + '" class="w-image-preview-img">';
                    if (actions) {
                        actions.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            });
        },

        /**
         * 移除文件项
         */
        removeFileItem: function (button, fieldId) {
            const fileInput = document.getElementById(fieldId);
            const fileList = document.getElementById('w-file-list-' + fieldId);

            // 移除DOM元素
            button.parentElement.remove();

            // 检查是否还有文件
            if (fileList.children.length === 0) {
                fileList.innerHTML = '<div class="w-file-placeholder">未选择文件</div>';
                if (fileInput) {
                    fileInput.value = '';
                }
            }
        },

        /**
         * 格式化文件大小
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        /**
         * 全局编辑记录方法
         * 可以从表格行或其他地方调用
         */
        editRecord: function (formId, recordId) {
            this.openModal(formId, 'edit', recordId);
        },

        /**
         * 全局添加记录方法
         */
        addRecord: function (formId) {
            this.openModal(formId, 'add');
        },

        /**
         * 为表格行添加编辑按钮
         */
        addEditButtonToTable: function (tableSelector, formId) {
            const table = document.querySelector(tableSelector);
            if (!table) return;

            // 查找所有数据行
            const rows = table.querySelectorAll('tbody tr[data-id]');
            rows.forEach(row => {
                const recordId = row.getAttribute('data-id');
                if (!recordId) return;

                // 检查是否已经有编辑按钮
                if (row.querySelector('.w-edit-btn')) return;

                // 查找操作列或创建操作列
                let actionCell = row.querySelector('.w-actions, .actions, td:last-child');
                if (!actionCell) {
                    actionCell = document.createElement('td');
                    actionCell.className = 'w-actions';
                    row.appendChild(actionCell);
                }

                // 创建编辑按钮
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'w-btn w-btn-sm w-btn-outline-primary w-edit-btn';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> 编辑';
                editBtn.onclick = function () {
                    DataTableFormManager.editRecord(formId, recordId);
                };

                actionCell.appendChild(editBtn);
            });
        },

        /**
         * 自动为页面上的所有表格添加编辑功能
         */
        autoAddEditButtons: function () {
            // 查找所有带有data-form-id属性的表格
            const tables = document.querySelectorAll('table[data-form-id]');
            tables.forEach(table => {
                const formId = table.getAttribute('data-form-id');
                if (formId) {
                    this.addEditButtonToTable('table[data-form-id="' + formId + '"]', formId);
                }
            });

            // 也可以通过约定查找表格和表单
            const forms = document.querySelectorAll('.w-form-modal');
            forms.forEach(modal => {
                const formId = modal.id.replace('w-form-modal-', '');
                const tableSelector = '[data-scope="' + formId.replace('-form', '') + '"]';
                this.addEditButtonToTable(tableSelector, formId);
            });
        }
    };

    // 将实例暴露到全局
    window.DataTableFormManager = DataTableFormManagerInstance;

    // 页面加载完成后初始化
    if (!DataTableFormManagerInstance._initialized) {
        DataTableFormManagerInstance._initialized = true;
        document.addEventListener('DOMContentLoaded', function () {
            console.log('DataTableFormManager 已加载');

            // 自动初始化页面上的所有表单
            const forms = document.querySelectorAll('.w-form-modal');
            forms.forEach(modal => {
                const formId = modal.id.replace('w-form-modal-', '');
                const form = modal.querySelector('form');
                if (form) {
                    const model = form.getAttribute('data-model');
                    const scope = form.getAttribute('data-scope');
                    const mode = form.getAttribute('data-mode') || 'add';
                    const recordId = form.getAttribute('data-record-id') || '';

                    if (model && scope) {
                        DataTableFormManager.initForm(formId, {
                            model: model,
                            scope: scope,
                            mode: mode,
                            recordId: recordId,
                            autoFields: true,
                            excludeFields: [],
                            includeFields: []
                        });
                    }
                }
            });

            // 自动为表格添加编辑按钮
            setTimeout(() => {
                DataTableFormManager.autoAddEditButtons();
            }, 500);

            // 监听表格内容变化，自动添加编辑按钮
            if (typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(function (mutations) {
                    let shouldUpdate = false;
                    mutations.forEach(function (mutation) {
                        if (mutation.type === 'childList') {
                            mutation.addedNodes.forEach(function (node) {
                                if (node.nodeType === 1) { // Element node
                                    if (node.tagName === 'TR' || node.querySelector('tr')) {
                                        shouldUpdate = true;
                                    }
                                }
                            });
                        }
                    });

                    if (shouldUpdate) {
                        setTimeout(() => {
                            DataTableFormManager.autoAddEditButtons();
                        }, 100);
                    }
                });

                // 观察表格容器的变化
                setTimeout(() => {
                    const tableContainers = document.querySelectorAll('.w-datatable, .datatable-container, table');
                    tableContainers.forEach(container => {
                        observer.observe(container, {
                            childList: true,
                            subtree: true
                        });
                    });
                }, 1000);
            }
        });
    }

    return window.DataTableFormManager;
})();