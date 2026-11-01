# 2026-10-22：诊断 writer 的 vmauth 重复路径修复

协调者最新结论：本地最小修复已通过完整 PHP 仓库检查，专用 pilot auth 已热加载修正，真实 QA 日志入库及 PHP 订单查询已通过。下文“本地准备者 NOT RUN”保留其局部交付边界，现场接续证据见文末；租约/性能/长期验收仍不能据此判定通过。

## 范围与保护

- PHP 仓库主 checkout，分支 `develop`，基线 `0235c106e724f26b3cf2441fc9c5fb9ecda24959`。本节记录提交前候选；最终提交 SHA 与现场变更由协调者另行绑定。
- 生产逻辑只改 `deploy/diagnostics/configure.py` 的 writer `url_prefix` 一行。`src_paths` 仍精确为 `/insert/jsonline`，四个固定 query 参数及三个身份 headers 原样保留；viewer 路由、凭据、collector、guard、Compose、CI 均未修改。
- 保护既有工作区改动：`docs/handoffs/customer-connection-diagnostics-progress.md`、`public/assets/admin` 及原有未跟踪文件。本次不改 Node 仓库、不提交、不连接服务器、不部署或操作现有服务。

## 根因与真实红色证据

协调者现场观察：真实 Vector 请求返回 `404 Not Found`，已确认至少永久丢弃 29 条，中央 accepted 为 0；writer 凭据摘要一致，writer 访问 health 返回 400。数量以 10:13:50 UTC 的实际 metrics 快照为准，不把后续进程重启后的计数重置视为补回；不能把先前时间戳误匹配的 401 聚合当作本次根因。

