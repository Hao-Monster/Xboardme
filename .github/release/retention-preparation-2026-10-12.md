# 旧版本清理准备报告 — 2026-10-12

## 结论与授权边界

已在主 checkout 的 `develop` 完成清理安全补强、离线故障回归和独立源码复审。
本次交付是**清理准备代码**，不是生产删除批准书。

- 未执行生产 finalize、cleanup、重启、迁移、部署或新的生产认证请求。
- 未提交、推送、创建/合并 PR，也未创建分支或 worktree。
- `.github/release/retention-review.json` 保持 `blocked`，执行对象和真实证据字段为空。
- 用户原有 admin 子模块差异、四个浏览器 HTML fixture 和
  `subscription-redesign-demo.html` 均不属于此次改动，未暂存或清理。
- 不承诺绝对零风险；真实 Docker/SSH、现场归属、备份及监控证据仍阻断生产执行。

本轮只读 Git 核对：本地 develop、远程 main/develop 均为
`873e943cdd8b2a994a189558ef3b4aadb953bd34`；本地 main 仍在
`17bad07edbbf1da2b8c1263fc5bb33499e22affc`，本轮没有切换或移动它。

生产基线沿用前一轮 2026-10-12 01:27 UTC+8 的只读审计，不冒充本轮实时复核：
当时活动 release 为 `37503793113-1`，应用 SHA 为 `17bad07edbbf1da2b8c1263fc5bb33499e22affc`。
16 个旧容器、135 个历史镜像仅为当时的候选；执行前必须重新盘点，不能复制旧指纹。
此次也没有将待发布的分销商改名版本部署上线。

## 已落实的安全措施

| 风险 | 实现与验证边界 |
|---|---|
| 失败重试扩大删除范围 | 首次冻结批准清单、完整库存、计划摘要与 workflow 身份；持久化每个 ID 的 intent/completed；新 ID、新 alias、未解释缺失及历史状态漂移均拒绝 |
| 当前服务或数据挂载被误处理 | 从 Caddy upstream 发现当前 web/edge；保护六角色、当前维护代理及 anchor；核对完整 ID、镜像、启动/重启、健康、网络、挂载 Source/Name/RW/Mode/Propagation、inode/卷身份 |
| 删除镜像连带清父镜像 | 所有生产镜像删除显式 `--no-prune`；库存包括 intermediate images；禁止 force、prune、停运行容器、删卷/网络/数据目录 |
| finalize 部分写入不一致 | intent 与终态多字段一次验证、一次原子 rename；开始为 `finalizing,false`，全退役后才为 `finalized,false` |
| 批次过大、超时仍继续 | 首批一个；后续 cleanup 最多五对象、容器最多两个；手工 finalize 最多两个；远端整进程组 900 秒 + 30 秒终止宽限，单删除也有超时 |
| 批次完成误报全清完成 | stdout/job output 区分 `pending_batch` 和 `complete`；没有自动循环 dispatch 下一批 |
| 失败跳过后验 | preflight 与审计分开；在安全 admission 后仍收集锁保护的 audit；无条件比较活动 ID/SHA/保护摘要，身份和健康通过才登录 smoke |
| 测试登录残留会话/秘密泄漏 | 私有临时目录，token 不进 argv/checkout/artifact；退出时只尝试撤本次会话并验证失效；能够执行退出处理时标记 INCOMPLETE，硬终止导致无可信结果也按未验证处理 |
| 维护清理误影响正常发布 | 手工操作必须有批准清单；正常已签名 successor rotation 继续使用原发布门禁，不套手工批大小；planner 支持精确 superseded 恢复并保留维护端口 |

`review_evidence` 的 SHA-256 仅绑定独立审查材料，不会自动证明备份可恢复、监控真实可用或测试已通过。
不得填随机值、测试 fixture 哈希或历史过期证据来解除 `blocked`。

## 测试矩阵与结果

