# 精确闲置镜像清理：被动观察范围审查

## 授权与非目标

2026-10-12 用户接受将过重的连续流量监测要求收敛到精确闲置镜像清理，并要求马上执行。
本次不建设监控系统、不 finalize、不部署分销商改名功能，不改变生产服务生命周期。
这是独立的 schema 3 审批契约，不是给旧 schema 2 添加跳过检查的开关。

每次仅批准一个完整镜像 ID，最多 512 MiB、8 个别名、至少 7 天历史。
所有运行或已停止的容器、数据卷、数据库、配置、日志、备份和回滚状态都必须保留。
只允许 `docker image rm --no-prune`；无 force、prune、批量循环派发、失败重试或自动部署。

## 必须保留的门禁

1. 仓库、main ref、workflow SHA、干净递归 checkout 与实时 main 一致；正常 develop → main PR，
   所有必需检查完成后方可合并，只有精确 main 工作流可以使用生产凭据。
2. 使用现有部署锁，以 Caddy loopback upstream → 端口绑定容器重新识别活动运行时。
   审批绑定完整资源、保护、启动配置和目标镜像快照指纹，过期或任何漂移即拒绝。
3. 镜像没有任何运行或停止容器引用，没有实际启动配置引用，不属于活动、回滚、维护、anchor、
   待上线版本或本次 workflow SHA。删除每个别名前再次验证，Docker 非 force 拒绝作为第二道保护。
4. CI runner 在首次生产访问之前，按批准的 GHCR 不可变 digest 拉取 linux/amd64 镜像并校验身份。
   不启动镜像，不把镜像或凭据传到生产。生产 Docker containerd 可能用 manifest/index digest 作为 ID，
   而 runner 使用 config ID；仅在批准 ID 就是固定恢复 digest 时允许这种已说明的存储表示差异，
   仍必须验证 RepoDigest、平台、revision 和 source。其他 ID 不一致拒绝。
5. 清理前记录不可覆盖的 plan 与 intent；只允许目标镜像消失或失去原有别名。
   完整库存中的其他镜像以及全部容器必须保持不变；共享层由 Docker 引用保护，不手工删文件。
6. 后续独立只读审计必须证明恰好一个镜像减少，全部容器、挂载、Caddy 进程/配置、启动引用、
   数据目录身份与 release state 未改变。成功结果和恢复证据上传后才标记 post-verification passed。

## 固定被动观察与停止条件

复用现有容器元数据、systemctl 指定属性及 /proc 资源采样；不安装 agent、不调用应用 CLI，
不发送 HTTP/WS、不登录、不查询数据库/Redis，不生成 live-signals 文件。

- 基线 120 秒，删除后观察 300 秒，每 10 秒采样，心跳最旧 30 秒；审批不能缩短这些固定值。
- 监测清单必须精确覆盖新鲜审计中的全部容器，包含旧回滚容器。
  所有 ID、status、StartedAt、RestartCount 必须保持一致；运行容器不得 OOM 或变为 unhealthy。
  已停止历史容器可能保留 unhealthy/OOM 旧标记，不为清理启动它们，也不把旧标记误判成当前线上故障；
  其完整快照仍由删除前复验和独立后验保护。
- CPU busy ≤85%，iowait ≤10%，MemAvailable ≥131072 KiB，IO full avg10 ≤1，CPU some avg10 ≤10。
  审批可以更严格，不能更宽松。缺失资源数据、采样超时、身份变化或超阈值均停止并保持 HOLD。
- Docker CLI 超时不代表 daemon 已停止处理。超时后不重试、不删除下一对象、不杀 daemon、不重启服务；
  后续只做受保护审计，任何无法确认的结果均保持未完成，阻止后续部署。
- 同一持久 gate 兼容旧 schema 1 与新 schema 2；旧 RUNNING/HOLD/损坏/未后验 COMPLETE 均不能被新 SHA
  或新契约绕过。只有 COMPLETE/passed 可以归档后接受另一份独立审查的新目标。

## 证据与不能声称的结果

只读现场审计：Actions run 38215833245，精确 main
`c078add837315ed23949437267843d21a130c854`；完成时间以产物 epoch 为准。
其完整 `images-only-operation.txt` SHA256 为
`d5f9e84df0b2a104d1e8024845f159c31f94e8d78db47e7cacf9d69379577236`。
当时 24 个容器、146 个镜像，活动版本为 `17bad07edbbf1da2b8c1263fc5bb33499e22affc`，
release `37503793113-1`，Caddy 上游 `127.0.0.1:7002`，rollback_supported=true。
这些是有时间边界的审计事实，执行前仍须实时复核；不把它们写成持续无断线证明。

删除不调用 stop/restart/reload/switch，不直接写业务数据库或数据文件，但 Docker 删除存在磁盘 IO，
运维锁、gate 和审计也有有限写入。镜像逻辑大小不是净回收量或 IO 上限。
容器健康与资源采样不能证明每个客户请求或长连接从未短暂异常；报告不得声称已验证自然流量、
认证功能、真实 WS 连续性或绝对零延迟影响。本次不通过新监控或主动认证请求制造此类证据。

## 实施与验收矩阵

| 风险 | 必须实际执行的验证 |
|---|---|
| 旧契约被隐式降级 | schema2 原检查不变；不能通过 mode/env/未知 schema 绕过；旧 gate 的 RUNNING/HOLD/pending 仍拒绝 |
| 被动模式暗中发送业务请求 | 真实 probe 分派 + HTTP/WS/signals tripwire；不生成 signals，无认证 smoke |
| 只监测部分资源 | schema3 清单与完整审计容器集合逐项完全一致；漏项、重复、额外 ID、PID/start/restart/health 漂移失败 |
| 恢复内容错误 | runner digest、平台、source、revision 与 ID/config-vs-index 语义正负用例；恢复失败时尚未访问生产 |
| 精确删除与共享层 | 隔离真实 Docker 验证标签/digest、多别名、共享层、停止容器引用、父镜像、数据 sentinel 保留 |
| 中途漂移与故障 | 首个别名后出现新引用/新别名、资源异常、TERM、监测失联均 HOLD，不继续，不自动重试 |
| 完成伪成功 | 完整 inventory/hash/数量和唯一目标差集、Caddy/状态/启动引用后验；证据上传失败仍不准部署 |

本文件为范围与风险审查，不以文档存在冒充测试通过。每次批准仍需实际测试报告、正常 PR 的
必需 CI 成功，以及本次执行流水线的恢复、基线、删除后观察和独立后验成功。
