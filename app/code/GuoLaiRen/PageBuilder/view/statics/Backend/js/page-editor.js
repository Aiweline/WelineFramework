/**
 * Page Builder - Editor.js Integration
 * 页面构建器 - Editor.js 集成
 */

class PageEditor {
    constructor(options) {
        this.options = Object.assign({
            holder: 'editorjs',
            placeholder: '开始输入页面内容...',
            autofocus: false,
            data: {},
            onChange: null
        }, options);
        
        this.editor = null;
        this.init();
    }
    
    /**
     * 初始化编辑器
     */
    async init() {
        // 等待 Editor.js 库加载完成
        await this.waitForEditorJS();
        
        // 创建编辑器实例
        this.editor = new EditorJS({
            holder: this.options.holder,
            placeholder: this.options.placeholder,
            autofocus: this.options.autofocus,
            data: this.options.data,
            onChange: this.options.onChange,
            
            tools: {
                // 标题工具
                header: {
                    class: Header,
                    config: {
                        placeholder: '输入标题',
                        levels: [1, 2, 3, 4, 5, 6],
                        defaultLevel: 2
                    },
                    inlineToolbar: true
                },
                
                // 段落工具（默认）
                paragraph: {
                    class: Paragraph,
                    inlineToolbar: true,
                    config: {
                        placeholder: '输入段落内容'
                    }
                },
                
                // 列表工具
                list: {
                    class: List,
                    inlineToolbar: true,
                    config: {
                        defaultStyle: 'unordered'
                    }
                },
                
                // 引用工具
                quote: {
                    class: Quote,
                    inlineToolbar: true,
                    config: {
                        quotePlaceholder: '输入引用内容',
                        captionPlaceholder: '引用来源'
                    }
                },
                
                // 代码工具
                code: {
                    class: CodeTool,
                    config: {
                        placeholder: '输入代码'
                    }
                },
                
                // 分隔线工具
                delimiter: Delimiter,
                
                // 警告框工具
                warning: {
                    class: Warning,
                    inlineToolbar: true,
                    config: {
                        titlePlaceholder: '标题',
                        messagePlaceholder: '消息内容'
                    }
                },
                
                // 表格工具
                table: {
                    class: Table,
                    inlineToolbar: true,
                    config: {
                        rows: 2,
                        cols: 3,
                    }
                },
                
                // 图片工具
                image: {
                    class: ImageTool,
                    config: {
                        uploader: {
                            uploadByFile(file) {
                                return new Promise((resolve, reject) => {
                                    // TODO: 实现文件上传到服务器
                                    // 这里需要调用后端上传接口
                                    console.warn('图片上传功能待实现');
                                    reject('图片上传功能待实现');
                                });
                            },
                            uploadByUrl(url) {
                                return Promise.resolve({
                                    success: 1,
                                    file: {
                                        url: url
                                    }
                                });
                            }
                        }
                    }
                },
                
                // 嵌入工具（视频等）
                embed: {
                    class: Embed,
                    config: {
                        services: {
                            youtube: true,
                            coub: true,
                            codepen: true,
                            instagram: true,
                            twitter: true,
                            facebook: true,
                            vimeo: true
                        }
                    }
                },
                
                // 链接工具
                linkTool: {
                    class: LinkTool,
                    config: {
                        endpoint: '' // 可以配置链接预览接口
                    }
                },
                
                // 原始 HTML 工具
                raw: RawTool,
                
                // 检查清单工具
                checklist: {
                    class: Checklist,
                    inlineToolbar: true
                }
            },
            
            // 国际化配置
            i18n: {
                messages: {
                    ui: {
                        "blockTunes": {
                            "toggler": {
                                "Click to tune": "点击调整",
                                "or drag to move": "或拖动移动"
                            }
                        },
                        "inlineToolbar": {
                            "converter": {
                                "Convert to": "转换为"
                            }
                        },
                        "toolbar": {
                            "toolbox": {
                                "Add": "添加"
                            }
                        }
                    },
                    toolNames: {
                        "Text": "文本",
                        "Heading": "标题",
                        "List": "列表",
                        "Warning": "警告",
                        "Checklist": "清单",
                        "Quote": "引用",
                        "Code": "代码",
                        "Delimiter": "分隔线",
                        "Raw HTML": "原始 HTML",
                        "Table": "表格",
                        "Link": "链接",
                        "Image": "图片",
                        "Embed": "嵌入"
                    },
                    tools: {
                        "warning": {
                            "Title": "标题",
                            "Message": "消息"
                        },
                        "link": {
                            "Add a link": "添加链接"
                        },
                        "stub": {
                            "The block can not be displayed correctly.": "该块无法正确显示"
                        }
                    },
                    blockTunes: {
                        "delete": {
                            "Delete": "删除"
                        },
                        "moveUp": {
                            "Move up": "向上移动"
                        },
                        "moveDown": {
                            "Move down": "向下移动"
                        }
                    }
                }
            }
        });
    }
    
