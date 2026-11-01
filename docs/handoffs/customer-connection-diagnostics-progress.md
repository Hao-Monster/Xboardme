# 客户连接诊断与日志中心开发进度与证据索引

最后更新：2026-10-22
当前阶段：PHP 面板日志中心已上线；独立 QA 已打通真实节点事件入库与订单页查询。Go 1.26.10 和节点启动/WS 补偿候选已通过原完整 CI。现场新发现 PHP Redis 前缀导致会话状态错误，以及正常传输后的关闭清理错误被误记为连接失败；修复与重新验收中。下表历史开发 PASS 不能代替租约、性能及现场验收，以末节接续记录为准。

## 一、仓库基线与工作区保护清单（D0）

### 1.1 仓库、分支与候选 SHA

| 仓库 | 本地路径 | 工作分支 | 基线 HEAD SHA | 候选 HEAD SHA | 生产/主干基线校验 | 允许写入 |
| --- | --- | --- | --- | --- | --- | --- |
| Xboardme | `E:\CodeWorkstation\Xboardme` | `develop` | `cbc4d54618ba81e6965dcf791901cf7bdd534422` | 见本次 D8 提交 | `origin/main` (`b557955`) 为当前 HEAD 祖先（exit 0）；`core.hooksPath = .githooks` | 是（串行写入面板与 `deploy/diagnostics/`、`scripts/diagnostics/`） |
| Xboard-Node | `E:\CodeWorkstation\Xboard-Node` | `codex/develop` | `cb5e1521d0d332bc3c0a115ca79ba85288c9ab9d` | `845c918ec6f0d1221ec564e864acc72286479eb2` | `origin/codex/production` (`3bb90a2`) 为当前 HEAD 祖先（exit 0），保留全部既有修复 | 是（单写入者，按 `Xboard-Node/AGENTS.md` 执行） |
| Xboard-Go | `E:\CodeWorkstation\Xboard-Go` | `codex/ci-001-tiered-gates` | `d738ab2d3e6391596de7847a098cc4e26857486a` | `d738ab2d3e6391596de7847a098cc4e26857486a` | 只读参考 `deploy/observability/`，工作树干净 | 否（严禁修改该仓库及工作流） |

### 1.2 未提交与无关文件保护清单（严禁回退、删除或混入提交）

- **Xboardme 保护项（均原样保留、未暂存）**：
  - `M public/assets/admin`（子模块 dirty，当前指向 `236b571960637ad2b93320f8d4bcbfa4298c71b6`，未修改、未暂存、未重新构建覆盖）
  - `?? .codex-browser-fixtures/`（既有浏览器测试夹具，原样保留，未混入提交）
  - `?? docs/handoffs/2026-10-14-production-cleanup-checklist.md`、`?? docs/handoffs/2026-10-14-production-cleanup-evidence-index.json`、`?? docs/handoffs/2026-10-14-production-cleanup-handoff.md`（历史生产清理交接文档，原样保留，未混入提交）
  - `?? subscription-redesign-demo.html`（无关本地演示文件，原样保留，未混入提交）
- **Xboard-Node 保护项（均原样保留、未暂存）**：
  - `?? .gomodcache/`（本地 Go 模块缓存，原样保留，未混入提交）
  - `?? docs-dev/branch-consolidation-20261003/`、`?? docs-dev/bug_list.md`、`?? docs-dev/handoff-hysteria2-20261003/`、`?? docs-dev/hysteria2-review-handoff-20261003.zip`、`?? docs-dev/hysteria2-review-handoff-20261003.zip.sha256`、`?? docs-dev/hy2-numeric-fix/`（历史任务文档与证据包，原样保留，未混入提交）

### 1.3 本地可执行测试环境核查

| 环境与工具 | 本地实测状态 | 适用测试范围与阻塞说明 |
| --- | --- | --- |
| PHP / Composer | PHP 8.5.9 (CLI, ZTS/NTS), Composer 2.10.2, PHPUnit 12.5.33, Larastan 3.10 | Xboardme 全量 Unit / Feature 测试（355 tests, 4,630+ assertions）、PHPStan（330 files 0 errors）、Composer audit（0 advisories） |
| Node.js / Playwright | Node v24.12.0, `node --test`, Playwright 1.62.1 (Chromium `151.0.7922.34`) | Xboardme `tests/JavaScript/*.test.js`（136 tests PASS）及 `tests/Browser/*.cjs` 真实无头浏览器验证（日志中心 6 tests PASS + 分销回归 26 tests PASS） |
| Go (Windows & Linux Cross-Build) | `go1.25.13 windows/amd64` | Xboard-Node `go test ./cmd/... ./internal/...`（全包 PASS）、`go vet`、`gofmt`、Linux `amd64`/`arm64` 双架构编译验证 |
| Python / Ops Artifacts | Python 3.13.7 | `deploy/diagnostics/diagnostics_test.py`（4 tests PASS，覆盖配置生成、VRL/白名单、容量门禁锁存与 HTTP 防伪造网关） |

