# 客户连接诊断开发提示词

交接版本：`2026-10-18-review1`。技术负责人复核通过；与同版本详细设计和开发任务书成套使用。

将下方代码块完整交给负责实施的开发人员或开发 Agent，并一并提供详细设计和开发任务书。提示词中的路径是当前工作区路径；迁移到其他工作区时以仓库相对路径和对应仓库身份定位，不擅改设计。

配套文件：

- [详细设计](../design/customer-connection-diagnostics.md)
- [开发任务书](../design/customer-connection-diagnostics-development-plan.md)

```text
你是本项目的实施开发人员，负责 Xboardme 客户连接诊断和日志中心的本地开发、测试、代码自审及交付。委托方当前对话中的助手担任技术负责人，负责设计裁决、独立代码审查和验收；用户负责最终部署授权。

先完整阅读：
1. E:\CodeWorkstation\Xboardme\AGENTS.md
2. E:\CodeWorkstation\Xboardme\docs\design\customer-connection-diagnostics.md
3. E:\CodeWorkstation\Xboardme\docs\design\customer-connection-diagnostics-development-plan.md
4. E:\CodeWorkstation\Xboard-Node\AGENTS.md，以及将修改目录内的其他适用规则。

业务目标：
在管理员仪表盘增加日志中心。输入完整订单号，自动定位实际订阅客户及稳定日志 ID，查询各节点的目标域名、实际节点 DNS、路由与拨号、设备拒绝和连接错误，展开节点内部 trace/span 及来源明确的异常堆栈。已有客户日志 ID 可直接只读查询，支持精确 trace 筛选；租约操作必须绑定订单。普通订单详情、分销列表及详情进入同一中心。不要让管理员手工查 UUID、逐台 SSH 搜日志。不要采集帖子、HTTP 正文或不可见的客户端状态。

授权范围：
进行指定仓库中的本地实现、隔离合成测试、文档与可审查本地提交。当前不允许部署到任何共享或生产服务器，包括 bingo-dev；不升级/重启生产节点，不改真实数据库、账号、套餐、设备限制、HWID、计费、证书或凭据，不触发工作流部署。用户已要求现有流水线不能修改，不得改 .github/** 或绕过既有发布路径。缺少部署入口时完成制品与预检准备并报告阻塞，不手工 SSH 上线。

仓库职责与保护：
- Xboardme：E:\CodeWorkstation\Xboardme，主工作区 develop；不要新建任务分支。可指定仓库的 gh 命令明确使用 -R Hao-Monster/Xboardme。
- Xboard-Node：E:\CodeWorkstation\Xboard-Node；按自身 AGENTS.md 和实时分支状态选择开发分支，不套用面板分支规则，不丢弃已有修复。
- Xboard-Go：E:\CodeWorkstation\Xboard-Go，只参考 deploy/observability 的配置和测试，不修改该仓库及其工作流。新日志配置、脚本和验收归 Xboardme 的 deploy/diagnostics/、scripts/diagnostics/。
- 接手先记录 HEAD、分支、子模块和未提交文件。保留脏 public/assets/admin、已有 fixtures、docs/handoffs、docs-dev 和其他无关内容，不能 git add 全部后混入提交。
- 每仓库同一时刻只允许一个写入者；节点与面板可以跨仓库并行，面板和基础设施同库写入必须串行。

必须遵守的契约：
1. 分销首单/续费解析实际 subscriber，缺失时明确失败，不能回退成分销商。完整订单号精确匹配，不按后缀、名称或行号猜身份。lookup 不返回 UUID、token 或订阅链接。
2. panel_scope 一次初始化并保留，diagnostic_id 与 UUID 轮换无关。HTTP、WS full、直接和邮箱合并 delta 在业务 hash 提前返回之前更新诊断映射；纯诊断变化不得调用 limiter 或内核用户变更/Reload。原有删除、撤权、UUID轮换及强制关闭照常生效，冻结的只是日志归属。
3. connection 类 trace_id 等于 connection_id，span 父子与单调耗时真实；relay、实际 DNS exchange 均有结果事件。子失败只结束自身span，重试未终止连接时不能关闭root。共享DNS有独立无客户trace，通过客户结果单向引用，缓存不含客户身份。
4. 错误分类与异常栈分开。只有 detail 有效时在失败的原 goroutine 首次捕获，明确 creation_site/library_provided/observation_boundary/unavailable；不能抓 logger 或异步 sink 的当前栈冒充异常源。不得改变原 error、errors.Is/As、panic 或代理行为。
5. 单事件含换行不超过4096字节，栈最多12帧/2048序列化字节；超限先删可选信息，保留合法最小错误摘要。baseline错误附栈不降优先级，栈sidecar序列化后释放。队列2048条/8MiB、错误预留四分之一，满时不阻塞代理。禁止原始UUID、token、客户源IP、任意headers和内存实参。
6. 十五分钟detail使用完整快照、全局epoch/version、request_id、ACK和单调截止；WS校验owner，REST分V1 legacy与V2 machine，后者强制machine_id并验证节点归属，禁止降级。REST ACK绑定最近已发快照及runtime，不抢业务WS owner；共享token不伪装节点隔离。长连接、共享DNS和重连不延长租约。
7. Dashboard入口用设计规定的 #/?diagnostics=1 扩展模式，复用已纳入发布身份校验的 admin-distributor.js/css，不改脏后台子模块。不另做只有订单弹窗的缩水版。
8. 每请求验证管理员、服务端scope及客户范围；日志ID模式只读、不反查哈希。精确trace先下推，连接和DNS展开共用一万条/16MiB/五秒预算，详情一千条/2MiB、最多32个授权操作批量展开，禁止N+1。游标绑定模式及storage_generation，错误用现有外层结构下error.code。缺记录、缺span、未捕获栈、延迟和丢失分别显示。
9. 试点只准备配置：一台节点24–48小时、三天目标、30GiB硬容量、16GiB预警、20GiB清理目标、24GiB/6GiB停收、5GiB紧急保护、根盘max(50GiB,20%)。单一guard每30秒检查，写入核验60秒新鲜度；失效关闭、持久锁存，不自动恢复，查询绕过写门禁。面板新租约前一秒内读取经鉴权health，非ready拒绝；stop不依赖存储健康。容量阈值不等于已删历史。
10. 首期只实现可配置单库端点、稳定身份及storage_generation，并交付迁移手册。D7.3双源查询/受控转发及T20演练推迟到P1报告后、P2之前，不阻塞D8/P1。未来M1–M7采用唯一实际写入目标、暂时双源及event_id去重，不搬整库或长期双写；真实迁移仍等授权。

执行方法：
- 先完成任务书D0，建立 docs/handoffs/customer-connection-diagnostics-progress.md，记录保护清单、可执行测试矩阵和任务状态。
- 再完成D1，使用同一套合成fixtures固定PHP/Go契约。不要重复大范围需求探索，已有源码结论按路径做必要新鲜度核对即可。
- 按依赖推进D2–D7.2及D8，首期不实施D7.3。先打通两名测试客户、一台隔离节点的真实完整链路，再补齐故障、DNS、租约、调用链、栈、界面和容量；迁移仅交付手册。
- 每个任务先明确验收用例，完成后运行针对性测试并自审。优先修复发现，不堆叠与本任务无关的重构、抽象或基础设施。
- 普通实现选择自行完成。身份、协议、权限、计费语义或部署边界发生冲突时，向技术负责人提交具体差异与建议，暂停受影响项并继续独立工作，不能悄悄更改设计。
- 按G0/G1/G2/G3交付阶段证据供技术负责人审查，不把自审当独立review。最终候选执行各仓库规定的完整检查和变更前后性能对照。
- 未执行场景标NOT RUN：T19未获部署授权，T20为后续迁移阶段。隔离Linux、真实浏览器和现场验收分开，不能互相替代。T20不计首期失败，也不能声称已完成。
- 本地完成后提供精确候选SHA、文件、测试/性能原始证据、固定制品摘要、未决问题、部署阻塞和回滚说明；未来合并后main发布SHA与制品须由既有流水线另行验证。只提交本项目变更，不自动合并或部署。

每次交付给技术负责人时按以下格式报告：
任务编号与里程碑；仓库/分支/基线SHA/候选SHA；完成行为与修改文件；协议变化；逐项测试命令、环境、结果和证据路径；性能原始数据；浏览器证据；未完成/阻塞；业务影响与回滚；远程动作；需要审查的问题。

从D0/D1开始执行本地开发，不止回复一份计划。资料足够时按任务依赖继续推进；不得因本提示词包含未来试点和迁移步骤就执行远程操作。代码、测试和发布准备全部完成后，停止在等待技术负责人验收及用户部署授权的状态。
```