    /**
     * 等待 EditorJS 库加载
     */
    waitForEditorJS() {
        return new Promise((resolve) => {
            const checkInterval = setInterval(() => {
                if (typeof EditorJS !== 'undefined') {
                    clearInterval(checkInterval);
                    resolve();
                }
            }, 100);
        });
    }
    
    /**
     * 保存编辑器数据
     */
    async save() {
        if (!this.editor) {
            return null;
        }
        
        try {
            const savedData = await this.editor.save();
            return savedData;
        } catch (error) {
            console.error('保存编辑器数据失败:', error);
            return null;
        }
    }
    
    /**
     * 清空编辑器
     */
    async clear() {
        if (!this.editor) {
            return;
        }
        
        await this.editor.clear();
    }
    
    /**
     * 销毁编辑器
     */
    destroy() {
        if (this.editor) {
            this.editor.destroy();
            this.editor = null;
        }
    }
    
    /**
     * 渲染编辑器数据为 HTML
     */
    static renderToHTML(data) {
        if (!data || !data.blocks || !Array.isArray(data.blocks)) {
            return '';
        }
        
        let html = '';
        
        data.blocks.forEach(block => {
            switch (block.type) {
                case 'header':
                    const level = block.data.level || 2;
                    html += `<h${level}>${block.data.text}</h${level}>`;
                    break;
                    
                case 'paragraph':
                    html += `<p>${block.data.text}</p>`;
                    break;
                    
                case 'list':
                    const listTag = block.data.style === 'ordered' ? 'ol' : 'ul';
                    html += `<${listTag}>`;
                    block.data.items.forEach(item => {
                        html += `<li>${item}</li>`;
                    });
                    html += `</${listTag}>`;
                    break;
                    
                case 'quote':
                    html += `<blockquote>`;
                    html += `<p>${block.data.text}</p>`;
                    if (block.data.caption) {
                        html += `<cite>${block.data.caption}</cite>`;
                    }
                    html += `</blockquote>`;
                    break;
                    
                case 'code':
                    html += `<pre><code>${block.data.code}</code></pre>`;
                    break;
                    
                case 'delimiter':
                    html += `<hr>`;
                    break;
                    
                case 'warning':
                    html += `<div class="warning">`;
                    if (block.data.title) {
                        html += `<strong>${block.data.title}</strong>`;
                    }
                    html += `<p>${block.data.message}</p>`;
                    html += `</div>`;
                    break;
                    
                case 'table':
                    html += `<table>`;
                    block.data.content.forEach((row, index) => {
                        html += `<tr>`;
                        row.forEach(cell => {
                            const tag = index === 0 ? 'th' : 'td';
                            html += `<${tag}>${cell}</${tag}>`;
                        });
                        html += `</tr>`;
                    });
                    html += `</table>`;
                    break;
                    
                case 'image':
                    html += `<figure>`;
                    html += `<img src="${block.data.file.url}" alt="${block.data.caption || ''}">`;
                    if (block.data.caption) {
                        html += `<figcaption>${block.data.caption}</figcaption>`;
                    }
                    html += `</figure>`;
                    break;
                    
                case 'embed':
                    html += `<div class="embed">${block.data.embed}</div>`;
                    break;
                    
                case 'raw':
                    html += block.data.html;
                    break;
                    
                case 'checklist':
                    html += `<ul class="checklist">`;
                    block.data.items.forEach(item => {
                        const checked = item.checked ? 'checked' : '';
                        html += `<li><input type="checkbox" ${checked} disabled> ${item.text}</li>`;
                    });
                    html += `</ul>`;
                    break;
                    
                default:
                    console.warn(`未知的块类型: ${block.type}`);
            }
        });
        
        return html;
    }
}

// 导出为全局变量
window.PageEditor = PageEditor;