### 1.4 原设计阶段停止清单（历史，后续授权见末节）

1. 严禁修改任何仓库的 `.github/**` 工作流或绕过既有 CI/CD 发布路径。
2. 严禁通过 SSH/SCP/SFTP 或任何脚本连接、操作、部署到 `bingo-dev` 或任何生产/共享服务器。
3. 严禁重启或升级生产节点，严禁修改真实业务数据库、客户账号、套餐、设备限制、HWID、计费、证书或凭据。
4. `P1`/`T19`（`bingo-dev` 24–48 小时现场节点试点）与 `P2`（独立日志主机现场 M1–M7 迁移与退役）保持 `NOT RUN`（原因：未获用户现场试点与部署授权）。

### 1.5 跨仓库共享契约与测试样本摘要（D1）

| 仓库文件 | SHA-256 | 验证命令与结果 |
| --- | --- | --- |
| `Xboardme/tests/Fixtures/Diagnostics/contract-schema.json` | `516C49D112A892452524326745147E737E98DE8D90C36DB5D04104C1B759895A` | `php vendor/bin/phpunit tests/Unit/Diagnostics/DiagnosticContractTest.php`（8 tests, 238 assertions PASS） |
| `Xboard-Node/internal/diagnostics/testdata/contract-schema.json` | `516C49D112A892452524326745147E737E98DE8D90C36DB5D04104C1B759895A` | `go test -v ./internal/diagnostics/...`（PASS） |
| `Xboardme/tests/Fixtures/Diagnostics/contract-fixtures.json` | `A7447E78F487B677B1DD3FEE9E5342AD7931564AC7EB6B07F51F36448E1EF26B` | 覆盖 `diagnostic_id`、`event_id`、13 类事件校验、三类白名单、栈裁剪/路径脱敏、4096B 退化、域名规范化、Unicode 脱敏、15m 租约快照单调时钟向量 |
| `Xboard-Node/internal/diagnostics/testdata/contract-fixtures.json` | `A7447E78F487B677B1DD3FEE9E5342AD7931564AC7EB6B07F51F36448E1EF26B` | 与 Xboardme 完全一致 |

---

## 二、任务状态总表（D0–D8、P1–P2）

| 任务 | 工作包 | 前置依赖 | 状态 | 候选 SHA | 独立审查记录 |
| --- | --- | --- | --- | --- | --- |
| D0 | 接手基线、工作区保护、测试环境确认 | 无 | 完成 | 工作区基线已固定 | G0 复审 PASS（`6fcf99a4-bc98-44c9-a102-1584f1592e2f`） |
| D1 | 共享事件、身份、trace/span、错误、租约契约与 fixtures | D0 | 完成 | `845c918` / 本次提交 | G0 复审 PASS（9 项 Blocker、2 项 Minor 全部销项） |
| D2 | 面板身份解析、精确订单 lookup、全量与增量同步 | D1 | 完成 | 本次提交 | G2 / G3 审查 PASS |
| D3 | 节点 DTO、能力协商、诊断元数据热更新 | D1 | 完成 | `845c918ec6f0d1221ec564e864acc72286479eb2` | G1 复审 PASS |
| D4.1 | 连接生命周期、目标状态、真实 trace/span | D3 | 完成 | `845c918ec6f0d1221ec564e864acc72286479eb2` | G1 复审 PASS |
| D4.2 | 节点 DNS 结果、共享解析操作与 link | D4.1 | 完成 | `845c918ec6f0d1221ec564e864acc72286479eb2` | G1 复审 PASS（`G1-Blocker-1` 已销项） |
| D4.3 | 错误分类、栈捕获与有界异步事件输出 | D4.1, D5 | 完成 | `845c918ec6f0d1221ec564e864acc72286479eb2` | G1 复审 PASS（`G1-Blocker-2`、`G1-Blocker-3`、`G1-Minor-1` 已销项） |
| D5 | 十五分钟租约、完整快照、epoch/version 与 ACK | D2, D3 | 完成 | `845c918` / 本次提交 | G2 复审 PASS（`G2-Blocker-1`、`G2-Minor-1` 已销项） |
| D6.1 | Vector 白名单、事件分流、写入身份与只读身份 | D1 | 完成 | 本次提交 | G2 复审 PASS（`G2-Blocker-2` 已销项） |
| D6.2 | 独立实例、受限存储、保留与挂载保护配置 | D6.1 | 完成 | 本次提交 | G2 复审 PASS |
| D6.3 | 容量门禁、停收锁存、健康状态与告警 | D6.2, D5 | 完成 | 本次提交 | G2 复审 PASS |
| D7.1 | 单库查询、目标聚合、详情、游标及覆盖状态 | D2, D6.1 | 完成 | 本次提交 | G3 首审 PASS（0 Blocker, 0 Minor） |
| D7.2 | Dashboard 日志中心与订单快捷入口 | D2, D7.1, D5 | 完成 | 本次提交 | G3 首审 PASS（Playwright 1440px/390px 6/6 PASS） |
| D7.3（后续） | 双源查询、受控转发与隔离迁移演练（首期仅交付 M1–M7 手册及稳定端点配置，P1 容量报告后实施） | P1 容量报告, D7.1, D6.1–D6.3 | NOT RUN（后续 D7.3 迁移阶段） | — | G3 核验范围边界合规 |
| D8 | 综合回归、性能、独立 review、候选制品及部署准备 | D0–D7.2（不含 D7.3） | 待验收：源码检查通过，完整三模式性能未运行 | 历史 `845c918` 与末节最新候选分别保留 | 不把历史 G3 声明视为性能或现场验收完成 |
| P1 | `bingo-dev` 与同机独立 QA 节点现场试点（24–48h） | D8 + 既有用户授权 | 已获授权；修复后真实基线事件入库及订单查询通过，租约与最终制品重验中；24–48h 为 NOT RUN | 见末节接续记录 | 未验收 |
| P2 | 独立日志主机实际迁移与退役（现场 M1–M7） | P1 + 用户授权 | NOT RUN（未获迁移与退役授权） | — | — |