旧生成器给 vmauth 的 writer 前缀为 `http://diagnostics-guard:9430/insert/jsonline?<fixed query>`。vmauth 默认保留并追加原始请求路径；官方行为见 [vmauth 简单代理与路径路由文档](https://docs.victoriametrics.com/vmauth.html)。本次结论同时有实际运行捕获，未仅从源码或文档推断。

协调者在 `bingo-dev` 的独立有界实例运行项目固定镜像：

`victoriametrics/vmauth:v1.153.0@sha256:4ebf2f21490df3e8837302b85d9db6ac45765442dfa28db77ffacd3577035a74`

测试真正加载 `generate_central` 生成的 auth，只替换测试 backend authority 为 loopback fake guard；path/query/src_paths/headers 保持原生成值。客户端向 vmauth POST `/insert/jsonline`，fake guard 实际捕获：

```text
/insert/jsonline/insert/jsonline?<四个原有固定参数>
HTTP 404
```

原生回归按预期 FAIL，exit 1。原始本机证据为 `C:\Temp\xboardme-logging-followup-20261022\qa-resume\vmauth-route-native-red.log`，SHA256 `6b250b46d8234b007939a26e3d6f671a34dd87074a82fc172dc5535edb780a3d`。日志还记录六项核心服务 active、中央 runtime 未改变；这些现场状态由协调者采集。本地准备者没有远端执行。

## 修复与回归

writer 前缀改为 `http://diagnostics-guard:9430/?<同一 fixed query>`，让 vmauth 只追加一次原始 `/insert/jsonline`。

`diagnostics_test.py` 新增原生 opt-in 回归：

- 强制使用项目固定 `AUTH_IMAGE`，Linux host network，只监听 `127.0.0.1:19448`；先预检查端口。预检查设置 `SO_REUSEADDR`，不启用 `SO_REUSEPORT`，既有活动 listener 仍会阻止测试。
- 临时目录内生成 fake 配置及身份，仅将 fake auth 只读挂载到独立容器；`--pull=never`、64 MiB、0.25 CPU、32 PIDs、只读根文件系统、drop all capabilities、no-new-privileges。
- 捕获实际转发 path，断言精确 `/insert/jsonline`、四个固定参数值、三个服务端身份 headers 覆盖伪造值及正文完整；非法 writer path、错误 token 和 viewer 写入不得抵达 fake guard。
- 使用随机唯一 name/owner label 标记测试容器；finally 先证明 label，再按完整 container ID 删除。即使 detached 客户端尚未返回 ID 就异常，也可按独有 name 找回并验证身份后清理；不操作未证明归属的容器。
- 普通生成器回归同时断言 writer 只保留精确路由、前缀为根路径。历史 guard 直连回归继续覆盖 gzip、身份伪造及容量门禁，但不能替代本次真实 vmauth 路由测试。

第一轮 green 的预检查曾遇到上一轮连接的 TIME_WAIT，`bind` 返回 `EADDRINUSE`；这不是修复后转发仍失败的证据。原 red/green 目录和错误日志保留，最终测试使用独立 `green-v2`，不覆盖旧证据。

协调者随后运行最终 `green-v2`，实际捕获 `/insert/jsonline?<同一四个固定参数>`，HTTP 200，全部固定 query/header/body 与拒绝断言 PASS，测试容器清理完成。六项核心服务 active，中央 runtime 未改变。原始本机证据为 `C:\Temp\xboardme-logging-followup-20261022\qa-resume\vmauth-route-native-green-v2.log`，SHA256 `870c5b5d8ba9f57165f7468e65be45f62ecf28f35fe2eada2a2bb5aac8816d3f`。该结果证明固定 vmauth 到 fake guard 的真实路由，不证明 VictoriaLogs 实际存储或新 QA 数据闭环。

## 验证快照与接续

| 检查 | 结果 |
| --- | --- |
| 普通 Python 红色回归 | 原生成器 FAIL：writer 前缀不是根路径 |
| `python -B -m unittest discover -s deploy/diagnostics -p '*_test.py' -v`，最终测试版本 | PASS：9 tests，6 通过、3 skip |
| `php vendor/bin/phpunit tests/Feature/Diagnostics/DiagnosticOpsArtifactsTest.php` | PASS：2 tests / 19 assertions，PHP 8.5.9；发生在仅 native harness 可靠性调整之前 |
| Python AST、同目录重新生成两个 writer | PASS；secrets 完全保留，auth 只有两处 writer 前缀改变，guard-config/Compose/guard 完全相同 |
| `git diff --check` | PASS，交付前再核 |
| 项目固定 vmauth 实际红色回归 | PASS：成功复现预期 FAIL、捕获重复路径及 HTTP 404 |
| 最终 native green-v2 | PASS：真实 `/insert/jsonline` + 原固定 query、HTTP 200、全部身份/正文/拒绝断言、容器清理及运行保护 |
| 本机 native Vector / vmauth、ext4 reservation | SKIP：本机 Docker daemon 不可用；缺少原生 fallocate/mkfs.ext4 |
| 此补丁后的完整 PHPUnit / JavaScript / PHPStan / Composer audit / 全部部署及 workflow 检查 | 本局部任务 NOT RUN；发布前按仓库门禁补齐，不将本节目标检查等同发布通过 |
| 中央 auth 实际修正、真实 QA 事件入库、15 分钟租约及长期观察 | 本地准备者 NOT RUN；由协调者按既有授权与运行保护门禁接续 |

最终本地测试 SHA256：`6e98e848b9f3510d30975a52dacefbf9ae85fc4cfe3226fe2b337ea0f60fad02`；修复后 generator SHA256：`9693c4f6585882ade876ea77bc4ce56c5ed813cd5682889b735a6f59b980a3be`。旧红色测试版本与最终测试只在端口预检查、容器清理可靠性上不同，转发路径和安全断言相同。

复现命令：在独立缓存目录使用 `green-v2`，设置 `XBOARD_DIAGNOSTICS_TEST_VMAUTH_IMAGE` 为上面的完整固定镜像引用后执行：

```bash
python3 -B -m unittest diagnostics_test.DiagnosticsConfigAndGuardTest.test_writer_routing_with_native_vmauth -v
```

本次独立路由验收已满足：实际 upstream path 仅一次 `/insert/jsonline`、HTTP 200、所有固定身份与拒绝断言通过、测试容器清理完成，且原服务和中央 runtime 保护不变。后续完整仓库检查、审查、提交与实际中央配置修正/QA 恢复由协调者接续；本回归不重建生产 Compose、不轮换凭据、不证明真实事件已入库。

## 协调者现场接续（2026-10-22）

- 路由补丁后的完整 PHPUnit 368 tests / 4,772 assertions、JavaScript 222 tests、PHPStan、Composer audit、Python 9 tests（6 pass / 3 明确 skip）、actionlint 9 个 workflow 全部通过。部署语法 Shell116/PHP4/Python10 在本轮已通过；仅 Python 路由文件后续调整已重新解析。可选 shellcheck/pyflakes 未运行；native vmauth 的服务器验证另有上述真实红绿证据。
- 10:29 UTC，专用 pilot 的原 auth 文件保留 inode、私有备份和锁，仅更改一处 writer prefix，fsync 后向核对过的 vmauth 完整 CID 发 SIGHUP。before SHA256 `7dc9c293fbaa296514244f57e8a748fb418b394d58c943875c5a941e1f07a87c`，after `60cf598a9f70e10e246b5a28ab753f885db5c86b58c29eb7997ccb36862adfff`；secrets/guard-config/Compose 摘要与容器 PID/StartedAt 均不变，六项核心服务 active。空正文返回 400 `invalid_insert_body_size` 只证明正确到达 guard，不算完整身份/存储验收。
- 真实闭环另行证明：首轮合格 CI 的 QA runtime `f9323f5c0c431b914e00ed733a960ba2`、node68、trace `4eb8926f000d3dea0000000000000001`，源 journal 与中央存储的 seq1–5 五个 event_id 完全相同。PHP 订单 #1435 展示同 trace 与五条事件，截图 `qa-resume/php-baseline-real-detail-visible.png`；精确比对 `qa-baseline-exact-event-proof.json`。10:40 UTC 中央 accepted=47，10:46 UTC 受控 detail 流量后 accepted=82。
- 至少29条旧永久丢弃记录保留为覆盖缺口，不宣称修复后补回。当前新增数据可存储与租约控制正确是两个结论：现场另发现 PHP Redis key prefix 导致 idle/stop 不可用，以及 Node Close 清理错误分类问题；分别修复、审查、重新验证，不以本路由通过覆盖它们。
- 原始证据根目录 `C:\Temp\xboardme-logging-followup-20261022\qa-resume`。热加载证据 `bingo-auth-route-hot-reload-result.jsonl`；TLS/身份对照 `qa-tls-forwarding-v2-result.json`；detail 精确入库 `qa-s1-detail-match.json`。本次没有面板重新发布、凭据轮换、原业务节点升级或重启。
