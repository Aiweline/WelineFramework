# 预览帧邮箱（PreviewFrameBus）

可视化编辑器父页与 iframe 之间只有一个跨帧出口：`editor-mode.js` 的 `postPreviewMessage`。

热路径不允许再各写一套 debounce、签名去重或「这条消息不要 console.log」。那是补丁。显示刷新的时钟是动画帧，不是任意毫秒。

## 两条车道

| 车道 | 事件 | 投递 |
|------|------|------|
| 冷 | `widget-dropped`、`widget-selected`、`widget-rejected`、`slot-selected`、`locale-change`、`slot-init-defaults` | 立即 `postMessage`，`lane: "cold"` |
| 热 | `drop-candidate`、`drop-candidate-clear`、`slot-hover-sync` | 只保留最新快照，`requestAnimationFrame` 每帧最多一条，`lane: "hot"` |

同身份热快照不入队。父页 `handleIframeMessage` 用 `data.lane !== 'hot'` 决定要不要打日志，不按消息类型开黑名单。

## 拖放只有一个权威

部件从库拖进画布时，父页 drop-bridge 与 iframe **同源**。`dragover` 直接调用：

```javascript
previewFrame.contentWindow.Weline.Theme.Preview.resolveDropAtPoint(x, y, widget, {
  notifyParent: false,
});
```

返回值写入父页 `rememberPreviewDropCandidate`。这次调用**禁止**再 `postMessage('drop-candidate')`。插入指示在 iframe 里同步改 DOM，跟手不依赖跨帧消息。

iframe 自己的 HTML5 `dragover`（DataTransfer 还在帧内）才把候选放进热车道。松手提交仍走冷车道 `widget-dropped`，或父页用已经记下的候选提交。

结构视图的插入线同样按插入索引跳过重建：索引没变就不拆 DOM。画布里新出现的插槽或部件，由同一条 mutation 安静窗在 flush 时发一条冷消息 `preview-structure-changed`，父页只初始化一次悬停按钮。禁止再用 100/400/1200 毫秒连打 `initWidgetHoverActions`。

## 禁止

- 用 `setTimeout` 毫秒数合并 `drop-candidate`
- 父页 drop-bridge 再套一层 `requestAnimationFrame` 延迟解析（`previewDropBridgeRaf`）
- 热路径逐条 `console.log`
- 为同一插入位同时走「直调返回值」和「postMessage」

## 源码与产物

改 `view/statics/js/editor-mode.js` 与 `view/statics/js/theme-editor.js`。`view/statics/ui/pages/weline-theme-preview.js`、`weline-theme-editor.js` 是 `php bin/w resource:compile welineUi` 的产物，不要手改。

契约：`ThemeEditorUiCapabilityContractTest::testVisualPreviewDragDropKeepsInsideBeforeAndAfterFeedback`。
