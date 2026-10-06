# 分销订阅名称测试报告

后续MySQL测试隔离修复和发布浏览器门禁验证见[上线前修复与验证](distributor-subscription-names-release-validation.md)。下文保留本轮历史执行结果，后续状态以补充记录及实际CI结果为准。

本轮针对“旧订阅保持原样、仅新购订阅采用新名称”重新执行本地验收，并补充鉴权、事务回滚和特殊字符输出测试。没有修改生产业务代码，也没有操作线上订单、推送或部署。测试范围与用例定义见 [测试方案和任务清单](distributor-subscription-names-test-plan.md)。

本地自动化验收通过：PHP 完整回归 297 项和 3,307 个断言、JavaScript 125 项、浏览器 5 个场景均无失败或跳过。没有发现需要修复的产品缺陷；MySQL 和实际客户端验收尚未完成，本报告不作发布验收通过结论。

## 基线和环境

- 业务基线：`1e5e7d4af7947c1c65b06e0b93b1c7c27a116a5b`，主工作区 `develop`。
- 本轮新增三个 PHP 验收方法，命名专项由 17 项扩展为 20 项。
- 测试文件 SHA256：`DB702816ADDD3DF287BDF42FBC6D4933535160EC727ECFD67794B432CDB1DE1B`。
- 后端：Windows、PHP 8.5.9、PHPUnit 12.5.33、SQLite 内存数据库、OpenSpout 4.28.5。
- 前端独立任务：Node 24.19.0、Playwright 1.62.1、Chrome 154.0.8037.93，390px 和 1440px 视口，本地接口夹具。
- 本机 PHP PDO 仅有 SQLite 驱动；Docker 的 desktop-linux 引擎连接失败。MySQL 测试未执行。
- 未加载 Xdebug 或 PCOV，行覆盖率、分支覆盖率和改动行覆盖率均未测量；不以用例数量代替覆盖率。

独立前端任务为“分销订阅命名兼容性测试”，ID `01a110bd-4edb-7311-a9d7-992e641c847a`。其测试源文件和相关前端文件在执行前后哈希一致；主任务单独汇总后端证据。

## 执行结果

以下均为本轮执行，时间为 2026 年 10 月 6 日北京时间。耗时采用进程墙钟时间；PHPUnit 自报时间保留在原始日志中。

| 检查 | 结果 | 通过和断言 | 失败 | 跳过 | 退出码 | 时间和耗时 |
| --- | --- | --- | --- | --- | --- | --- |
| 既有命名专项 | PASS | 17 项，313 断言 | 0 | 0 | 0 | 18:24:27 至 18:24:45，17.555 秒 |
| 扩充后的命名专项 | PASS | 20 项，584 断言 | 0 | 0 | 0 | 18:30:28 至 18:30:45，16.619 秒 |
| PHP 完整回归 | PASS | 297 项，3,307 断言 | 0 | 0 | 0 | 18:31:06 至 18:32:27，81.377 秒 |
| JavaScript 完整回归 | PASS | 125 项，30 个文件 | 0 | 0 | 0 | 18:26:23 至 18:26:30，6.989 秒 |
| Chrome 浏览器 | PASS | 5 个场景 | 0 | 0 | 0 | 18:26:23 至 18:26:33，10.080 秒 |
| PHPStan | PASS | 无错误 | 0 | 不适用 | 0 | 18:24:27 至 18:24:30，2.898 秒 |
| Composer audit | PASS | 无已知依赖安全公告命中 | 0 | 不适用 | 0 | 18:24:27 至 18:25:01，33.823 秒 |
| 发布相关静态检查 | PASS | 70 条检查命令 | 0 | 0 | 0 | 18:29:03 至 18:29:13，9.951 秒 |

发布相关静态检查包括 59 个 bash 脚本、4 个 sh 脚本、5 个 PHP 辅助脚本、5 个 workflow 的 YAML 解析和 `git diff --check`。YAML 解析不等于 actionlint，也不等于远程 GitHub Actions 执行。

复现命令：

```powershell
php vendor/bin/phpunit tests/Feature/Distributor/DistributorSubscriptionNameTest.php --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result
node --test --test-reporter=tap tests/JavaScript/*.test.js
node tests/Browser/distributor-subscription-name.cjs
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer audit --locked --no-interaction
& 'C:/Temp/xboard-subscription-qa-20261006-182356/release-checks.ps1'
```

前端实际使用已安装运行时 `C:/Users/冯飏/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe`，`NODE_PATH` 为同一依赖目录下的 `node/node_modules`。JavaScript 的 30 个测试路径由 PowerShell 显式展开；完整参数见独立任务的执行元数据。

## 新增测试与验收证据

| 新增验收 | 实际操作 | 结果 |
| --- | --- | --- |
| SN12 公开名称不能鉴权 | 两个订阅入口、三种客户端 UA、短号和完整名称组合请求；检查 403、无响应标题、五张业务表快照不变、无设备绑定；真实 token 随后正常访问 | PASS，65 断言 |
| SN04 非法历史商户名的新购回滚 | null、空白、超长汉字、超长 emoji、控制字符和邮箱通过真实下单 API 请求；检查 422、订单及订阅数据没有残留、旧订阅读取及续费有效 | PASS，53 断言 |
| SN15 最长名和特殊字符 | 16 汉字、8 emoji、`=甲&乙#<北>` 经过真实购买、DB、URL fragment、三种 UA 的响应标题与文件名、XLSX 读取及原始 XML | PASS，153 断言 |

上述方法位于 [命名专项测试](../../../tests/Feature/Distributor/DistributorSubscriptionNameTest.php)。快照覆盖订单、用户、分销交付、HWID 设备及流量重置日志五张表；短号被拒绝或下单失败后逐值比较。