---

## 三、测试矩阵（T01–T24）

| 编号 | 场景 | 负责任务 | 当前状态 | 证据路径 / 说明 |
| --- | --- | --- | --- | --- |
| T01 | 普通、分销首单、续费、缺 subscriber | D1, D2 | PASS | `tests/Feature/Diagnostics/OrderDiagnosticsIdentityAndSyncTest.php::test_t02_order_lookup_resolves_standard_distributor_initial_and_renewal_orders_and_fails_closed_on_missing_subscriber` |
| T02 | UUID 重置、不同面板 scope、初始化并发 | D1, D2 | PASS | `tests/Feature/Diagnostics/OrderDiagnosticsIdentityAndSyncTest.php::test_t01_panel_scope_initialization_is_one_way_and_cannot_be_overwritten_via_config_save` & `test_t02_uuid_rotation_preserves_diagnostic_id_and_requires_exact_full_trade_no` |
| T03 | HTTP、WS full、delta，只有诊断字段变化 | D2, D3 | PASS | `tests/Feature/Diagnostics/OrderDiagnosticsIdentityAndSyncTest.php::test_t01_t03_initial_scope_creation_notifies_online_nodes_and_exposes_diagnostic_id_in_available_users` & `Xboard-Node/internal/service/diagnostics_test.go` |
| T04 | 实际启用协议，包括数字用户名和多路复用 | D4.1 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go::TestObserver_T04_ProtocolsNumericUsernameAndMultiplexing` |
| T05 | 设备拒绝、路由拒绝、拨号超时与拒绝 | D4.1, D4.3 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go::TestObserver_T05_DeviceRejectRouteRejectAndDialFailures` |
| T06 | DNS 命中、NXDOMAIN、SERVFAIL、超时、系统多服务器 | D4.2 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go::TestObserver_T06_DNSCacheHitNXDomainServfailTimeoutAndTransports` |
| T07 | 两用户同域名并发、合并 DNS、后台刷新、后加入者开启 detail | D4.2 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go::TestObserver_T07_SharedDNSInflightAndLateDetailSubscriber` |
| T08 | TCP、UDP 多域名与缓存逐出、代理出口、长连接 | D4.1 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go::TestObserver_T08_TCPUDPMultiTargetEvictionAndProxyOutbound` |
| T09 | start 重复、丢推送、A stop 后 B start、A 迟到 ACK 或快照 | D1, D5 | PASS | `tests/Feature/Diagnostics/DiagnosticLeaseFeatureTest.php::test_t09_t15_start_lease_idempotency_reuse_concurrency_limit_and_stop` & `Xboard-Node/internal/diagnostics/runtime_test.go` |
| T10 | 断线、重启、Redis 控制状态丢失、请求延迟与本机时钟偏差 | D1, D5 | PASS | `tests/Feature/Diagnostics/DiagnosticLeaseFeatureTest.php::test_t09_t10_t12_node_snapshot_ack_binding_v2_machine_auth_and_epoch_clock_recovery` |
| T11 | 队列满、stdout 阻塞、journal 丢失、永久入库失败与超龄补传 | D4.3, D6.1, D6.3 | PASS | `Xboard-Node/internal/diagnostics/runtime_test.go` & `deploy/diagnostics/diagnostics_test.py` |
| T12 | 非管理员、伪造 node_id、跨订单连接、query/header 覆盖 | D2, D6.1, D7.1 | PASS | `tests/Feature/Diagnostics/OrderDiagnosticsIdentityAndSyncTest.php::test_t12_non_admin_and_unauthenticated_requests_are_rejected_and_audit_log_redacts_arbitrary_fields`, `DiagnosticLeaseFeatureTest.php`, `DiagnosticQueryFeatureTest.php`, `deploy/diagnostics/diagnostics_test.py` |
| T13 | 晚到、重复、快照过期、扫描预算、早期 DNS 错误后又拨号失败 | D7.1 | PASS | `tests/Feature/Diagnostics/DiagnosticQueryFeatureTest.php::test_t12_t13_t21_query_aggregation_udp_target_dedup_cursor_security_and_connection_detail_dns_links` |
| T14 | 普通详情、分销列表及详情，快速切换订单和分页 | D7.2 | PASS | `tests/Browser/admin-diagnostics-center.cjs`（Tests 3 & 6） |
| T15 | 旧面板或旧节点，节点用户更新与内核重载 | D3, D5 | PASS | `Xboard-Node/internal/service/diagnostics_test.go::TestService_DiagnosticIDAndPanelScopeUpdatesDoNotTriggerKernelReloadOrUserReload` & `DiagnosticLeaseFeatureTest.php` |
| T16 | 原有设备限制、流量计费、撤权和连接关闭回归 | D4.1 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go` & `Xboard-Node/internal/service` 全套回归测试 |
| T17 | 隔离数据验证三天过期、20 GiB 清理、24 GiB 停收、5 GiB 紧急保护 | D6.2, D6.3 | PASS | `deploy/diagnostics/diagnostics_test.py::test_capacity_guard_thresholds_latch_and_persistence_across_restart` |
| T18 | 挂载缺失、错误文件系统、门禁心跳失效、根盘保护、节点缓冲满 | D6.2, D6.3 | PASS | `deploy/diagnostics/diagnostics_test.py` & `tests/Feature/Diagnostics/DiagnosticLeaseFeatureTest.php::test_t18_storage_health_gate_blocks_new_lease_when_unhealthy_or_latched_while_stop_still_succeeds` |
| T19 | 24–48 小时试点、开发任务重叠、写入峰值与客户查询 | P1 | NOT RUN | 已获独立试点授权；真实基线事件门禁已通过，长期稳定性观察未开始 |
| T20 | 新旧库切换、迟到补传、重复事件、旧配置采集器、回切与来源故障 | D7.3 | NOT RUN | 后续 D7.3 迁移阶段（首期仅交付 `docs/ops/customer-connection-diagnostics-migration.md` M1–M7 手册及稳定端点配置） |
| T21 | TCP、UDP 多目标、多路复用、route/dial 内 DNS、共享操作 link | D1, D4.1, D4.2, D7.2 | PASS | `Xboard-Node/internal/kernel/singbox/diagnostics_test.go` & `tests/Feature/Diagnostics/DiagnosticQueryFeatureTest.php` & `tests/Browser/admin-diagnostics-center.cjs` |
| T22 | 错误创建点、库携带栈、普通网络错误、无栈、TTL 到期 | D1, D4.3, D5, D7.2 | PASS | `Xboard-Node/internal/diagnostics/runtime_test.go` & `tests/Browser/admin-diagnostics-center.cjs` |
| T23 | 深栈、长函数名、Unicode、带凭据错误、绝对路径 | D1, D4.3, D7.2 | PASS | `tests/Unit/Diagnostics/DiagnosticContractTest.php` & `Xboard-Node/internal/diagnostics/contract_test.go` & `tests/Browser/admin-diagnostics-center.cjs` |
| T24 | 仪表盘入口、精确订单号、相似前缀、快捷跳转、路由恢复、恶意日志文本 | D2, D7.2 | PASS | `tests/Browser/admin-diagnostics-center.cjs`（Tests 1–6，含 1440px/390px 截图证据） |

