# 2026-10-22：分销浮动入口遮挡诊断详情按钮

## 根因与最小修复

`#admin-dist-entry` 与 `#admin-diagnostics-root` 都直接挂在 body。浮动入口的层级为 9000，诊断根层原为 1000；两者在屏幕上重叠时，实际点击会命中分销入口，打开分销管理而不是诊断详情。

生产样式只改 `public/assets/admin-distributor.css` 一处：诊断根层 `z-index:1000` → `9001`。浮动入口仍为 9000，既有分销面板 9500、详情 10000、备注/通知 11000 保持原值。没有修改 JS、侧栏/页面布局或入口行为。

## 真实鼠标红绿回归

`tests/Browser/admin-diagnostics-center.cjs` 复用真实 CSS/JS 与既有合成 API fixture，在 1440×900、390×900 两种 viewport 增加确定重叠用例：

1. 正常点击日志中心、精确查订单；仅给 fixture 增加列表滚动余量，将真实详情按钮滚到浮动入口原有固定位置。没有修改任一层级、pointer-events 或入口位置。
2. 根据两者实际 bounding box 算交集，要求确实重叠，记录 `elementFromPoint` 命中；用 `page.mouse.click` 点击交集内部，不使用 force 或 DOM click。
3. 要求产生一次正确 connection 请求、显示详情、没有误开分销面板；关闭日志中心后，在同一点真实鼠标点击仍能打开分销入口。

旧 CSS 的两项用例均按预期 FAIL：命中 `admin-dist-entry`，实际点击后 connection 请求数仍为 0。修复后完全相同的坐标命中详情按钮，业务结果及关闭后的入口回归均通过。

| viewport | 实际重叠点击点 | 旧样式命中 | 修复后命中 |
| --- | --- | --- | --- |
| 1440×900 | (1351.8671875, 792) | `admin-dist-entry` | connection `0` |
| 390×900 | (330.53125, 792) | `admin-dist-entry` | connection `0` |

本地命令：

```text
node --test --test-timeout=20000 --test-name-pattern="floating distributor entry" tests/Browser/admin-diagnostics-center.cjs
node --test --test-timeout=20000 tests/Browser/admin-diagnostics-center.cjs
node --test tests/JavaScript/admin-distributor-ui.test.js tests/JavaScript/admin-distributor-rename-ui.test.js
git diff --check
```

结果：旧样式重叠回归 2/2 预期失败；修复后完整诊断浏览器文件 9/9 PASS；相关 JavaScript 14/14 PASS；diff check PASS。环境为 Node v24.12.0、Playwright 1.62.1、既有本机 Chromium 1234，没有安装或下载浏览器。

证据目录 `C:\Temp\xboardme-logging-followup-20261022\diagnostics-overlap`，包含 red-final/green 桌面及手机截图。最终红色日志 `diagnostics-overlap-red-final.log` SHA256 `673c6e0da1b5c76567ceab09a45a0e99551360b0ff3f8ed4ce454ada0c033a41`；绿色浏览器日志 `diagnostics-overlap-green-browser.log` SHA256 `303330aa459f58423c7c7f4810adab7a72871c88d9c68b76572a9be3450496d0`。

## 状态与限制

本修复仅本地未提交；没有连接远端、部署或替换已服务资产。现场截图作为问题输入，回归使用确定重叠的合成数据页面，不声称重放了现场完整布局或完成公开 URL 验收。全仓库发布检查和实际服务资产/浏览器验收由协调者接续。

保留其余工作区 dirty 文件及未跟踪文件；未修改协调者负责的 `customer-connection-diagnostics-progress.md`。本项只新增/修改上述 CSS、浏览器测试与本说明。