| 方案用例 | 已取得的证据 | 状态 |
| --- | --- | --- |
| SN01 至 SN03 | 新格式、北京时间跨日、名称长度及非法输入验证 | PASS |
| SN04 至 SN07 | 新购失败原子回滚、唯一约束与碰撞重试、新旧订阅续费名称稳定 | PASS |
| SN08 至 SN11 | 用户和管理员读取、旧订阅更新、迁移及异常历史商户均不触发回填 | PASS |
| SN12 至 SN15 | 短号不鉴权、租户隔离、实际 XLSX 内容、最长名和特殊字符传递 | PASS |
| SN16 至 SN17 | 新旧混合 DOM、旧续费弹窗、二维码文字和 Canvas 几何边界 | PASS，浏览器夹具范围 |
| SN18 | 本地全量及静态回归 | PASS |
| SN19 | MySQL 并发购买、唯一约束、savepoint 和部分 DDL 恢复 | NOT RUN |
| SN20 | 三种实际客户端导入、更新、扫码及完整运行链 | NOT RUN |

浏览器 5 个场景为分销端 390px 和 1440px 各普通及最长名称，以及管理端 1440px。后端覆盖实际续费请求与持久化不变量；浏览器仅打开续费弹窗，没有再次执行真实购买或续费。

## 首轮失败及处理

### 导出断言读取方式

扩充专项首轮为 20 项、544 断言，其中 19 项通过、1 项失败，退出码 1。失败是测试把 OpenSpout Reader 返回的单元格类当作 XLSX 存储类型：读取器会将以 `=` 开头的字符串重新推断为 `FormulaCell`。

在修改断言前直接检查真实导出的文件，确认 M2 和 N2 的类型都是 `inlineStr`，整张 worksheet 的公式节点 `<f>` 数量为 0，原始文本完整。随后把断言改为检查实际 XML：身份列必须为字符串类型、不能有公式节点、解码值必须与预期相等，同时保留读取器的值相等断言。最终 20 项通过，未修改生产代码或降低验收要求。

这是测试自身的错误假设得到修正，不是产品缺陷的 Red 到 Green；初始失败日志和原始 XLSX 样本均保留。

### 静态检查记录脚本

首轮临时记录脚本发现本机两个 `git.exe`，在定位最后一条检查命令时失败。选择 PATH 首项后完整重跑，70 条检查均通过。首轮脚本和错误记录保留；该问题没有导致仓库代码修改，也未执行部署脚本。

## 未执行项和剩余边界

| 项目 | 状态和原因 | 后续完成条件 |
| --- | --- | --- |
| MySQL 5.7 与 8.4 集成矩阵 | NOT RUN，本机 PHP 缺 `pdo_mysql`，Docker 引擎不可连接 | 在隔离测试环境执行本方案 SN19 及仓库 CI 数据库矩阵 |
| Karing 实际客户端 | NOT RUN，本轮未连接运行新版本的测试实例和实际客户端 | 记录版本并完成普通及最长新名导入、旧订阅更新与续费、扫码验收 |
| FlClash 实际客户端 | NOT RUN，同上 | 同上 |
| Clash Verge 实际客户端 | NOT RUN，同上 | 同上 |
| 完整 SPA 及已部署资源 | NOT RUN，浏览器使用本地增强脚本、CSS 和接口夹具 | 在候选实例验证完整管理端和用户端页面及资源版本 |
| 真实二维码解码 | NOT RUN，浏览器二维码为占位图；后端验证接口与链接但未解码图像 | 真实测试二维码扫码后核对链接和导入名称 |
| 行及分支覆盖率 | 未测量，缺 Xdebug 和 PCOV | 在具备覆盖率驱动的测试环境采集 |

浏览器脚本监听 `pageerror`，没有监听全部 console 事件；日志文字中的 console 不能解释为完整控制台错误检查。SQL 和实际 XLSX 内容通过后端测试验证，浏览器未执行真实搜索和下载链。

## 证据和任务状态

后端与静态证据目录：`C:/Temp/xboard-subscription-qa-20261006-182356`。

- `naming-baseline.log`、`naming-baseline.junit.xml`、`naming-baseline.json`：原 17 项基线。
- `naming-expanded.log`、`naming-expanded.junit.xml`、`naming-expanded.result.json`：最终 20 项专项。
- `phpunit-full.log`、`phpunit-full.junit.xml`、`phpunit-full.json`：完整回归。
- `naming-expanded-initial.*`：首次测试误判的失败证据。
- `naming-reader-diagnostic.xlsx`、`naming-reader-diagnostic-sheet.xml`：实际导出类型核验。
- `phpstan.*`、`composer-audit.*`、`environment.json`：静态检查和环境。
- `release-checks.ps1`、`release-checks-results.json`、`release-checks-output.log`、`release-checks-attempt1*`：可复现静态检查和首轮记录。

前端独立证据目录：`C:/Temp/xboard-subscription-frontend-20261006-01a110bd`。

- `frontend-verification-report.txt`：逐条断言和证据边界。
- `javascript.log`、`javascript-result.json`、`browser.log`、`browser-result.json`：原始日志、命令与精确时间。
- `browser/xboard-subscription-longest-qr.png`：最长名称合成图，已独立目视核对；图中明确标注二维码占位区，不是整页截图或扫码证明。
- `source-verification.json`：36 个相关文件执行前后哈希一致。

任务 QA01 至 QA07 已完成；QA08 保持 NOT RUN。本轮不改业务实现，原有 `public/assets/admin`、`.codex-browser-fixtures/` 和 `subscription-redesign-demo.html` 保留。