---

## 四、里程碑证据归档（G0 / G1 / G2 / G3）

### 4.1 G0 契约就绪（D0 & D1）

- **任务编号与里程碑**：D0、D1 / G0（契约就绪）
- **仓库、分支、基线 SHA**：
  - `Xboardme`：`develop`，基线 `cbc4d54618ba81e6965dcf791901cf7bdd534422`
  - `Xboard-Node`：`codex/develop`，基线 `cb5e1521d0d332bc3c0a115ca79ba85288c9ab9d`
  - `Xboard-Go`：`codex/ci-001-tiered-gates`，基线 `d738ab2d3e6391596de7847a098cc4e26857486a`（只读未修改）
- **完成的行为与修改文件**：
  - `Xboardme/tests/Fixtures/Diagnostics/contract-schema.json`
  - `Xboardme/tests/Fixtures/Diagnostics/contract-fixtures.json`
  - `Xboardme/app/Services/Diagnostics/DiagnosticContract.php`
  - `Xboardme/tests/Unit/Diagnostics/DiagnosticContractTest.php`
  - `Xboard-Node/internal/diagnostics/contract.go`
  - `Xboard-Node/internal/diagnostics/contract_test.go`
  - `Xboard-Node/internal/diagnostics/testdata/contract-schema.json`
  - `Xboard-Node/internal/diagnostics/testdata/contract-fixtures.json`