环境：Windows / PHP 8.5.9 / PHPUnit 12.5.33；SQLite `:memory:`、array cache/session、
testing 配置及 C:\Temp 下独立缓存/storage/views。Shell 使用 WSL Debian、隔离临时副本与 fake Docker。
没有使用生产数据或真实生产服务补测。

| 验收标准/命令 | 结果 | 退出码 | 数量与耗时 |
|---|---|---:|---|
| `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result`（独立路径环境） | PASS | 0 | 317 tests / 3632 assertions；0 fail/error/skip；87.642s |
| 定向 PHP 工作流、低内存部署及首轮环境失败项 | PASS | 0 | 40 tests / 763 assertions；6.019s |
| `node --test tests/JavaScript/*.test.js` | PASS | 0 | 136 tests；0 fail/skip；5.527s |
| `npm run test:browser:distributor-rename` | PASS | 0 | 26 本地 Playwright 场景；0 fail/skip；20.766s；不是生产浏览器验收 |
| `php vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress --memory-limit=1G` | PASS | 0 | No errors；修正临时缓存路径后 3.415s |
| `composer audit --locked --no-interaction` | PASS | 0 | 无已知安全公告；6.242s |
| `composer validate --no-check-publish` | PASS（既有 warnings） | 0 | openspout 精确版本与 yaml/assert 通配约束警告；没有改依赖 |
| `actionlint 1.7.12 -shellcheck=`，全部 workflows | PASS | 0 | 官方校验和确认的本地临时工具；未运行 ShellCheck |
| `bash test-cleanup-xboard-retention.sh` | PASS | 0 | 26 场景；冻结复验 107.562s；fake Docker；含重试越界、别名、历史/保护漂移、TERM、显式镜像保留 |
| `bash test-finalize-xboard-v2-low-memory.sh` | PASS | 0 | 29 场景；182s；冻结副本与当前源码一致 |
| `bash test-plan-xboard-release-slot.sh` | PASS | 0 | 8 场景；保留维护端口、无空槽拒绝、精确 superseded 恢复 |
| `bash test-release-state.sh` | PASS | 0 | 原测试及 8 类输入/写入故障；原子替换、选项形态值保真 |
| `bash test-verify-retention-request.sh` | PASS | 0 | 9 场景；无 host connection |
| `bash test-verify-retention-post-audit.sh` | PASS | 0 | 14 场景；失败/取消同样核对身份，partial 不是 complete |
| `bash test-smoke-distributor-session.sh` | PASS | 0 | 11 场景；fake curl/SSH；4.735s |
| `bash test-run-distributor-smoke-bounded.sh` | PASS | 0 | 3 场景；1.755s |
| `bash test-retention-timeout.sh` | PASS | 0 | 1 本地真实进程组/锁/TERM 场景；2.78s；无 Docker/SSH |
| 相邻网络 audit/cleanup、运行时发现、V2 port/prepared、SSH、scheduler-reaper、v2-common、rollback-network 测试 | PASS | 0 | 9 套离线 fixture；无生产连接；逐套结果另见本地 results.tsv |
| 全部署 Shell 语法、修改 PHP 语法、`git diff --check` | PASS | 0 | `.github/scripts` 65 个，加根目录/.docker 7 个，共 72；不等于真实 Docker 行为测试 |
| 真实 Docker 协议测试、完整隔离清理与 SSH 断连 | NOT RUN | — | 本机 Docker 服务未运行；未启动或改造共享环境，未连生产代测 |
| 远程 PHP 8.3/8.4、MySQL 5.7/8.4、镜像构建/签名矩阵 | NOT RUN（本轮） | — | 未推送或触发 CI；旧提交的绿灯不能替代新补强代码 |
| 生产 HTTP/5xx/延迟/I/O 观测与清理后验收 | NOT RUN | — | 本轮没有清理；60 秒循环只检查身份/库存/容器健康，不是完整业务监控 |
| 行/分支覆盖率 | NOT RUN | — | 本轮没有收集；不以场景数冒充覆盖率 |

