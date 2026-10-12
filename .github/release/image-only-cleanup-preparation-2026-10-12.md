# 单镜像清理工具准备与执行边界 — 2026-10-12

## 需求与当前结论

本次接受的需求是：先实现并通过正常 develop → main PR 发布安全工具，
再以新鲜生产证据判断能否清理；不以旧 finalize 路径换取空间，不自动部署分销商改名功能。
清理只能针对一个批准的无引用历史镜像，保留所有容器、数据目录、数据卷、备份和回滚能力。

**生产删除批准仍为 blocked。** 本报告和测试夹具不是删除清单。
尚无经审查的真实长连接/自然流量信号生产者；不能通过假 JSON、随意填充 hash、
建立会争抢真实节点 ownership 的 WebSocket 连接或上线新监控绕过这个缺口。
先使用精确 main 的受保护只读 audit 核实已有低扰动数据源，缺失则保持 NO-GO。
没有创建 06:00 定时任务，也没有自动清理下一批或自动部署任务。

## 方案与可验证门禁

| 验收标准 | 实现与验证 |
|---|---|
| 仅精确 main 的干净 CI checkout 可操作生产 | 独立 workflow_dispatch、recursive submodules、仓库/ref/workflow SHA/checkout/当前远程 main/活动祖先检查；默认 audit |
| 不 finalize、不删容器、不动数据和服务 | 独立 image-only executor；检查原 state hash、全部容器、挂载、镜像及 startup 引用；无 restart/stop/switch/卷和目录删除命令 |
| 只删除批准对象 | 固定 schema2 manifest，1 个完整 image ID，≤512 MiB、≤8 aliases、≥7 天；源/revision/digest/快照/启动引用及审批有效期门禁；只用 `docker image rm --no-prune` |
| 中途异常不能扩散 | deploy.lock、不可覆盖 plan/intent、每 alias 前复核；失败不重试、不扩充目标，RUNNING/HOLD 跨进程持久化 |
| 连续观察 | 最少 600 秒基线、900 秒删除后观察；公共与 origin 状态/内容/延迟、角色身份/启动/重启/健康、Caddy process identity、资源压力、真实流量/连接信号 |
| 不能把脚本完成当成全流程通过 | COMPLETE/pending 仍闭锁；独立审计核对库存数量/摘要、唯一精确镜像差异、state/Caddy/process/startup/保护身份，再运行公共/认证 smoke，保全证据后 post-verification 才可 passed |
| 失败不能被换 SHA 绕过 | 标准发布入口和持锁 mutator 再检查固定 gate；admin asset hotfix 也持同一锁；既有故障回滚不受该 gate 阻挡 |
| 无人值守失联不假成功 | 远端进程组 2700 秒 + TERM 后 30 秒；job 65 分钟留出后验和证据时间；超时/取消/监测失联维持 HOLD 或未完成状态 |

归档只适用于已经 COMPLETE/passed 的上一轮且下一轮有全新审查和目标，不允许把失败
计划当作新请求继续。单镜像净回收空间取决于共享层，不能用镜像 Size 冒充实际可用容量。

## 独立审查中已修复的问题

- 连续观察的 5 秒等待曾受通用 3 秒 timeout 误伤；修复并加入真实时钟回归。
- 当前 V2 验收的 rollback 模式与 V2 release ID 不兼容；使用活动完整 SHA 对应的 release 验收，
  不要求旧活动镜像含有待上线的新功能资源。
- 后验曾忽略已采集的 Caddy PID/invocation/start-time，可能漏掉观察后重启；补唯一性、
  有效性、审批绑定、前后完全一致检查，同时绑定完整库存数量/摘要与目标快照。
- 未启用的 sample Compose 模板曾导致误报；实际使用 sample 必须纳入，未知语法/变量、
  隐藏引用和不安全路径拒绝；规范化 tag@digest 和 Docker Hub 熟悉名称，保守保护跨仓库相同 digest。
- 生产 lock 文件使用非截断打开；验证 owner/mode/link，避免硬链接导致非目标数据被截断。
- SSH askpass 改为以可执行位入库，避免首次连接的 chmod 令 Ubuntu checkout 变脏，
  从而把后续 audit/postverify 错误挡在干净工作区门禁外；保留严格 dirty-check。

## 测试证据与边界

测试在 Windows PHP/Node、WSL Debian 的隔离临时目录中执行，不以生产补测。
本地已完成 PHPUnit 321 tests / 3706 assertions（0 fail/error/skip，107.215s）、
JavaScript 136 项（0 fail/skip，4.540s）、改名真实本地浏览器 26 项（0 fail/skip，22.328s）、
PHPStan（No errors）、Composer audit（无安全公告）、actionlint 1.7.12（未使用 ShellCheck）。
Composer validate 通过但有原有精确版本/通配依赖约束 warnings。

Shell 针对审批、持续监测、执行器、轻量审计、启动引用、后验完整性、持久闭锁和 legacy
互操作分别提供可执行 fixture 测试。最终新增/补强场景数以当前 CI 日志为准；不把采样
时钟替身的 600/900 秒输出当作真实生产观察。已证明的红→绿包括旧 cleanup 拒绝保留回滚的
image-only 场景、缺失安全 helper，以及独立审查复现的参数/后验缺口。

真实 Docker 集成只在无生产凭据的普通 Ubuntu CI 执行，使用带唯一测试标签的合成资源、
真实 Docker inventory/rm/shared layers/挂载与数据 sentinel/持续 TCP 连接。
合成 discovery、GHCR eligibility 和监测时钟属于显式测试边界，不证明真实生产负载、
签名、多 RepoDigest、daemon timeout 或 SSH 断线时的线上表现。本机 daemon 不可用，
不启动共享 Docker Desktop；本地真实 Docker 结果是 NOT RUN，必须等待 PR CI。

行/分支覆盖率未收集。没有线上恢复演练、实时监测验收或“不发生任意瞬间抖动”的证据，
不能承诺绝对零影响。认证 smoke 仅使用专用测试账号和临时会话，但会更新登录元数据并可能
调用已装 hooks；必须审查真实 hooks 和撤销本次会话证据，不能声称整条流水线零数据写入。

## 执行条件

仓库已有无关子模块差异和 HTML 文件保持原样，不暂存、不清理。
正常 PR/必需检查/合并之后，才允许运行该精确 main 的 audit；audit 绿色不是删除批准。
必须补齐安全端点/备份恢复/资源开销/认证/实际信号源证据、冻结一个无引用镜像并独立审查，
再以新的正常 PR 批准该 manifest。任何步骤缺失，不触发 cleanup；本轮不部署改名功能。
若未来获准清理过程中线上异常，停止一切后续删除/部署并报告，按用户要求尝试本机音乐告警，
不调整本机音量/电源，也不通过重启线上服务“修复”清理异常。