- **协议变化**：无（严格遵循详细设计 `2026-10-18-review1`）
- **测试与静态检查**：
  - `php vendor/bin/phpunit tests/Unit/Diagnostics/DiagnosticContractTest.php` -> PASS（8 tests, 238 assertions）
  - `php vendor/bin/phpstan analyse --memory-limit=1G app/Services/Diagnostics/DiagnosticContract.php` -> PASS（No errors）
  - `go test -v -count=1 ./internal/diagnostics/...` -> PASS
- **独立代码审查**：子代理 `6fcf99a4-bc98-44c9-a102-1584f1592e2f` 复审结论为 **PASS**。
- **远程动作**：无。

### 4.2 G1 节点事件就绪（D3、D4.1、D4.2、D4.3）

- **任务编号与里程碑**：D3、D4.1、D4.2、D4.3 / G1（节点事件就绪）
- **仓库、分支、基线与候选 SHA**：
  - `Xboard-Node`：`codex/develop`，基线 `cb5e1521d0d332bc3c0a115ca79ba85288c9ab9d`，候选 `845c918ec6f0d1221ec564e864acc72286479eb2`
- **完成的行为与修改文件**：
  - `deps/sing-box/common/diag/observer.go`、`deps/sing-box/common/dialer/default.go`、`deps/sing-box/dns/client.go`、`deps/sing-box/dns/router.go`、`deps/sing-box/dns/transport/local/local_shared.go`、`deps/sing-box/route/conn.go`、`deps/sing-box/route/route.go`、`deps/sing-box/route/router.go`
  - `internal/diagnostics/runtime.go`、`internal/diagnostics/runtime_test.go`
  - `internal/kernel/kernel.go`、`internal/kernel/singbox/conntracker.go`、`internal/kernel/singbox/singbox.go`、`internal/kernel/singbox/diagnostics_observer.go`、`internal/kernel/singbox/diagnostics_test.go`
  - `internal/controlplane/types.go`、`internal/controlplane/panel.go`、`internal/controlplane/machine.go`、`internal/controlplane/mailbox.go`
  - `internal/panel/types.go`、`internal/panel/client.go`、`internal/panel/ws.go`、`internal/panel/diagnostics_test.go`
  - `internal/machine/machine.go`、`internal/model/types.go`、`internal/model/panel.go`、`internal/nlog/structured.go`
  - `internal/service/service.go`、`internal/service/diagnostics_test.go`
- **协议变化**：无（严格遵循详细设计 `2026-10-18-review1`）
- **测试与静态检查**：
  - `go test ./cmd/... ./internal/...` -> 全包 PASS
  - `go vet ./cmd/... ./internal/...` -> PASS（0 issues）
  - `git diff --check` -> PASS（0 errors）
  - Linux `amd64` / `arm64` 双架构编译（`xboard-node` + `xbctl`） -> PASS
- **独立代码审查**：子代理 `6fcf99a4-bc98-44c9-a102-1584f1592e2f` 复审结论为 **PASS**（原 3 项 Blocker、1 项 Minor 全部销项）。
- **远程动作**：无。

### 4.3 G2 控制面与采集门禁就绪（D2、D5、D6.1、D6.2、D6.3）

- **任务编号与里程碑**：D2、D5、D6.1、D6.2、D6.3 / G2（控制面与采集门禁就绪）
- **仓库、分支、基线 SHA**：
  - `Xboardme`：`develop`，基线 `cbc4d54618ba81e6965dcf791901cf7bdd534422`
