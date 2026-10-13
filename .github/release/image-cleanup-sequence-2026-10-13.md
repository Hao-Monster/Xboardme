# Sampled19 单镜像顺序清理范围

status: ready_for_root_approval
authorizes_execution: false

本文件记录已核对证据及拟采用范围，须由根代理明确批准并经正常 PR 合并后，才可与对应编号清单共同生效；它不单独授权恢复、删除或部署，不代表已经执行清理。不继承旧零采样 HOLD 的恢复授权。

## 新审计 A 与有效期

- 仓库 `Hao-Monster/Xboardme`；A 完整 main/workflow SHA：`e1c98b36a02f5523735235113e2cd913ef922554`。
- gate-audit run `38354235392`，attempt 1，`workflow_dispatch` / `main` / `success`；唯一 artifact：`production-image-cleanup-e1c98b36a02f5523735235113e2cd913ef922554-38354235392-1`。运行终态及来源关联以根代理外部核验为据。
- 本地原始文件 `gate-audit-38354235392-e1c98b36/images-only-operation.txt` 的重算 SHA256：`382549938d71f73190107595404d2eb34a28dcbda20831940cd6ea1ec19a863a`。终行是只读 PASS，`recovery_authorized=false`、`deletion_authorized=false`、`connection_continuity=unverified`。
- 实际审计完成 epoch `1791871288`（新加坡时间2026-10-13 14:01:28）；固定到期 epoch `1791957688`（2026-10-14 14:01:28）。窗口为86400秒，提交、构建或生成时间不刷新审计。
- A记录24个容器、146个镜像；实际 Caddy 上游 `127.0.0.1:7002`，活动 release `37503793113-1`，应用 revision `17bad07edbbf1da2b8c1263fc5bb33499e22affc`。这些是A时点证据，不是持续健康证明。
- A的 active-state / inventory / startup SHA256依次为 `ed841a62d7b4c6e3e5ff411126bd28ca5dcc228257ca0a57fa6fceec8d2cd6b5`、`2eeaa03650dd563c2681495d7f8e31afc4c40f2e51db6b0c460ac75a8f4ef0c4`、`35d5e4641d0e185b88610ab8cac703315405001161e2255ae8d908ddf4cb8412`；protection 为 `e3193761cad71b4bf98c6adcd54417c93238dfc3195e0dfe9b52297fc2c647ee`，resource 为 `6ccd78749f8eb5fdce9ff54238a2256da89a430a8c30ba6a36534f635b294e7e`。

## 固定历史失败 H 与 sampled19 恢复边界

- H：`354608ffd90e1febe51f36c3063d3a71816e4f76`；失败 run `38265050808`，attempt 1；原 session `6b46aa43-8767-401b-82f1-92e2d0a40d60`。
- 首项目标：`sha256:42e9170c0ab7c56014ec54a4e04a8ad78bc45bbb95cef090b2353267c514317a`。
- 历史 proof SHA256：`d2e2ea4f7b6a43cd8b0a2a8ca2ed968ee9861e0d9659a150a3332ede954383f1`；原 review SHA256：`756ed33f7409804d02a5b11373a92a483f8f54657ab90a6265543a07b0a8c0dc`。
- A仍绑定原 gate SHA256 `08f169d7f03d5e61bec7449f366e1c810c9ce5a37c011d752b77fdf9772afdf6`（1182字节）及原 plan SHA256 `e508dfef8bca806d770c2a172e35353ca7c5afb2ab09bf2a59435c6142dd4c77`（162752字节）。

该 gate 为 schema 2 / `passive_runtime_v1` / HOLD，已有19次成功采样及 baseline 完成记录；第20次采样因 CPU PSI `some avg10=20.34 > 10` 被拒绝，未计入成功数。A中 `intent_at_uptime`、`removed_at_uptime`、`observation_completed_at_uptime`、`completed_at_uptime`、`post_evidence_sha256`、`post_verified_at_uptime` 六字段均存在且为 null；未进入删除意图，没有删除成功记录。

001仅使用既定 `schema_version=2, mode=predelete_sampled_hold_v2`。固定历史 proof、完整失败 operation、timeline、alignment，以及原 gate/plan 重建字节继续按原协议验证；不得改写历史证据。受保护执行器仍须在锁内复验当前完整状态，保留原 HOLD/plan 证据，并开启新的完整监测；不复用旧19次样本，不把旧 HOLD 改写为成功。

## 精确候选与不变保护

- 有序 allowlist 原始 SHA256：`e211e8bb1741d94d9e2a6dfd91024aa3d2268c234b29515e1dae17ab0fc5b694`；90个唯一ID，001为上述目标。根代理提供的独立复核已确认90项在A证据下全合格；本范围不扩展候选，实际执行仍逐项复验原有来源/revision、七天保留期、512 MiB大小、至多八alias、不可变恢复、全容器消费者、启动引用及保护条件。逻辑镜像大小不是可回收空间保证。
- 每份新编号清单仅一个精确镜像、无容器；全链重算原始字节哈希、前驱及投影库存。仅001带 sampled-v2 recovery；后继须以前项真实 COMPLETE 和独立 post-verification 为前置。预测库存不是新审计。
- 保留全部当前容器及身份/启动时间/重启计数、Caddy进程与路由、挂载、存储/Redis锚、既有revision/digest保护集合及待发布改名镜像。**不 stop/restart/reload/switch，不部署应用，不删除容器、网络、volume、数据库、业务数据、配置、日志、备份或生产持久目录。** 不 broad prune、不强制删除、不在生产拉取恢复镜像。
- 原资源阈值、120秒 baseline、300秒删除后观察、10秒采样及30秒心跳要求不变；恢复镜像仍在runner验证。过期、漂移、HOLD、缺失证据、结果不明或验收失败立即停止，不自动重试或跳项。
- `connection_continuity=unverified` 保持明确：被动样本、身份不变和无重启命令不能证明逐连接零中断，不作绝对零影响承诺。

## 固定来源与批准提交 C

- 模板拟绑定仓库路径 `.github/release/image-cleanup-sampled-seed-2026-10-13.json`，原始 SHA256 `49ec4368eedf8f21be0e4d68508b91646c0e895acea7586e992b3ed3f43f78c6`；本地种子为 `sampled-template-from-756ed33f.json`，不含 recovery/sequence。最终编号清单使用A的审计时间、上述到期时间及本次范围证据。
- 本范围拟绑定 `.github/release/image-cleanup-sequence-2026-10-13.md`。其原始字节哈希由根代理在生成清单时外部冻结，不在正文写入自引用哈希。
- Docker证据沿用2026-10-12的PR77 CI run `38250610429`：日志 `pr-77-38250610429-verify.log`，重算 SHA256 `ed17f73acf156b21b1f541030b169b63412da6765c2f72ca383de655ad5f47fe`；其中 `IMAGE_ONLY_DOCKER_TEST=PASS cases=16 real_docker=true`。这是历史隔离兼容性证据，不是当前生产健康、容量、性能或连接连续性证明，也不替代本次批准链的CI。
- C是包含最终全链、模板、安全历史投影及本范围的**未来正常PR合并main提交**。C在合并后、执行前单独冻结，不以A或H替代，也不在本文预填C以形成自指。正常PR/CI、C的构建/签名及原有生产门禁仍须完成。
- 新001必须在C上单独执行并验收完整证据；成功后方可使用既有已审 sampled driver。配置、journal、运行cap及每项剩余时间门禁沿用既定协议。本文件和审计A均不是cleanup-success，当前 `authorizes_execution: false` 不因文件命名或生成编号草稿自动改变。
