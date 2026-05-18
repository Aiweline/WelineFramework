/**
 * DataTable 琛ㄥ崟绠＄悊鍣?
 * 澶勭悊琛ㄥ崟鐨勫垵濮嬪寲銆佸瓧娈电敓鎴愩€佹彁浜ょ瓑鍔熻兘
 *
 * @version 2.0.0
 * @author Weline Framework
 * @description 澧炲己鐗堣〃鍗曠鐞嗗櫒锛屾敮鎸佸瓧娈甸獙璇併€佽嚜鍔ㄤ繚瀛樸€佹枃浠朵笂浼犵瓑鍔熻兘
 */

// 濡傛灉娌℃湁__鍑芥暟锛屽垯瀹氫箟涓€涓?
if (typeof __ === 'undefined') {
    function __(text) {
        return text;
    }
}

// DataTableFormManager 鍗曚緥妯″紡
(function () {
    'use strict';

    // 濡傛灉宸茬粡瀛樺湪瀹炰緥锛岀洿鎺ヨ繑鍥?
    if (window.DataTableFormManager && window.DataTableFormManager._instance) {
        return window.DataTableFormManager;
    }

    // 鍒涘缓鍗曚緥瀹炰緥
    const DataTableFormManagerInstance = {
        _instance: true, // 鏍囪涓哄崟渚嬪疄渚?
        _initialized: false,

        // 琛ㄥ崟瀹炰緥瀛樺偍
        instances: {},

        // 閰嶇疆閫夐」
        config: {
            apiUrl: '',
            fieldApiUrl: '',
            validateOnChange: true,
            autoSave: false,
            showValidationErrors: true,
            debounceDelay: 300
        },

        /**
         * 鍒濆鍖栬〃鍗?
         */
        initForm: function (formId, options) {
            console.log('鍒濆鍖栬〃鍗?', formId, options);

            // 瀛樺偍琛ㄥ崟瀹炰緥
            this.instances[formId] = {
                id: formId,
                options: options,
                fields: [],
                autoFields: [],
                manualFields: []
            };

            // 鎻愬彇鎵嬪姩璁剧疆鐨勫瓧娈?
            this.extractManualFields(formId);

            // 濡傛灉闇€瑕佽嚜鍔ㄧ敓鎴愬瓧娈?
            if (options.autoFields) {
                this.loadAutoFields(formId);
            } else {
                this.hideLoadingFields(formId);
            }

            // 濡傛灉鏄紪杈戞ā寮忥紝鍔犺浇璁板綍鏁版嵁
            if (options.mode === 'edit' && options.recordId) {
                this.loadRecordData(formId, options.recordId);
            }

            // 缁戝畾琛ㄥ崟浜嬩欢
            this.bindFormEvents(formId);
        },

        /**
         * 鎻愬彇鎵嬪姩璁剧疆鐨勫瓧娈?
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

            console.log('鎵嬪姩璁剧疆鐨勫瓧娈?', instance.manualFields);
        },

        /**
         * 鍔犺浇鑷姩鐢熸垚鐨勫瓧娈?
         */
        loadAutoFields: function (formId) {
            const instance = this.instances[formId];
            if (!instance) return;

            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);
            if (!autoFieldsContainer) return;

            // 鏋勫缓璇锋眰鍙傛暟
            const requestData = {
                form_id: formId,
                model: instance.options.model,
                scope: instance.options.scope,
                exclude_fields: instance.options.excludeFields,
                include_fields: instance.options.includeFields,
                manual_fields: instance.manualFields
            };

            // 鍙戦€佽姹傝幏鍙栧瓧娈典俊鎭?
            Promise.reject(new Error('DataTable form worker API is not initialized.'))
                .then(response => {
                    // 鍏煎涓ょ鍝嶅簲鏍煎紡锛歿success, data, message} 鍜?{msg, data, code}
                    const isSuccess = response.success || response.code === 200 || response.code === '200';
                    if (isSuccess) {
                        this.renderAutoFields(formId, response.data);
                    } else {
                        this.showError(formId, response.message || response.msg || __('鍔犺浇瀛楁澶辫触'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('缃戠粶閿欒'));
                });
        },

        /**
         * 娓叉煋鑷姩鐢熸垚鐨勫瓧娈?
         */
        renderAutoFields: function (formId, fieldsData) {
            const instance = this.instances[formId];
            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);

            if (!autoFieldsContainer) return;

            // 娓呯┖鍔犺浇鎻愮ず
            autoFieldsContainer.innerHTML = '';

            // 杩囨护瀛楁
            let fields = fieldsData.fields || [];

            // 鎺掗櫎鎵嬪姩璁剧疆鐨勫瓧娈?
            fields = fields.filter(field => !instance.manualFields.includes(field.name));

            // 鎺掗櫎鎸囧畾瀛楁
            if (instance.options.excludeFields && instance.options.excludeFields.length > 0) {
                fields = fields.filter(field => !instance.options.excludeFields.includes(field.name));
            }

            // 鍙寘鍚寚瀹氬瓧娈?
            if (instance.options.includeFields && instance.options.includeFields.length > 0) {
                fields = fields.filter(field => instance.options.includeFields.includes(field.name));
            }

            // 娓叉煋瀛楁
            fields.forEach(field => {
                const fieldHtml = this.generateFieldHtml(formId, field);
                autoFieldsContainer.insertAdjacentHTML('beforeend', fieldHtml);
            });

            // 瀛樺偍鑷姩瀛楁淇℃伅
            instance.autoFields = fields;

            // 瀛楁娓叉煋瀹屾垚鍚庤皟鏁村竷灞€
            const modal = document.getElementById('w-form-modal-' + formId);
            if (modal) {
                this.adjustModalLayout(formId, modal);
            }

            console.log('鑷姩鐢熸垚鐨勫瓧娈?', fields);
        },

        /**
         * 鐢熸垚瀛楁HTML
         */
        buildFieldDomId: function (formId, fieldName) {
            const normalize = function (value) {
                return String(value || '')
                    .trim()
                    .replace(/[^a-zA-Z0-9_-]+/g, '-')
                    .replace(/^-+|-+$/g, '');
            };

            const normalizedFieldName = normalize(fieldName);
            const normalizedFormId = normalize(formId);

            return normalizedFormId
                ? 'field-' + normalizedFormId + '-' + normalizedFieldName
                : 'field-' + normalizedFieldName;
        },

        generateFieldHtml: function (formId, field) {
            const fieldId = this.buildFieldDomId(formId, field.name);
            const requiredAttr = field.required ? 'required' : '';
            const readonlyAttr = field.readonly ? 'readonly' : '';
            const disabledAttr = field.disabled ? 'disabled' : '';
            const maxlengthAttr = field.maxlength > 0 ? 'maxlength="' + field.maxlength + '"' : '';
            const placeholder = field.placeholder || __('璇疯緭鍏?{1}', [field.label || field.name]);

            // 鏋勫缓瀛楁 CSS 绫?
            let fieldClasses = ['w-form-field', 'w-field-type-' + field.type];
            
            // 鍗犳弧鏁磋鐨勫瓧娈电被鍨?
            const fullWidthTypes = ['textarea', 'file', 'image'];
            if (fullWidthTypes.includes(field.type)) {
                fieldClasses.push('w-field-full-width');
            }

            let fieldHtml = '<div class="' + fieldClasses.join(' ') + '" data-type="' + field.type + '" data-field="' + field.name + '">';

            // 瀛楁鏍囩
            if (field.label) {
                const requiredMark = field.required ? '<span class="w-required-mark">*</span>' : '';
                fieldHtml += '<label for="' + fieldId + '" class="w-field-label">' + field.label + requiredMark + '</label>';
            }

            // 瀛楁鎺т欢
            fieldHtml += '<div class="w-field-control">';

            switch (field.type) {
                case 'textarea':
                    fieldHtml += '<textarea id="' + fieldId + '" name="' + field.name + '" class="w-form-control" rows="3" placeholder="' + placeholder + '" ' + requiredAttr + ' ' + readonlyAttr + ' ' + disabledAttr + ' ' + maxlengthAttr + '></textarea>';
                    break;

                case 'select':
                    fieldHtml += '<select id="' + fieldId + '" name="' + field.name + '" class="w-form-control" ' + requiredAttr + ' ' + disabledAttr + '>';
                    fieldHtml += '<option value="">' + __('璇烽€夋嫨') + '</option>';
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

            // 甯姪鏂囨湰
            if (field.help) {
                fieldHtml += '<div class="w-field-help">' + field.help + '</div>';
            }

            fieldHtml += '</div>';

            return fieldHtml;
        },

        /**
         * 闅愯棌鍔犺浇瀛楁鎻愮ず
         */
        hideLoadingFields: function (formId) {
            const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);
            if (autoFieldsContainer) {
                autoFieldsContainer.style.display = 'none';
            }
        },

        /**
         * 鍔犺浇璁板綍鏁版嵁锛堢紪杈戞ā寮忥級
         */
        loadRecordData: function (formId, recordId) {
            const instance = this.instances[formId];
            if (!instance) return;

            Promise.reject(new Error('DataTable form worker API is not initialized.'))
                .then(response => {
                    if (response.success) {
                        this.fillFormData(formId, response.data);
                    } else {
                        this.showError(formId, response.message || __('鍔犺浇璁板綍澶辫触'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('缃戠粶閿欒'));
                });
        },

        /**
         * 濉厖琛ㄥ崟鏁版嵁
         */
        fillFormData: function (formId, data) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 濉厖鎵€鏈夎〃鍗曞瓧娈?
            Object.keys(data).forEach(fieldName => {
                const field = form.querySelector('[name="' + fieldName + '"]');
                if (field) {
                    if (field.type === 'checkbox') {
                        if (Array.isArray(data[fieldName])) {
                            // 澶氶€夊閫夋
                            field.checked = data[fieldName].includes(field.value);
                        } else {
                            // 鍗曢€夊閫夋
                            field.checked = data[fieldName] == field.value;
                        }
                    } else if (field.type === 'radio') {
                        field.checked = field.value == data[fieldName];
                    } else {
                        field.value = data[fieldName] || '';
                    }
                }
            });

            console.log('琛ㄥ崟鏁版嵁宸插～鍏?', data);
        },

        /**
         * 缁戝畾琛ㄥ崟浜嬩欢
         */
        bindFormEvents: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 琛ㄥ崟鎻愪氦浜嬩欢
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                DataTableFormManager.submitForm(formId);
            });

            // 瀛楁楠岃瘉浜嬩欢
            form.addEventListener('blur', function (e) {
                if (e.target.matches('.w-form-control')) {
                    DataTableFormManager.validateField(e.target);
                }
            }, true);

            // 瀹炴椂楠岃瘉
            form.addEventListener('input', function (e) {
                if (e.target.matches('.w-form-control')) {
                    DataTableFormManager.validateField(e.target);
                }
            }, true);
        },

        /**
         * 楠岃瘉瀛楁
         */
        validateField: function (field) {
            const fieldContainer = field.closest('.w-form-field');
            if (!fieldContainer) return;

            // 娓呴櫎涔嬪墠鐨勯獙璇佷俊鎭?
            const validationElement = fieldContainer.querySelector('.w-field-validation');
            if (validationElement) {
                validationElement.innerHTML = '';
                validationElement.className = 'w-field-validation';
            }

            // 鍩烘湰楠岃瘉
            let isValid = true;
            let errorMessage = '';

            // 蹇呭～楠岃瘉
            if (field.required && !field.value.trim()) {
                isValid = false;
                errorMessage = 'This field is required.';
            }

            // 閭楠岃瘉
            if (field.type === 'email' && field.value && !this.isValidEmail(field.value)) {
                isValid = false;
                errorMessage = '璇疯緭鍏ユ湁鏁堢殑閭鍦板潃';
            }

            // 鏁板瓧楠岃瘉
            if (field.type === 'number' && field.value) {
                const numValue = parseFloat(field.value);
                if (isNaN(numValue)) {
                    isValid = false;
                    errorMessage = '璇疯緭鍏ユ湁鏁堢殑鏁板瓧';
                } else {
                    if (field.min && numValue < parseFloat(field.min)) {
                        isValid = false;
                        errorMessage = '鏁板€间笉鑳藉皬浜?' + field.min;
                    }
                    if (field.max && numValue > parseFloat(field.max)) {
                        isValid = false;
                        errorMessage = '鏁板€间笉鑳藉ぇ浜?' + field.max;
                    }
                }
            }

            // 鏄剧ず楠岃瘉缁撴灉
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
         * 楠岃瘉閭鏍煎紡
         */
        isValidEmail: function (email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        /**
         * 鎻愪氦琛ㄥ崟
         */
        submitForm: function (formId) {
            const instance = this.instances[formId];
            const form = document.getElementById(formId);
            if (!instance || !form) return;

            // 楠岃瘉鎵€鏈夊瓧娈?
            const fields = form.querySelectorAll('.w-form-control');
            let isValid = true;

            fields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                }
            });

            if (!isValid) {
                this.showError(formId, __('璇锋鏌ヨ〃鍗曚腑鐨勯敊璇?'));
                return;
            }

            // 鏀堕泦琛ㄥ崟鏁版嵁
            const formData = new FormData(form);
            const data = {};

            for (let [key, value] of formData.entries()) {
                if (data[key]) {
                    // 澶勭悊澶氶€夊瓧娈?
                    if (Array.isArray(data[key])) {
                        data[key].push(value);
                    } else {
                        data[key] = [data[key], value];
                    }
                } else {
                    data[key] = value;
                }
            }

            // 鏄剧ず鎻愪氦鐘舵€?
            this.showSubmitting(formId);

            // 纭畾API鎿嶄綔绫诲瀷鍜孶RL
            const isEdit = instance.options.mode === 'edit' && instance.options.recordId;
            const operation = isEdit ? 'update' : 'create';
            
            Promise.reject(new Error('DataTable form worker API is not initialized.'))
                .then(response => {
                    // 鍏煎涓ょ鍝嶅簲鏍煎紡锛歿success, data, message} 鍜?{msg, data, code}
                    const isSuccess = response.success || response.code === 200 || response.code === '200';
                    const message = response.message || response.msg;
                    
                    if (isSuccess) {
                        this.showSuccess(formId, message || __('淇濆瓨鎴愬姛'));
                        // 鍏抽棴妯℃€佹
                        this.closeModal(formId);
                        // 瑙﹀彂鎴愬姛鍥炶皟
                        if (typeof window.onFormSuccess === 'function') {
                            window.onFormSuccess(formId, response.data);
                        }
                        // 鍒锋柊DataTable锛堝鏋滃瓨鍦級
                        if (typeof window.DataTableManager !== 'undefined') {
                            window.DataTableManager.refreshAllTables && window.DataTableManager.refreshAllTables();
                        }
                    } else {
                        this.showError(formId, message || __('淇濆瓨澶辫触'));
                    }
                })
                .catch(error => {
                    this.showError(formId, error.message || __('缃戠粶閿欒'));
                })
                .finally(() => {
                    this.hideSubmitting(formId);
                });
        },

        /**
         * 鎵撳紑妯℃€佹
         */
        openModal: function (formId, mode, recordId) {
            const modal = document.getElementById('w-form-modal-' + formId);
            if (!modal) return;

            // 濡傛灉鏄紪杈戞ā寮忥紝鍔犺浇璁板綍鏁版嵁
            if (mode === 'edit' && recordId) {
                const instance = this.instances[formId];
                if (instance) {
                    instance.options.mode = 'edit';
                    instance.options.recordId = recordId;
                    this.loadRecordData(formId, recordId);
                }
            }

            // 鏄剧ず妯℃€佹
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';

            // 鏍规嵁瀛楁鏁伴噺鑷姩璋冩暣甯冨眬
            this.adjustModalLayout(formId, modal);

            // 鑱氱劍鍒扮涓€涓緭鍏ユ
            setTimeout(() => {
                const firstInput = modal.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            }, 100);
        },

        /**
         * 鏍规嵁瀛楁鏁伴噺璋冩暣寮圭獥瀹藉害锛堝瓧娈典娇鐢ㄦ祦寮忓竷灞€鑷姩鎺掑垪锛?
         */
        adjustModalLayout: function (formId, modal) {
            const container = modal.querySelector('.w-form-modal-container');
            const fieldsContainer = modal.querySelector('.w-form-fields');
            
            if (!container || !fieldsContainer) return;

            // 璁＄畻鍙瀛楁鏁伴噺
            const fields = fieldsContainer.querySelectorAll('.w-form-field');
            const fieldCount = fields.length;

            // 閲嶇疆寮圭獥瀹藉害绫?
            container.classList.remove('w-form-modal-wide', 'w-form-modal-extra-wide');

            // 鏍规嵁瀛楁鏁伴噺璋冩暣寮圭獥瀹藉害锛屾祦寮忓竷灞€浼氳嚜鍔ㄦ帓鍒楀瓧娈?
            if (fieldCount > 2) {
                container.classList.add('w-form-modal-wide');
            }
            if (fieldCount > 8) {
                container.classList.add('w-form-modal-extra-wide');
            }
        },

        /**
         * 鍏抽棴妯℃€佹
         */
        /**
         * 閲嶇疆琛ㄥ崟
         */
        resetForm: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            // 閲嶇疆琛ㄥ崟鏁版嵁
            form.reset();

            // 娓呴櫎楠岃瘉閿欒
            const validationElements = form.querySelectorAll('.w-field-validation');
            validationElements.forEach(function (element) {
                element.innerHTML = '';
                element.className = 'w-field-validation';
            });

            // 娓呴櫎瀛楁閿欒鐘舵€?
            const errorFields = form.querySelectorAll('.w-form-field.w-field-error');
            errorFields.forEach(function (field) {
                field.classList.remove('w-field-error');
            });

            // 娓呴櫎琛ㄥ崟娑堟伅
            const messages = form.querySelectorAll('.w-form-message');
            messages.forEach(function (message) {
                message.remove();
            });

            console.log('琛ㄥ崟宸查噸缃?', formId);
        },

        closeModal: function (formId) {
            const modal = document.getElementById('w-form-modal-' + formId);
            if (!modal) return;

            // 闅愯棌妯℃€佹
            modal.classList.remove('show');
            document.body.style.overflow = '';

            // 閲嶇疆琛ㄥ崟
            const form = document.getElementById(formId);
            if (form) {
                form.reset();
            }

            // 瑙﹀彂鍙栨秷鍥炶皟
            if (typeof window.onFormCancel === 'function') {
                window.onFormCancel(formId);
            }
        },

        /**
         * 鍙栨秷琛ㄥ崟
         */
        cancelForm: function (formId) {
            this.closeModal(formId);
        },

        /**
         * 鏄剧ず鎻愪氦鐘舵€?
         */
        showSubmitting: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const submitBtn = form.querySelector('.w-btn-primary');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + __('淇濆瓨涓?..');
            }
        },

        /**
         * 闅愯棌鎻愪氦鐘舵€?
         */
        hideSubmitting: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const submitBtn = form.querySelector('.w-btn-primary');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> 淇濆瓨';
            }
        },

        /**
         * 鏄剧ず鎴愬姛娑堟伅
         */
        showSuccess: function (formId, message) {
            this.showMessage(formId, message, 'success');
        },

        /**
         * 鏄剧ず閿欒娑堟伅
         */
        showError: function (formId, message) {
            this.showMessage(formId, message, 'error');
        },

        /**
         * 鏄剧ず娑堟伅
         */
        showMessage: function (formId, message, type) {
            const container = document.getElementById('w-form-container-' + formId);
            if (!container) return;

            // 绉婚櫎涔嬪墠鐨勬秷鎭?
            const existingMessage = container.querySelector('.w-form-message');
            if (existingMessage) {
                existingMessage.remove();
            }

            // 鍒涘缓娑堟伅鍏冪礌
            const messageElement = document.createElement('div');
            messageElement.className = 'w-form-message w-form-message-' + type;
            messageElement.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;

            // 鎻掑叆鍒拌〃鍗曞ご閮?
            const header = container.querySelector('.w-form-header');
            if (header) {
                header.insertAdjacentElement('afterend', messageElement);
            }

            // 鑷姩闅愯棌鎴愬姛娑堟伅
            if (type === 'success') {
                setTimeout(() => {
                    if (messageElement.parentNode) {
                        messageElement.remove();
                    }
                }, 3000);
            }
        },

        /**
         * 涓鸿〃鏍艰娣诲姞缂栬緫鎸夐挳
         */
        addEditButtons: function (tableId, formId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            // 鏌ユ壘鎿嶄綔鍒楁垨娣诲姞鎿嶄綔鍒?
            let actionColumn = table.querySelector('.w-table-actions');
            if (!actionColumn) {
                // 濡傛灉娌℃湁鎿嶄綔鍒楋紝鍦ㄨ〃澶存坊鍔?
                const thead = table.querySelector('thead tr');
                if (thead) {
                    const th = document.createElement('th');
                    th.className = 'w-table-actions';
                    th.textContent = __('鎿嶄綔');
                    thead.appendChild(th);
                }

                // 涓烘瘡涓€琛屾坊鍔犳搷浣滃垪
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

            // 涓烘瘡涓€琛屾坊鍔犵紪杈戞寜閽?
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const actionCell = row.querySelector('.w-table-actions');
                if (actionCell) {
                    // 妫€鏌ユ槸鍚﹀凡缁忔湁缂栬緫鎸夐挳
                    if (!actionCell.querySelector('.w-edit-btn')) {
                        const editBtn = document.createElement('button');
                        editBtn.className = 'w-btn w-btn-sm w-btn-secondary w-edit-btn';
                        editBtn.innerHTML = '<i class="fas fa-edit"></i> ' + __('缂栬緫');
                        editBtn.onclick = function () {
                            // 鑾峰彇琛屾暟鎹甀D
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
         * 瀛楁楠岃瘉
         * @param {string} formId 琛ㄥ崟ID
         * @param {string} fieldName 瀛楁鍚?
         * @param {*} value 瀛楁鍊?
         * @returns {boolean} 楠岃瘉缁撴灉
         */
        validateField: function (formId, fieldName, value) {
            const instance = this.instances[formId];
            if (!instance) return true;

            const field = instance.fields.find(f => f.name === fieldName);
            if (!field) return true;

            const fieldElement = document.querySelector(`#${formId} [name="${fieldName}"]`);
            const feedbackElement = fieldElement ? fieldElement.parentElement.querySelector('.invalid-feedback') : null;

            // 娓呴櫎涔嬪墠鐨勯敊璇姸鎬?
            if (fieldElement) {
                fieldElement.classList.remove('is-invalid');
            }
            if (feedbackElement) {
                feedbackElement.textContent = '';
            }

            // 蹇呭～楠岃瘉
            if (field.required && (!value || value.toString().trim() === '')) {
                this.showFieldError(fieldElement, feedbackElement, __('姝ゅ瓧娈典负蹇呭～椤?'));
                return false;
            }

            // 绫诲瀷楠岃瘉
            if (value && value.toString().trim() !== '') {
                switch (field.type) {
                    case 'email':
                        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('璇疯緭鍏ユ湁鏁堢殑閭鍦板潃'));
                            return false;
                        }
                        break;

                    case 'number':
                        if (isNaN(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('璇疯緭鍏ユ湁鏁堢殑鏁板瓧'));
                            return false;
                        }
                        break;

                    case 'tel':
                        if (!/^[\d\-\+\(\)\s]+$/.test(value)) {
                            this.showFieldError(fieldElement, feedbackElement, __('璇疯緭鍏ユ湁鏁堢殑鐢佃瘽鍙风爜'));
                            return false;
                        }
                        break;
                }
            }

            return true;
        },

        /**
         * 鏄剧ず瀛楁閿欒
         * @param {Element} fieldElement 瀛楁鍏冪礌
         * @param {Element} feedbackElement 鍙嶉鍏冪礌
         * @param {string} message 閿欒娑堟伅
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
         * 楠岃瘉鏁翠釜琛ㄥ崟
         * @param {string} formId 琛ㄥ崟ID
         * @returns {boolean} 楠岃瘉缁撴灉
         */
        validateForm: function (formId) {
            const form = document.getElementById(formId);
            if (!form) return false;

            const formData = new FormData(form);
            let isValid = true;

            // 楠岃瘉鎵€鏈夊瓧娈?
            for (let [name, value] of formData.entries()) {
                if (!this.validateField(formId, name, value)) {
                    isValid = false;
                }
            }

            return isValid;
        },

        // 鍒濆鍖栧琛ㄥ垎缁?
        initMultiTableGroups: function () {
            const multiTableGroups = document.querySelectorAll('.multi-table-group');

            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                const modelClass = group.getAttribute('data-model-class');

                // 娣诲姞鍒嗙粍鏍峰紡
                group.classList.add('table-group-container');

                // 涓哄垎缁勬坊鍔犲睍寮€/鎶樺彔鍔熻兘
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

                // 涓哄瓧娈垫坊鍔犺〃鍒悕鍓嶇紑
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

        // 鏀堕泦澶氳〃琛ㄥ崟鏁版嵁
        collectMultiTableData: function (formElement) {
            const data = {};
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 鍗曡〃琛ㄥ崟锛屼娇鐢ㄥ師鏈夐€昏緫
                const formData = new FormData(formElement);
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                return data;
            }

            // 澶氳〃琛ㄥ崟锛屾寜琛ㄥ埆鍚嶅垎缁勬敹闆嗘暟鎹?
            multiTableGroups.forEach(group => {
                const tableAlias = group.getAttribute('data-table-alias');
                if (!tableAlias) return;

                data[tableAlias] = {};

                const fields = group.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    const fieldName = field.getAttribute('data-original-name') || field.getAttribute('name');
                    if (fieldName) {
                        // 绉婚櫎琛ㄥ埆鍚嶅墠缂€
                        const cleanFieldName = fieldName.replace(tableAlias + '.', '');
                        data[tableAlias][cleanFieldName] = this.getFieldValue(field);
                    }
                });
            });

            return data;
        },

        // 鑾峰彇瀛楁鍊?
        getFieldValue: function (field) {
            if (field.type === 'checkbox') {
                return field.checked ? field.value : '';
            } else if (field.type === 'radio') {
                return field.checked ? field.value : '';
            } else {
                return field.value || '';
            }
        },

        // 璁剧疆瀛楁鍊?
        setFieldValue: function (field, value) {
            if (field.type === 'checkbox') {
                field.checked = value == field.value;
            } else if (field.type === 'radio') {
                field.checked = value == field.value;
            } else {
                field.value = value || '';
            }
        },

        // 濉厖澶氳〃琛ㄥ崟鏁版嵁
        populateMultiTableForm: function (formElement, data) {
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 鍗曡〃琛ㄥ崟锛屼娇鐢ㄥ師鏈夐€昏緫
                Object.keys(data).forEach(fieldName => {
                    const field = formElement.querySelector('[name="' + fieldName + '"]');
                    if (field) {
                        this.setFieldValue(field, data[fieldName]);
                    }
                });
                return;
            }

            // 澶氳〃琛ㄥ崟锛屾寜琛ㄥ埆鍚嶅垎缁勫～鍏呮暟鎹?
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

        // 楠岃瘉澶氳〃琛ㄥ崟
        validateMultiTableForm: function (formElement) {
            const multiTableGroups = formElement.querySelectorAll('.multi-table-group');

            if (multiTableGroups.length === 0) {
                // 鍗曡〃琛ㄥ崟锛屼娇鐢ㄥ師鏈夐€昏緫
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
                            errors[tableAlias][cleanFieldName] = '瀛楁楠岃瘉澶辫触';
                        }
                    }
                });
            });

            return { isValid, errors };
        },

        /**
         * 鐢熸垚鏂囦欢瀛楁HTML
         */
        generateFileField: function (fieldId, field, requiredAttr, disabledAttr) {
            const accept = field.accept || '*/*';
            const multiple = field.multiple ? 'multiple' : '';
            const maxSize = field.maxSize || '10MB';

            let fieldHtml = '<div class="w-file-field">';

            // 鏂囦欢杈撳叆妗?
            fieldHtml += '<input type="file" id="' + fieldId + '" name="' + field.name + '" class="w-file-input" accept="' + accept + '" ' + multiple + ' ' + requiredAttr + ' ' + disabledAttr + ' style="display: none;">';

            // 鏂囦欢閫夋嫨鎸夐挳
            fieldHtml += '<div class="w-file-selector">';
            fieldHtml += '<button type="button" class="w-btn w-btn-outline-primary w-file-btn" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-upload"></i> 閫夋嫨鏂囦欢';
            fieldHtml += '</button>';
            fieldHtml += '<span class="w-file-info">鏀寔鏍煎紡锛? ' + accept + '锛屾渶澶э細' + maxSize + '</span>';
            fieldHtml += '</div>';

            // 鏂囦欢鍒楄〃
            fieldHtml += '<div class="w-file-list" id="w-file-list-' + fieldId + '">';
            fieldHtml += '<div class="w-file-placeholder">鏈€夋嫨鏂囦欢</div>';
            fieldHtml += '</div>';

            // 涓婁紶杩涘害
            fieldHtml += '<div class="w-upload-progress" id="w-upload-progress-' + fieldId + '" style="display: none;">';
            fieldHtml += '<div class="w-progress-bar">';
            fieldHtml += '<div class="w-progress-fill" style="width: 0%"></div>';
            fieldHtml += '</div>';
            fieldHtml += '<div class="w-progress-text">0%</div>';
            fieldHtml += '</div>';

            fieldHtml += '</div>';

            // 缁戝畾鏂囦欢閫夋嫨浜嬩欢
            setTimeout(() => {
                this.bindFileEvents(fieldId, field);
            }, 100);

            return fieldHtml;
        },

        /**
         * 鐢熸垚鍥剧墖瀛楁HTML
         */
        generateImageField: function (fieldId, field, requiredAttr, disabledAttr) {
            const accept = field.accept || 'image/*';
            const multiple = field.multiple ? 'multiple' : '';
            const maxSize = field.maxSize || '5MB';

            let fieldHtml = '<div class="w-image-field">';

            // 鍥剧墖杈撳叆妗?
            fieldHtml += '<input type="file" id="' + fieldId + '" name="' + field.name + '" class="w-image-input" accept="' + accept + '" ' + multiple + ' ' + requiredAttr + ' ' + disabledAttr + ' style="display: none;">';

            // 鍥剧墖棰勮鍖哄煙
            fieldHtml += '<div class="w-image-preview" id="w-image-preview-' + fieldId + '">';
            fieldHtml += '<div class="w-image-placeholder" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-image"></i>';
            fieldHtml += '<div class="w-placeholder-text">鐐瑰嚮閫夋嫨鍥剧墖</div>';
            fieldHtml += '<div class="w-placeholder-info">鏀寔鏍煎紡锛欽PG銆丳NG銆丟IF锛屾渶澶э細' + maxSize + '</div>';
            fieldHtml += '</div>';
            fieldHtml += '</div>';

            // 鍥剧墖鎿嶄綔鎸夐挳
            fieldHtml += '<div class="w-image-actions" style="display: none;">';
            fieldHtml += '<button type="button" class="w-btn w-btn-sm w-btn-outline-primary" onclick="DataTableFormManager.triggerFileSelect(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-edit"></i> 鏇存崲';
            fieldHtml += '</button>';
            fieldHtml += '<button type="button" class="w-btn w-btn-sm w-btn-outline-danger" onclick="DataTableFormManager.clearImageField(\'' + fieldId + '\')">';
            fieldHtml += '<i class="fas fa-trash"></i> 鍒犻櫎';
            fieldHtml += '</button>';
            fieldHtml += '</div>';

            // 涓婁紶杩涘害
            fieldHtml += '<div class="w-upload-progress" id="w-upload-progress-' + fieldId + '" style="display: none;">';
            fieldHtml += '<div class="w-progress-bar">';
            fieldHtml += '<div class="w-progress-fill" style="width: 0%"></div>';
            fieldHtml += '</div>';
            fieldHtml += '<div class="w-progress-text">0%</div>';
            fieldHtml += '</div>';

            fieldHtml += '</div>';

            // 缁戝畾鍥剧墖閫夋嫨浜嬩欢
            setTimeout(() => {
                this.bindImageEvents(fieldId, field);
            }, 100);

            return fieldHtml;
        },

        /**
         * 瑙﹀彂鏂囦欢閫夋嫨
         */
        triggerFileSelect: function (fieldId) {
            const fileInput = document.getElementById(fieldId);
            if (fileInput) {
                fileInput.click();
            }
        },

        /**
         * 娓呯┖鍥剧墖瀛楁
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
                    '<div class="w-placeholder-text">鐐瑰嚮閫夋嫨鍥剧墖</div>' +
                    '<div class="w-placeholder-info">鏀寔鏍煎紡锛欽PG銆丳NG銆丟IF锛屾渶澶э細5MB</div>' +
                    '</div>';
            }

            if (actions) {
                actions.style.display = 'none';
            }
        },

        /**
         * 缁戝畾鏂囦欢浜嬩欢
         */
        bindFileEvents: function (fieldId, field) {
            const fileInput = document.getElementById(fieldId);
            const fileList = document.getElementById('w-file-list-' + fieldId);

            if (!fileInput || !fileList) return;

            fileInput.addEventListener('change', (e) => {
                const files = e.target.files;
                if (files.length === 0) {
                    fileList.innerHTML = '<div class="w-file-placeholder">鏈€夋嫨鏂囦欢</div>';
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
         * 缁戝畾鍥剧墖浜嬩欢
         */
        bindImageEvents: function (fieldId, field) {
            const imageInput = document.getElementById(fieldId);
            const preview = document.getElementById('w-image-preview-' + fieldId);
            const actions = preview.parentElement.querySelector('.w-image-actions');

            if (!imageInput || !preview) return;

            imageInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;

                // 楠岃瘉鏂囦欢绫诲瀷
                if (!file.type.startsWith('image/')) {
                    // 浣跨敤 toast 鎻愮ず鏇夸唬 alert
                    const toastHtml = `<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 11000;">
                        <div class="toast show bg-danger text-white" role="alert">
                            <div class="toast-body d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${__('璇烽€夋嫨鍥剧墖鏂囦欢')}
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

                // 璇诲彇骞舵樉绀哄浘鐗囬瑙?
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="' + __('棰勮鍥剧墖') + '" class="w-image-preview-img">';
                    if (actions) {
                        actions.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            });
        },

        /**
         * 绉婚櫎鏂囦欢椤?
         */
        removeFileItem: function (button, fieldId) {
            const fileInput = document.getElementById(fieldId);
            const fileList = document.getElementById('w-file-list-' + fieldId);

            // 绉婚櫎DOM鍏冪礌
            button.parentElement.remove();

            // 妫€鏌ユ槸鍚﹁繕鏈夋枃浠?
            if (fileList.children.length === 0) {
                fileList.innerHTML = '<div class="w-file-placeholder">鏈€夋嫨鏂囦欢</div>';
                if (fileInput) {
                    fileInput.value = '';
                }
            }
        },

        /**
         * 鏍煎紡鍖栨枃浠跺ぇ灏?
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        /**
         * 鍏ㄥ眬缂栬緫璁板綍鏂规硶
         * 鍙互浠庤〃鏍艰鎴栧叾浠栧湴鏂硅皟鐢?
         */
        editRecord: function (formId, recordId) {
            this.openModal(formId, 'edit', recordId);
        },

        /**
         * 鍏ㄥ眬娣诲姞璁板綍鏂规硶
         */
        addRecord: function (formId) {
            this.openModal(formId, 'add');
        },

        /**
         * 涓鸿〃鏍艰娣诲姞缂栬緫鎸夐挳
         */
        addEditButtonToTable: function (tableSelector, formId) {
            const table = document.querySelector(tableSelector);
            if (!table) return;

            // 鏌ユ壘鎵€鏈夋暟鎹
            const rows = table.querySelectorAll('tbody tr[data-id]');
            rows.forEach(row => {
                const recordId = row.getAttribute('data-id');
                if (!recordId) return;

                // 妫€鏌ユ槸鍚﹀凡缁忔湁缂栬緫鎸夐挳
                if (row.querySelector('.w-edit-btn')) return;

                // 鏌ユ壘鎿嶄綔鍒楁垨鍒涘缓鎿嶄綔鍒?
                let actionCell = row.querySelector('.w-actions, .actions, td:last-child');
                if (!actionCell) {
                    actionCell = document.createElement('td');
                    actionCell.className = 'w-actions';
                    row.appendChild(actionCell);
                }

                // 鍒涘缓缂栬緫鎸夐挳
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'w-btn w-btn-sm w-btn-outline-primary w-edit-btn';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> 缂栬緫';
                editBtn.onclick = function () {
                    DataTableFormManager.editRecord(formId, recordId);
                };

                actionCell.appendChild(editBtn);
            });
        },

        /**
         * 鑷姩涓洪〉闈笂鐨勬墍鏈夎〃鏍兼坊鍔犵紪杈戝姛鑳?
         */
        autoAddEditButtons: function () {
            // 鏌ユ壘鎵€鏈夊甫鏈塪ata-form-id灞炴€х殑琛ㄦ牸
            const tables = document.querySelectorAll('table[data-form-id]');
            tables.forEach(table => {
                const formId = table.getAttribute('data-form-id');
                if (formId) {
                    this.addEditButtonToTable('table[data-form-id="' + formId + '"]', formId);
                }
            });

            // 涔熷彲浠ラ€氳繃绾﹀畾鏌ユ壘琛ㄦ牸鍜岃〃鍗?
            const forms = document.querySelectorAll('.w-form-modal');
            forms.forEach(modal => {
                const formId = modal.id.replace('w-form-modal-', '');
                const tableSelector = '[data-scope="' + formId.replace('-form', '') + '"]';
                this.addEditButtonToTable(tableSelector, formId);
            });
        }
    };

    // 灏嗗疄渚嬫毚闇插埌鍏ㄥ眬
    window.DataTableFormManager = DataTableFormManagerInstance;

    // 椤甸潰鍔犺浇瀹屾垚鍚庡垵濮嬪寲
    if (!DataTableFormManagerInstance._initialized) {
        DataTableFormManagerInstance._initialized = true;
        document.addEventListener('DOMContentLoaded', function () {
            console.log('DataTableFormManager 宸插姞杞?');

            // 鑷姩鍒濆鍖栭〉闈笂鐨勬墍鏈夎〃鍗?
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

            // 鑷姩涓鸿〃鏍兼坊鍔犵紪杈戞寜閽?
            setTimeout(() => {
                DataTableFormManager.autoAddEditButtons();
            }, 500);

            // 鐩戝惉琛ㄦ牸鍐呭鍙樺寲锛岃嚜鍔ㄦ坊鍔犵紪杈戞寜閽?
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

                // 瑙傚療琛ㄦ牸瀹瑰櫒鐨勫彉鍖?
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

(function () {
    if (typeof window === 'undefined' || !window.DataTableFormManager) {
        return;
    }

    const manager = window.DataTableFormManager;
    const originalInitForm = manager.initForm.bind(manager);

    function resolveApiUrl(url, fallback) {
        const raw = String(url || fallback || '').trim();
        if (!raw) {
            return '';
        }

        if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) {
            return raw;
        }


        return '/' + raw.replace(/^\/+/, '');
    }

    function buildApiUrl(baseUrl, endpoint) {
        const resolvedBaseUrl = resolveApiUrl(baseUrl, '');
        if (!endpoint) {
            return resolvedBaseUrl;
        }

        return resolvedBaseUrl.replace(/\/+$/, '') + '/' + String(endpoint).replace(/^\/+/, '');
    }

    function deriveRecordApiUrl(fieldApiUrl) {
        const resolvedFieldApiUrl = resolveApiUrl(fieldApiUrl, '');
        if (!resolvedFieldApiUrl) {
            return '';
        }

        if (resolvedFieldApiUrl.endsWith('/fields')) {
            return resolvedFieldApiUrl.replace(/\/fields$/, '/record');
        }

        return buildApiUrl(resolvedFieldApiUrl, 'record');
    }

    function findFieldContainer(field) {
        return field.closest('.w-form-field') || field.closest('.form-group[data-field]') || field.parentElement;
    }

    function getValidationElement(container) {
        if (!container) {
            return null;
        }

        let element = container.querySelector('.w-field-validation, .invalid-feedback');
        if (!element) {
            element = document.createElement('div');
            element.className = 'w-field-validation invalid-feedback';
            container.appendChild(element);
        }

        return element;
    }

    function isSuccessfulResponse(response) {
        return !!(response && (response.success || response.code == 200 || response.code === '200'));
    }


    async function requestFormJson(instance, endpoint, payload, requestOptions) {
        if (!instance || !instance.options || !instance.options.workerApi) {
            throw new Error('DataTable form frontend requests require Weline.Api worker mode.');
        }

        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api.resource is not available');
        }

        const provider = instance.options.apiProvider || 'datatable';
        const api = await window.Weline.Api.resource(provider);
        const method = operationName(instance, endpoint);
        if (!api || typeof api[method] !== 'function') {
            throw new Error('Weline.Api operation is not available: ' + provider + '.' + method);
        }

        return api[method](payload || {}, Object.assign({silent: true}, requestOptions || {}));
    }

    function parseFieldConfig(container, field) {
        let config = {};
        const rawConfig = container ? container.getAttribute('data-w-field') : '';
        if (rawConfig) {
            try {
                config = JSON.parse(rawConfig);
            } catch (error) {
                console.warn('[DataTableFormManager] failed to parse data-w-field', error);
            }
        }

        return {
            name: config.name || field.getAttribute('data-original-name') || field.name,
            type: config.type || field.type || field.tagName.toLowerCase(),
            required: config.required === true || field.required || field.getAttribute('required') !== null,
            min: config.min ?? field.getAttribute('min'),
            max: config.max ?? field.getAttribute('max'),
            maxlength: config.maxlength ?? field.getAttribute('maxlength'),
            pattern: (config.validation && config.validation.pattern) || config.pattern || field.getAttribute('pattern') || '',
            label: config.label || (container && container.getAttribute('data-field')) || field.name
        };
    }

    function assignCollectedValue(target, name, value) {
        if (value === undefined || name === undefined || name === null || name === '') {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(target, name)) {
            if (Array.isArray(target[name])) {
                target[name].push(value);
            } else {
                target[name] = [target[name], value];
            }
            return;
        }

        target[name] = value;
    }

    function serializeFiles(fileList) {
        const files = Array.from(fileList || []).map(file => ({
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: file.lastModified
        }));

        if (files.length <= 1) {
            return files[0] || '';
        }

        return files;
    }

    manager.resolveApiUrl = resolveApiUrl;
    manager.buildApiUrl = buildApiUrl;
    manager.deriveRecordApiUrl = deriveRecordApiUrl;
    manager.modelConfig = manager.modelConfig || {};

    manager.initForm = function (formId, options) {
        const normalizedOptions = Object.assign({}, options || {});
        normalizedOptions.apiUrl = normalizedOptions.workerApi ? '' : resolveApiUrl(normalizedOptions.apiUrl, this.config.apiUrl);
        normalizedOptions.fieldApiUrl = normalizedOptions.workerApi ? '' : resolveApiUrl(normalizedOptions.fieldApiUrl, this.config.fieldApiUrl);
        normalizedOptions.recordApiUrl = resolveApiUrl(
            normalizedOptions.recordApiUrl,
            deriveRecordApiUrl(normalizedOptions.fieldApiUrl)
        );
        normalizedOptions.modelConfig = normalizedOptions.modelConfig || this.modelConfig || {};

        const result = originalInitForm(formId, normalizedOptions);
        const instance = this.instances[formId];
        if (instance) {
            instance.options = Object.assign({}, instance.options, normalizedOptions);
            instance.loadedRecordData = instance.loadedRecordData || null;
        }

        return result;
    };

    manager.extractManualFields = function (formId) {
        const form = document.getElementById(formId);
        const instance = this.instances[formId];
        if (!form || !instance) {
            return;
        }

        const manualFields = [];
        const fieldMap = new Map();
        const fieldContainers = form.querySelectorAll('.w-form-field, .form-group[data-field]');

        fieldContainers.forEach(container => {
            const field = container.querySelector('input[name], select[name], textarea[name]');
            if (!field) {
                return;
            }

            const config = parseFieldConfig(container, field);
            if (!manualFields.includes(config.name)) {
                manualFields.push(config.name);
            }
            fieldMap.set(config.name, Object.assign(fieldMap.get(config.name) || {}, config));
        });

        instance.manualFields = manualFields;
        instance.fields = Array.from(fieldMap.values()).concat(
            (instance.fields || []).filter(field => !fieldMap.has(field.name))
        );
    };

    manager.mergeFieldMetadata = function (formId, fields) {
        const instance = this.instances[formId];
        if (!instance) {
            return;
        }

        const merged = new Map();
        (instance.fields || []).forEach(field => merged.set(field.name, field));
        (fields || []).forEach(field => {
            if (!field || !field.name) {
                return;
            }

            merged.set(field.name, Object.assign({}, merged.get(field.name) || {}, field));
        });

        instance.fields = Array.from(merged.values());
    };

    manager.loadAutoFields = function (formId) {
        const instance = this.instances[formId];
        if (!instance) {
            return;
        }

        const autoFieldsContainer = document.getElementById('w-auto-fields-' + formId);
        if (!autoFieldsContainer) {
            return;
        }

        requestFormJson(instance, 'formFields', {
            form_id: formId,
            model: instance.options.model,
            scope: instance.options.scope,
            exclude_fields: instance.options.excludeFields || [],
            include_fields: instance.options.includeFields || [],
            manual_fields: instance.manualFields || [],
            model_config: instance.options.modelConfig || {}
        })
            .then(response => {
                if (!isSuccessfulResponse(response)) {
                    this.showError(formId, response.message || response.msg || __('鍔犺浇瀛楁澶辫触'));
                    return;
                }

                const payload = response.data || {};
                this.mergeFieldMetadata(formId, payload.fields || []);
                this.renderAutoFields(formId, payload);
            })
            .catch(error => {
                console.error('[DataTableFormManager] loadAutoFields failed', error);
                this.showError(formId, error.message || __('缃戠粶閿欒'));
            });
    };

    manager.fillFormData = function (formId, data) {
        const form = document.getElementById(formId);
        if (!form || !data || typeof data !== 'object') {
            return;
        }

        const controls = form.querySelectorAll('input[name], select[name], textarea[name]');
        controls.forEach(control => {
            const fieldName = control.getAttribute('data-original-name') || control.name;
            if (!Object.prototype.hasOwnProperty.call(data, fieldName)) {
                return;
            }

            const value = data[fieldName];
            if (control.type === 'radio') {
                control.checked = String(control.value) === String(value);
            } else if (control.type === 'checkbox') {
                if (Array.isArray(value)) {
                    control.checked = value.map(String).includes(String(control.value));
                } else {
                    control.checked = String(value) === String(control.value) || String(value) === '1' || String(value).toLowerCase() === 'true';
                }
            } else if (control.multiple && Array.isArray(value)) {
                Array.from(control.options).forEach(option => {
                    option.selected = value.map(String).includes(String(option.value));
                });
            } else if (control.type !== 'file') {
                control.value = value == null ? '' : String(value);
            }
        });
    };

    manager.loadRecordData = function (formId, recordId) {
        const instance = this.instances[formId];
        const form = document.getElementById(formId);
        if (!instance || !form) {
            return;
        }

        requestFormJson(instance, 'formRecord', {
            form_id: formId,
            model: instance.options.model,
            scope: instance.options.scope,
            record_id: recordId,
            model_config: instance.options.modelConfig || {},
            dependencies: instance.options.dependencies || ''
        })
            .then(response => {
                if (!isSuccessfulResponse(response)) {
                    this.showError(formId, response.message || response.msg || __('鍔犺浇璁板綍澶辫触'));
                    return;
                }

                const payload = response.data && response.data.record ? response.data.record : (response.data || {});
                instance.loadedRecordData = payload;

                if (form.querySelectorAll('.multi-table-group[data-table-alias]').length > 0) {
                    this.populateMultiTableForm(form, payload);
                } else {
                    this.fillFormData(formId, payload);
                }
            })
            .catch(error => {
                console.error('[DataTableFormManager] loadRecordData failed', error);
                this.showError(formId, error.message || __('缃戠粶閿欒'));
            });
    };

    manager.validateDomField = function (field) {
        const container = findFieldContainer(field);
        const validationElement = getValidationElement(container);
        const config = parseFieldConfig(container, field);

        let isValid = true;
        let message = '';
        const rawValue = field.type === 'checkbox'
            ? (field.checked ? (field.value || '1') : '')
            : (field.type === 'radio' ? (field.checked ? field.value : '') : field.value);
        const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue;

        if (config.required) {
            if (field.type === 'checkbox' && !field.checked) {
                isValid = false;
                message = __('姝ゅ瓧娈典负蹇呭～椤?');
            } else if (field.type === 'radio') {
                const sameNameFields = field.form ? field.form.querySelectorAll('[name="' + field.name + '"]') : [];
                const checked = Array.from(sameNameFields).some(item => item.checked);
                if (!checked) {
                    isValid = false;
                    message = __('姝ゅ瓧娈典负蹇呭～椤?');
                }
            } else if (!value) {
                isValid = false;
                message = __('姝ゅ瓧娈典负蹇呭～椤?');
            }
        }

        if (isValid && value) {
            if ((config.type === 'email' || field.type === 'email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value))) {
                isValid = false;
                message = __('璇疯緭鍏ユ湁鏁堢殑閭鍦板潃');
            } else if ((config.type === 'number' || field.type === 'number') && Number.isNaN(Number(value))) {
                isValid = false;
                message = __('璇疯緭鍏ユ湁鏁堢殑鏁板瓧');
            } else if (config.min !== null && config.min !== '' && !Number.isNaN(Number(value)) && Number(value) < Number(config.min)) {
                isValid = false;
                message = __('鏁板€间笉鑳藉皬浜?%{1}', [config.min]);
            } else if (config.max !== null && config.max !== '' && !Number.isNaN(Number(value)) && Number(value) > Number(config.max)) {
                isValid = false;
                message = __('鏁板€间笉鑳藉ぇ浜?%{1}', [config.max]);
            } else if (config.maxlength && String(value).length > Number(config.maxlength)) {
                isValid = false;
                message = __('杈撳叆闀垮害涓嶈兘瓒呰繃 %{1}', [config.maxlength]);
            } else if (config.pattern) {
                try {
                    const regex = new RegExp(config.pattern);
                    if (!regex.test(String(value))) {
                        isValid = false;
                        message = __('瀛楁鏍煎紡涓嶆纭?');
                    }
                } catch (error) {
                    console.warn('[DataTableFormManager] invalid validation pattern', config.pattern, error);
                }
            }
        }

        field.classList.toggle('is-invalid', !isValid);
        if (container) {
            container.classList.toggle('w-field-error', !isValid);
            container.classList.toggle('field-error', !isValid);
        }
        if (validationElement) {
            validationElement.textContent = isValid ? '' : message;
            validationElement.style.display = isValid ? 'none' : '';
        }

        return isValid;
    };

    manager.validateField = function (formIdOrField, fieldName) {
        if (formIdOrField instanceof Element) {
            return this.validateDomField(formIdOrField);
        }

        const form = document.getElementById(formIdOrField);
        if (!form) {
            return true;
        }

        const candidateFields = Array.from(form.querySelectorAll('input[name], select[name], textarea[name]')).filter(field => {
            const names = [field.name, field.getAttribute('data-original-name') || ''];
            const normalizedNames = names
                .filter(Boolean)
                .flatMap(name => [name, name.replace(/^[^.]+\./, '')]);

            return normalizedNames.includes(fieldName);
        });

        if (candidateFields.length === 0) {
            return true;
        }

        return candidateFields.every(field => this.validateDomField(field));
    };

    manager.validateForm = function (formId) {
        const form = document.getElementById(formId);
        if (!form) {
            return false;
        }

        let isValid = true;
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(field => {
            if (field.disabled || ['hidden', 'button', 'submit', 'reset'].includes(field.type)) {
                return;
            }

            if (!this.validateDomField(field)) {
                isValid = false;
            }
        });

        return isValid;
    };

    manager.getSerializedFieldValue = function (field) {
        if (!field) {
            return undefined;
        }

        if (field.type === 'file') {
            return serializeFiles(field.files);
        }

        if (field.type === 'checkbox') {
            if (field.name.endsWith('[]')) {
                return field.checked ? field.value : undefined;
            }

            return field.checked ? (field.value || '1') : '';
        }

        if (field.type === 'radio') {
            return field.checked ? field.value : undefined;
        }

        if (field.multiple) {
            return Array.from(field.selectedOptions || []).map(option => option.value);
        }

        return field.value;
    };

    manager.collectFormData = function (formElement) {
        const data = {};
        const controls = formElement.querySelectorAll('input[name], select[name], textarea[name]');

        controls.forEach(field => {
            if (field.disabled || ['button', 'submit', 'reset'].includes(field.type)) {
                return;
            }

            const value = this.getSerializedFieldValue(field);
            assignCollectedValue(data, field.name, value);
        });

        return data;
    };

    manager.collectMultiTableData = function (formElement) {
        const data = {};
        const multiTableGroups = formElement.querySelectorAll('.multi-table-group[data-table-alias]');

        if (multiTableGroups.length === 0) {
            return this.collectFormData(formElement);
        }

        multiTableGroups.forEach(group => {
            const alias = group.getAttribute('data-table-alias');
            if (!alias) {
                return;
            }

            data[alias] = data[alias] || {};
            group.querySelectorAll('input[name], select[name], textarea[name]').forEach(field => {
                if (field.disabled || ['button', 'submit', 'reset'].includes(field.type)) {
                    return;
                }

                const originalName = field.getAttribute('data-original-name') || field.name;
                const cleanFieldName = originalName.replace(new RegExp('^' + alias.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\.'), '');
                const value = this.getSerializedFieldValue(field);
                assignCollectedValue(data[alias], cleanFieldName, value);
            });
        });

        const instance = this.instances[formElement.id];
        if (instance && instance.loadedRecordData && typeof instance.loadedRecordData === 'object') {
            Object.keys(data).forEach(alias => {
                const loadedAliasData = instance.loadedRecordData[alias];
                if (loadedAliasData && typeof loadedAliasData === 'object' && loadedAliasData.id && !data[alias].id) {
                    data[alias].id = loadedAliasData.id;
                }
            });
        }

        return data;
    };

    manager.validateMultiTableForm = function (formElement) {
        let isValid = true;
        const errors = {};

        formElement.querySelectorAll('.multi-table-group[data-table-alias]').forEach(group => {
            const alias = group.getAttribute('data-table-alias') || 'default';
            group.querySelectorAll('input[name], select[name], textarea[name]').forEach(field => {
                if (field.disabled || ['hidden', 'button', 'submit', 'reset'].includes(field.type)) {
                    return;
                }

                const valid = this.validateDomField(field);
                if (!valid) {
                    isValid = false;
                    errors[alias] = errors[alias] || {};
                    const originalName = field.getAttribute('data-original-name') || field.name;
                    errors[alias][originalName] = __('瀛楁楠岃瘉澶辫触');
                }
            });
        });

        return {
            isValid: isValid,
            errors: errors
        };
    };

    manager.toggleFieldset = function (fieldsetId) {
        const fieldset = document.getElementById(fieldsetId);
        if (!fieldset) {
            return;
        }

        fieldset.classList.toggle('collapsed');
        const icon = fieldset.querySelector('.collapse-toggle i');
        if (icon) {
            icon.className = fieldset.classList.contains('collapsed')
                ? 'fas fa-chevron-down'
                : 'fas fa-chevron-up';
        }
    };

    manager.setModelConfig = function (modelConfig) {
        this.modelConfig = modelConfig || {};
        Object.keys(this.instances || {}).forEach(formId => {
            const instance = this.instances[formId];
            if (instance && instance.options && (!instance.options.modelConfig || Object.keys(instance.options.modelConfig).length === 0)) {
                instance.options.modelConfig = this.modelConfig;
            }
        });
    };

    manager.submitForm = function (formId) {
        const instance = this.instances[formId];
        const form = document.getElementById(formId);
        if (!instance || !form) {
            return;
        }

        const hasMultiTableGroups = form.querySelectorAll('.multi-table-group[data-table-alias]').length > 0;
        const validationResult = hasMultiTableGroups ? this.validateMultiTableForm(form) : { isValid: this.validateForm(formId) };

        if (!validationResult.isValid) {
            this.showError(formId, __('璇锋鏌ヨ〃鍗曚腑鐨勯敊璇?'));
            return;
        }

        const requestData = {
            model: instance.options.model,
            scope: instance.options.scope,
            data: hasMultiTableGroups ? this.collectMultiTableData(form) : this.collectFormData(form),
            model_config: instance.options.modelConfig || this.modelConfig || {}
        };

        const isEdit = instance.options.mode === 'edit' && (instance.options.recordId || instance.loadedRecordData);
        if (isEdit) {
            requestData.id = instance.options.recordId || instance.loadedRecordData?.id || '';
        }

        if (instance.options.dependencies) {
            requestData.dependencies = instance.options.dependencies;
        }
        if (instance.options.transaction !== undefined) {
            requestData.transaction = instance.options.transaction;
        }

        this.showSubmitting(formId);

        requestFormJson(instance, isEdit ? 'saveData' : 'create', requestData)
            .then(response => {
                if (!isSuccessfulResponse(response)) {
                    this.showError(formId, response.message || response.msg || __('淇濆瓨澶辫触'));
                    return;
                }

                this.showSuccess(formId, response.message || response.msg || __('淇濆瓨鎴愬姛'));

                if (typeof window.onFormSuccess === 'function') {
                    window.onFormSuccess(formId, response.data || {});
                }

                if (window.DataTableManager && typeof window.DataTableManager.loadData === 'function') {
                    Object.keys(window.DataTableManager.instances || {}).forEach(tableId => {
                        const tableInstance = window.DataTableManager.instances[tableId];
                        if (!tableInstance || !tableInstance.options) {
                            return;
                        }

                        if (
                            tableInstance.options.scope === instance.options.scope ||
                            tableInstance.options.model === instance.options.model
                        ) {
                            window.DataTableManager.loadData(tableInstance);
                        }
                    });
                }

                this.closeModal(formId);
            })
            .catch(error => {
                console.error('[DataTableFormManager] submitForm failed', error);
                this.showError(formId, error.message || __('缃戠粶閿欒'));
            })
            .finally(() => {
                this.hideSubmitting(formId);
            });
    };
})();