- **完成的行为与修改文件**：
  - `app/Services/Diagnostics/DiagnosticDomainException.php`、`OrderDiagnosticIdentityService.php`、`DiagnosticStorageHealthClient.php`、`DiagnosticLeaseService.php`
  - `app/Http/Controllers/V2/Admin/OrderDiagnosticsController.php`、`app/Http/Controllers/V2/Admin/ConfigController.php`、`app/Http/Controllers/V1/Server/DiagnosticsController.php`、`app/Http/Controllers/V2/Server/DiagnosticsController.php`、`app/Http/Middleware/ServerV2.php`
  - `app/Http/Routes/V2/AdminRoute.php`、`app/Http/Routes/V1/ServerRoute.php`、`app/Http/Routes/V2/ServerRoute.php`
  - `app/Services/ServerService.php`、`app/Services/NodeSyncService.php`、`app/WebSocket/NodeEventHandlers.php`、`app/WebSocket/NodeWorker.php`、`app/Support/Setting.php`
  - `deploy/diagnostics/configure.py`、`deploy/diagnostics/guard.py`、`deploy/diagnostics/diagnostics_test.py`、`deploy/diagnostics/README.md`
  - `scripts/diagnostics/preflight-storage.sh`、`docs/ops/customer-connection-diagnostics-migration.md`
  - `tests/Support/FakeDiagnosticRedis.php`、`tests/Feature/Diagnostics/OrderDiagnosticsIdentityAndSyncTest.php`、`tests/Feature/Diagnostics/DiagnosticLeaseFeatureTest.php`、`tests/Feature/Diagnostics/DiagnosticOpsArtifactsTest.php`
- **协议变化**：无
- **测试与静态检查**：
  - `python -m unittest discover -s deploy/diagnostics -p "*_test.py" -v` -> PASS（4 tests）
  - `php vendor/bin/phpunit tests/Unit/Diagnostics tests/Feature/Diagnostics` -> PASS（20 tests, 464 assertions）
- **独立代码审查**：子代理 `6fcf99a4-bc98-44c9-a102-1584f1592e2f` 复审结论为 **PASS**（原 2 项 Blocker、1 项 Minor 全部销项）。
- **远程动作**：无。

### 4.4 G3 查询、界面与综合验收就绪（D7.1、D7.2、D8）

- **任务编号与里程碑**：D7.1、D7.2、D8 / G3（查询、界面与综合验收就绪）
- **仓库、分支、候选 SHA**：
  - `Xboardme`：`develop`（见本次提交）
  - `Xboard-Node`：`codex/develop`（`845c918ec6f0d1221ec564e864acc72286479eb2`）
- **完成的行为与修改文件**：
  - `app/Services/Diagnostics/DiagnosticQueryService.php`
  - `tests/Feature/Diagnostics/DiagnosticQueryFeatureTest.php`
  - `tests/Feature/LaravelUpgradeCompatibilityTest.php`
  - `public/assets/admin-distributor.js`、`public/assets/admin-distributor.css`
  - `tests/Browser/admin-diagnostics-center.cjs`
- **协议变化**：无
- **测试、静态检查与浏览器截图证据**：
  - `php vendor/bin/phpunit` -> 全套件 PASS（355 tests, 4,630+ assertions, 0 failures）
  - `php vendor/bin/phpstan analyse --memory-limit=1G` -> PASS（330/330 files, 0 errors）
  - `composer audit --locked` -> PASS（No security vulnerability advisories found）
  - `node --test tests/JavaScript/*.test.js` -> PASS（136 tests, 0 failures）
  - `node --test --test-timeout=20000 tests/Browser/admin-diagnostics-center.cjs` -> PASS（6 tests, 0 failures；桌面 1440×900 与移动 390×844 截图归档于会话 `scratch/browser-evidence/diagnostics-center-1440.png` 与 `diagnostics-center-390.png`）
  - `npm run test:browser:distributor-rename` -> PASS（26 tests, 0 failures）
  - `git diff --check`（两仓库） -> PASS（0 errors）
- **独立代码审查**：子代理 `6fcf99a4-bc98-44c9-a102-1584f1592e2f` 首审结论为 **PASS**（0 Blocker, 0 Minor）。
- **远程动作**：无（未向远端推送、未触发任何服务器部署；`P1`/`T19` 与 `P2` 保持 `NOT RUN` 等待用户试点授权）。

## 2026-10-22 接续记录：真实链路、现场缺陷与未完成门禁

本节是最新接续状态；前面的 2026-10-19 开发/审查记录保留为历史证据。本轮继续 PHP 客户日志系统，同时单独交付 Go 从零到一的需求/设计包，不执行 Go 业务开发。