另外，WSL 的既有 `test-resolve-production-image.sh` 因缺少 PHP/zip 未运行；
`test-v2-redis.sh`、`test-v2-redis-owner-restore.sh` 依赖真实 Docker，也未运行。
它们不是本轮已通过的离线测试，不以 Windows PHP 或旧 CI 结果代替。

原始本地结果保留于 `C:\Temp\xboard-cleanup-preparation-20261012`（PHP JUnit）及
`C:\Temp\xboard-shell-review-12135297bef4411bafa4975b98db0f8c`（冻结 hash、逐套日志、
`results.tsv` 与 root 前置环境重跑记录）。

真实 Docker 协议脚本已接入 CI，但只覆盖多 tag、共享层、引用拒绝与 bind/named-volume 保留。
即使该脚本将来通过，也不自动等于多 RepoDigest、完整执行器、真实 SSH 丢失和生产归属已通过。

### Red → Green 与环境失败记录

- 原清理脚本：新增越界回归 exit 1，`retry_deleted_unreviewed_container`，确实删到了后出现的未审对象；冻结白名单版本 26 场景通过。
- 原 finalize：新增“当前维护代理必须保留”断言失败；原子状态 API 缺失及 jq 选项形态值失败均有复现；最终 29 场景及状态套件通过。
- 原工作流失败后验：新增断言先出现 2 failures；拆分后验/无条件身份核对的新增断言又出现 1 failure；修复后 2 定向测试 / 54 assertions 通过，最终全套再次通过。
- 首轮全 PHP 为 1 error + 11 failures：10 项与隔离环境未创建 Blade views 目录有关，2 项为替换执行器后的旧源码字符串断言。创建临时 views 路径、按新更严格契约更新结构断言后，原失败模块和完整测试均通过；没有修改业务逻辑来迎合测试。
- 首次 PHPStan 因当前盘符下临时缓存路径不存在而未能 bootstrap；改在 C:\Temp 下运行、显式隔离配置后通过，没有 dump-autoload 或改生产配置。
- 曾有一次代理在另一代理追加测试时读到混合版本而失败；最终使用冻结副本重跑，不把那次混版运行计为完整结果。
- 相邻的 v2-common/rollback-network fixture 首次因 WSL 非 root 且无免密 sudo 而在前置阶段 exit 1；随后仅在同一隔离临时副本中使用 WSL root 运行通过，未更改 sudo、Docker daemon 或系统服务。

## 独立审查与剩余执行门禁

独立审查发现并修正了父镜像隐式删除、失败后保护摘要未核对、远端整批时限不足、
finalize 续批历史 state 未复核，以及通用状态写入的 jq 选项值解析问题。
复审未发现仍未处理的具体 P1/P2 源码问题；这不等于生产执行风险已全部消除。

实际清理前仍须完成：

1. 隔离真实 Docker/SSH 演练和新代码的远程 CI，保留具体镜像别名/父层、挂载、取消/失联证据。
2. 通过精确 main 受保护只读工作流重新盘点，核对无并发发布；逐对象补齐历史 state、可写层唯一文件、启动引用和精确保护集。
3. 核实备份及恢复证据、真实登录 hook/专用账号副作用、至少五分钟基线与可执行的 HTTP/5xx/延迟/I/O 停止阈值。
4. 分别审批 finalize 与后续 cleanup 清单；finalize 改变状态/库存后重新审计，不复用旧指纹。
5. 在用户执行命令及所有门禁满足后，才可逐批操作；每批后验、最终至少十分钟观察。任何异常停止，不盲重试、不自动恢复旧数据库，也不自动重发改名部署。

当前维护代理被有意保留，仍占其端口；不能宣称释放 7004 或支持无限轮换。
删除旧容器可写层/日志不具备简单撤销；需要的证据应在批准前归档。
若 main、现场身份或审批材料变化，须重新审查恢复路径，不能绕过 exact-main 约束。

完整操作约束见同目录 `production-retention-policy.md`。批准清单继续保持阻断，
本地改动未提交，等待真实环境验证及后续执行授权，不新增重复发布任务。