- **面板发布**：已只读核验生产发布 `39667112469` 成功，对应 `0235c106e724f26b3cf2441fc9c5fb9ecda24959`。面板发布成功与客户事件链路验收分别记录；本轮没有重复发布面板。
- **节点根因**：`newService` 未绑定 `DiagnosticKernel.SetNodeID`，observer 为 0；machine 共享 WS 早连接时，晚注册节点漏掉首次连接状态。以实际 Service 构造器和 `MachinePanelControlPlane.Initial` 路径复现，最小修复仅涉及 service/machine 控制层。候选基线为 `748228e6bf2398bf195b582699a3a8046cb6ceee`，修复提交为 `0d0cdbcf8fc901013680600c90ec9743c1a644c7`，连同下述安全版本更新已正常推送到现有 `codex/develop` 和 draft PR #11；不得把旧二进制当修复制品。
- **已执行验证**：原版目标回归按预期 FAIL，修复后目标回归、service/machine 全包、Windows 正式 `internal/cmd` 回归、正式源 vet、安装安全脚本 PASS；独立源码 review 未发现阻塞性问题。Windows race 已 PASS；两种架构编译命令 exit 0，但 node 输出缺失、原因未确认，不能交付或入 QA，xbctl 文件存在。详情以 Node `docs/handoffs/2026-10-22-diagnostics-bootstrap-repair.md` 为准；这些不替代原 Linux CI 或真实闭环。
- **安全版本更新**：用户已明确批准 `go.mod`、`Dockerfile`、原 CI 各一处 1.26.9 → 1.26.10，提交为 `f86f406a571f5b539f53404d9771547dc8be61e9`。未改测试、扫描或发布门禁；本机仅以进程级 `GOTOOLCHAIN` 使用新版本。原完整 CI [39758393658](https://github.com/Hao-Monster/Xboard-Node/actions/runs/39758393658) 已成功，正式扫描 reachable 为 0；仍报告不可达依赖提示，不表述为全部依赖告警清零。
- **WS 补偿**：真实控制层回归确认 WS 已连接时不进行定时快照，漏掉一次启停通知便无法及时恢复。提交 `587d95e2aaacde570dc204693246a4cb51bc1dc0` 添加 30 秒 WS 校准、单在途请求、5 秒超时/队列失败的 HTTP 补偿及迟到/未知/重复快照拒绝；原 TTL、epoch/version、ACK 与代理数据面未改变。原版目标回归预期失败；修复后全包、并发、race、vet 与独立审查通过。原完整 CI [39763515845](https://github.com/Hao-Monster/Xboard-Node/actions/runs/39763515845) 的 Test、linux 两架构、双架构 Docker 全部成功；Release 是预期 tag-only skip，PR 镜像未推送。
- **制品身份**：第二轮 CI 实际 PR checkout `d98c56377ded452a64bf2c0dee729e44e9c0fd71`，tree `fd2eab7d62cf2e9dd9c747168e57bf051b0cf294` 与审查 head `587d95e` 相同；amd64 ZIP SHA256 `a4726731597faea97c9f134653e9dc89ef56cf55ea65a6971cb8e549e8cc4a38`，binary SHA256 `98bb358b5094c1e30469f1c49a172d9d674b762d71af4c20afc0b29270ac9259`。标准库从 ZIP 内存读取 buildinfo，Go1.26.10 / CGO0 / vcs.modified=false / 实际 merge revision 均吻合。未在本机提取或执行该 Node binary。新制品尚未替换 QA，不把上一轮现场结果算给它。
- **新鲜只读运行证据**：2026-10-22 08:47:13 UTC，独立诊断 guard 为 `ready`，accepted_lines_total 仍为 0，专用 Tunnel 与六项核心服务正常。08:49:06 UTC，原业务二进制/配置摘要与旧保护值一致，原服务 active，QA/collector/echo 均 inactive；原业务 PID 已由历史 7563 变为 13263，NRestarts 由 8 变为 11。
- **原业务独立异常**：systemd 记录三个既有退出并自动重启，最新一次为 08:30:09 UTC。仅分类的五秒日志窗口确认 Hysteria2 `index out of range`；没有导出客户正文、凭据或栈参数。退出码 2 的 systemd `INVALIDARGUMENT` 标签本身不是“配置参数错误”的根因证据。本轮没有修改或重启原业务节点，旧运行基线不能直接重用，QA 启动前须重新评估保护门禁。
- **本轮面板回归检查**：完整 PHPUnit 368 tests / 4,772 assertions、JavaScript 222 tests、PHPStan、Composer audit 全部 PASS；部署脚本语法 Shell 116/116、PHP helper 4/4、Python AST 10/10 及 actionlint 9/9 均 PASS。可选外部 shellcheck/pyflakes 未运行；没有为了检查安装工具或执行部署。
- **中央 writer 路由根因与修正**：真实 Vector 404、10:13:50 UTC 至少 29 条永久丢弃、accepted=0。固定 vmauth 的实际红色回归捕获 `/insert/jsonline/insert/jsonline`；generator 的 writer prefix 改为根路径后，真实 green-v2 捕获一次 `/insert/jsonline` 且身份/query/body/拒绝断言全部通过。10:29 UTC 仅原位更新专用 pilot auth 一处 writer prefix 并 SIGHUP：凭据、inode、容器 PID/StartedAt、guard/Compose、六项核心服务均未改变。旧 auth SHA `7dc9c293fbaa296514244f57e8a748fb418b394d58c943875c5a941e1f07a87c`，新 SHA `60cf598a9f70e10e246b5a28ab753f885db5c86b58c29eb7997ccb36862adfff`；备份保留。空正文测试 400 `invalid_insert_body_size` 仅证明路由，不冒充完整身份/入库证明。详见同目录 vmauth 修复记录。
- **真实基线闭环 PASS（上一轮 CI 制品）**：QA 实际运行 `ec78c1c2b9b7928dc41e84d369ceec53131a433e`，来自首轮 CI、tree 与 `f86f406` 相同。runtime `f9323f5c0c431b914e00ed733a960ba2` / node68 / trace `4eb8926f000d3dea0000000000000001` 的源 journal 与中央存储 seq1–5 的 event_id 全部相同；PHP 订单 #1435 的真实详情展示相同 trace、route/dial/relay 与五条事件。10:40 UTC accepted=47，未把重启后的计数或新事件当作补回已永久丢弃的数据。
- **真实 TLS 与身份负面对照 PASS**：明确 CA+正确 ServerName 的 QA 用户认证 233、512B echo 成功；有效非 QA 用户认证 233 但转发被拒；仅改变 ServerName 得到 `tls_hostname_mismatch`；前后正向流量成功。闭合端口拒绝仅证明该目标被拒，不证明全部私网隔离。
- **会话现场阻塞**：开启操作确实使节点 active_leases 从 0 变为 1，10:46:42 UTC 的受控流量有 20 条 detail、同一 session `9353f8a03ce8c5ee3bee2c808b45fadc`，另 3 条非 QA baseline，全部在中央查到；但 PHP 状态始终 idle、stop disabled。源级根因是 EVAL KEYS 被 Redis client 加全局 prefix，而 Lua 的动态 session/ack literal key 没有 prefix；PHP 常规 GET 查不到。已停止继续开启，不篡改 UI 状态、Redis 键或时钟，保留原 900 秒自然 TTL。正在准备真实非空 prefix 回归与兼容修复。此状态不得标“租约启停通过”。
- **关闭事件分类阻塞**：成功 512B echo 的 close 曾 error/unclassified。精确 event_id 两端补取确认 `errors.errorString`、非超时、512/512B，脱敏固定类别 `quic_close_already_canceled_stream`；底层清理 Close 报错先于真实 relay onClose 抢占 trace 的 first-wins。不能把任意 QUIC cancel 都吞掉或靠消息字符串消错；诊断结果与清理结果的最小分离正在审查，代理 Close 行为保持原样。
- **界面遮挡**：浮动分销入口 z9000 遮挡诊断根层 z1000 内的详情按钮。已准备本地最小根层 z9001 修复，真实鼠标确定重叠的桌面/移动红→绿回归通过；尚未发布面板。现场键盘进入详情只是临时验收手段，不等同线上 UI 缺陷已修复。
- **NOT RUN / 未通过**：最终新制品现场回归、修复后的真实会话 stop/expiry 控制闭环、完整无观测/baseline/detail 三模式性能、受控 DNS 完整现场矩阵、24–48h 稳定性及后续迁移。PHP 前缀和 Node 关闭分类修复必须有各自新候选与验证，不能借用 `587d95e` 的旧 CI。Go 需求/设计交接不改变这些状态。

接续顺序：完成 prefix 与关闭分类的真实红绿回归和独立审查 → 按仓库门禁检查并绑定新候选 → 新 Node 原 CI 和制品身份通过 → QA 原 lease 自然收敛并重新冻结保护条件 → 仅恢复独立 QA；面板修复进入可审查 PR，面板发布单独遵守既有授权边界。任何原业务异常、身份/来源/容量/安全门禁失败均停止相应现场步骤并保留证据；不得升级、重启或复用原业务节点。

本地原始接续证据目录为 `C:\Temp\xboardme-logging-followup-20261022`；节点复现/回归目录为 `C:\Temp\xboard-node-diagnostics-20261022-bootstrap`。均含时间与证据层次，不能把本地检查提升为上线验收。

参考：[既有节点草稿 PR #11](https://github.com/Hao-Monster/Xboard-Node/pull/11)、[最新面板生产发布](https://github.com/Hao-Monster/Xboardme/actions/runs/39667112469)、[官方漏洞说明](https://pkg.go.dev/vuln/GO-2026-6809)。
