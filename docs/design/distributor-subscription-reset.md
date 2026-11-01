# 分销订阅整体重置详细设计

日期：2026-10-30。范围：当前 develop 工作区；先完成本设计，再开发。用户已确认所有设备重新扫码，流量和到期时间保留。本文不代表生产部署或真实节点验收。

## 1. 行为和不变量

重置的对象是 DistributorOrder 对应的原 subscriber_user_id，不创建客户、订单或续费记录。一次操作原子更换该客户的 token 和 UUID，删除全部 HWID 设备，作废旧 claim_token 及 claim_token_hash。新二维码携带新 token，所有设备须重新导入。

严格保留客户 ID、plan_id/group_id、transfer_enable、u/d、expired_at、next_reset_at、限速、device_limit、HWID 开关和上限、客户姓名/备注、订阅名称/编号、交付历史、续费和结算数据。流量上报可以正常继续；重置代码不读取再写回权益快照，不调用套餐分配/流量重置逻辑。凡具备订阅二维码入口的订单（无论是否有关联续费订单或历史交付弹窗是否关闭）均支持重置；仅续费子订单不重复展示二维码/权益/续费操作。过期、耗尽、封禁客户不因重置恢复权益。

## 2. 当前实现与失效语义

- 当前二维码使用客户 token；Client middleware 通过数据库查询 token 认证。
- 普通用户 resetSecurity 更换当前登录账号 token/UUID，不适用于分销商操作客户。
- HWID 同步路径和异步新设备路径持有交付行锁；异步已绑定设备快速路径保持只读。
- NodeUserSyncJob 在 UUID 变化后的提交阶段入队，节点用户列表和协议密码均使用客户 UUID（SS2022 也由 UUID 派生）。
- **控制面保证**：重置事务提交后，以旧 token 开始认证的请求失败；先前已认证但尚未绑定的请求在交付锁内重新校验，不能恢复旧绑定。响应发出前再次检查 token，避免已知过期请求返回配置。重置前已经输出到网络的响应不可撤回，其 UUID 随节点同步作废。
- **数据面边界**：节点需要接受最新用户列表/增量，才会拒绝旧 UUID。仓库没有外部节点消费者和凭据确认协议，不能把队列入队视为所有节点已确认，更不能承诺强制瞬断已有长连接。上线验收必须实际验证旧配置新建代理连接失败、新配置可用；节点离线/延迟需处理后才可宣布全链路失效。
- WS 增量使用同一用户 ID 的 `add`，外部节点必须替换原 UUID；发布验收分别覆盖 WS 增量与 HTTP ETag 拉取，不能假定增量消费者已正确实现替换。套餐在线 IP 统计按用户 ID 保留，旧 IP 最多仍计入 300 秒窗口；该统计与已清空的 HWID 绑定不同，不清除它以免重置绕过在线设备统计。
- 遗留 `/api/v1/client/app/getConfig` 无 HWID 流程，不允许有关联分销交付的内部订阅账号使用；这些账号统一走标准订阅入口。普通账号保留现有兼容入口。

## 3. API 合约

GET /api/v1/user/distributor/subscription-qr 保留现有字段，新增 can_reset_subscription（凡客户订阅 token 存在即可重置，不受是否关联续费订单或历史交付弹窗是否关闭限制）、delivery_status、credential_version。版本为高熵 token 的 SHA-256 指纹，不作为任何认证凭据，不返回 token/UUID 明文。deliveryData 同样提供这些字段，方便单张与批量交付弹窗。

POST /api/v1/user/distributor/subscription-reset：

```json
{"trade_no":"原始订阅订单号","credential_version":"64位小写十六进制","idempotency_key":"一次用户确认生成的随机标识","confirm":true}
```

成功 data 是新 subscriptionQrData，并带 reset_at、cleared_devices、replayed。只允许当前有效分销商操作其原订阅订单（续费子订单无独立交付记录返回 404）；非所有者统一 404，角色/封禁 403，订阅未生成/版本过期/幂等冲突 409。输入格式错误 422。POST 不自动重试。

新增 v2_distributor_subscription_reset 审计表：id、distributor_order_id、distributor_user_id、idempotency_key、previous_version、credential_version、cleared_devices、created_at。唯一约束 (distributor_order_id,idempotency_key)。不存 token/UUID、订阅 URL 或二维码。不改既有表结构，支持旧版本回滚读取；迁移列入 release allowlist。

相同幂等键和原版本重试：若该次重置仍是当前凭据，返回当前二维码而不再次轮换；参数冲突或后来已有另一次重置则 409。不同键同时使用同一旧版本：只有第一个成功。返回响应失败/图片渲染失败不回滚已提交的重置，原键重试可恢复。

审计查询使用锁定当前读，避免 MySQL REPEATABLE READ 下事务最初读取操作者建立的旧快照，使等待交付锁的相同幂等请求看不到先行提交的审计记录。所有包含二维码的 API 响应均设置 Cache-Control: no-store, private。

禁止在持有交付行锁后再锁分销商用户行；续费锁序为分销商 → 交付 → 客户，反向追加操作者锁会造成锁环。凭据轮换使用 `random_bytes(16)` 和标准 `Str::uuid()`，避免旧 Helper 在 Windows COM 环境产生非标准 UUID。随机 token 为 128 位；不使用“先查不存在再插入”冒充数据库全局唯一保证。

## 4. 事务、并发与性能

重置先按所有权定位交付记录，在 SqliteImmediateTransaction::write 中按 **交付行 → 客户行** 顺序加锁（与续费一致）。再次核实有效分销商及所有权，检查幂等记录与凭据版本；只更新客户 token/UUID，删除 HWID，作废领取密钥，写入审计，同事务提交。二维码渲染与响应在提交后完成。避免锁整个分销商或全局锁，独立订阅可并发；SQLite 固有单写者限制必须如实说明。

HWID 两条写路径在已有交付锁下，读取当前客户 token 并与请求最初认证的 token 比较；失败不写设备、不返回新凭据。不要 refresh 原认证 User 为新 UUID。异步已注册设备读取不增加写锁；响应结束前统一新鲜度检查。交付领取/配置下发状态写入应带当前 token 条件，并与重置锁序一致，避免旧请求在重置后写状态。

旧 HWID 异步观察任务只能按旧设备 ID 更新既有记录，删除后应空操作，不得 upsert 复活。旧领取接口必须在交付锁内核验仍有效的 claim hash；重置不能只清除加密 token 而保留可兑换的 hash。

## 5. 前端交互

订单二维码、单张交付弹窗和批量交付每张订阅卡片均将“复制图片 / 下载图片 / 重置订阅”置于同一水平操作行；单张交付与批量交付在“重置订阅”旁附带金色提示标签“我的订单页面也可以重置”。点击“重置订阅”弹出独立上层确认弹窗，关闭确认弹窗不影响底层原有的订单二维码弹窗、单笔交付弹窗或批量交付弹窗。确认页显示客户、订阅编号、设备数及“所有设备需重新扫码，流量和到期时间不变”。中英文文案一致。批量交付弹窗打开时不自动触发下载，顶部提供“下载全部二维码 (N张)”按钮，点击后逐张自动下载 PNG 图片（不再打包为 ZIP），且每张批量二维码 PNG 左上角直接绘制 `#序号 / 订阅名称 / 订单号`。重置成功后显示新二维码、清空复制状态和设备标签，刷新订单数据与对应卡片的待下载 PNG 资源。

确认提交后立即禁止旧图片复制/下载并清除旧图片缓存；按钮防重复。保留该次幂等键和旧版本，超时/网络失败后允许用户显式恢复同一次操作，不能生成新键盲目重置。关闭/重新打开时恢复未决操作；账号切换清理状态并忽略旧请求返回。已知版本冲突先刷新当前二维码，再由用户重新确认。异步读请求、分片图片生成、轮询和逐张下载循环均不得覆盖重置结果或在弹窗关闭后继续下发旧图片。

## 6. 分工与验证

根代理：重置服务、迁移、路由/控制器、二维码合约、设计与整合。鉴权代理：旧 token 并发栅栏及相关测试。前端代理：弹窗/缓存/重试、JavaScript 和真实 headless 浏览器测试。验证代理：独立 API/权益/节点凭据导出测试。所有写入在同一个 develop 工作区，按文件分工，保留既有未提交修改。Antigravity 只读设计和代码审查。

核心验收：所有权益逐字段不变（含关联续费订单与历史关闭交付状态）；旧 query/path token 和旧 claim 失败；新 token 可绑定原上限设备；旧 UUID 不出现在新配置/节点列表、ETag 变化；重试不二次重置；越权与续费子订单拒绝；重置与旧请求/观察任务/续费/流量上报交错不复活旧绑定或丢权益；失败事务全部回滚。真实多进程 SQLite 测试覆盖锁等待/双重置，数据库支持的行级并发单独说明。

先跑针对性 PHP/JS/浏览器检查，再跑仓库要求的完整 PHPUnit、JavaScript、PHPStan、Composer audit、部署 shell 语法、工作流校验、diff whitespace 检查。区分新引入失败和原工作区/环境问题。生产候选和公网浏览器、真实节点旧/新凭据验证属于后续发布验收，本次不部署。

## 7. 已实现的交付范围与验证说明

- 新增独立重置服务、POST 路由、审计迁移；现有二维码接口附带版本和重置能力。对既有工作区修改采用逐文件增量集成。
- 设备写入和初次交付写入在交付行锁内校验原 token；最终响应再次校验。已绑定异步路径不新增写锁，外部 SQLite writer lock 下的原读取回归仍通过。
- 前端订单/单张交付/批量卡片均可独立确认重置并就地刷新对应二维码 PNG；批量下载改为点击按钮逐张下载左上角带 `#序号 / 订阅名 / 订单号` 的 PNG；20 秒未收到响应时显示待恢复状态，显式重试使用原幂等键，原 POST 不自动重发。
- API/权益和真实重置并发测试合计 11 tests / 141 assertions。并发测试执行真实 TrafficFetchJob、季度续费和重置，验证无流量累加丢失、无到期时间覆盖；单次提交恰好派发一次节点同步，重放和回滚不派发。
- 旧请求真实进程暂停→重置→放行：已注册与新设备两种路径都拒绝旧请求，无响应配置和绑定复活；另有同步/异步、禁用 HWID、旧观察任务及历史状态测试。
- 浏览器测试使用本机 Chrome headless 与本地拦截的 API fixtures，加载当前 JS/CSS，验证 UI 合约与异步状态；不代表后端 HTTP 集成、生产候选资源身份或公网验收。
- MySQL 当前读修复经过代码检查，本轮未运行真实 MySQL 并发实验。SQLite 的实际并发仍受单写者约束；本设计未声称吞吐量或延迟基准。
- 上线后必须用同一客户重置前后的真实配置测试节点新连接：旧配置失败，新配置成功，原客户流量/到期时间连续；还需候选和公网资产身份、移动端浏览器验收。

## 8. 最终本地验证结果

2026-10-30，Windows / PHP 8.5.9 / 当前 develop 未提交工作区：

| 检查 | 结果 |
| --- | --- |
| 全部 PHPUnit（复审后） | 457 tests、5636 assertions，零失败，5 skipped；3 分 18 秒 |
| 本功能 API、权益、进程并发与旧请求竞态 | 原 19 tests、186 assertions 通过；复审新增/增强鉴权测试 15 tests、91 assertions 通过，权限/空关联/凭据格式 3 tests、17 assertions 通过；最终回滚注入替换后 API 7 tests、105 assertions 重跑通过 |
| 全部 JavaScript 与 CI scope | 225 + 9 = 234/234 通过 |
| 重置功能 Chrome headless（桌面/手机） | 13/13 通过；已接入 CI |
| PHPStan（完整 app，1 GB 上限） | 通过 |
| 部署脚本 bash 语法 | .github/scripts/*.sh、.docker/*.sh、deploy.sh、init.sh、update.sh 全部通过 |
| 工作流 actionlint 1.7.12 | 通过；外部 shellcheck/pyflakes 集成关闭 |
| git diff --check | 通过 |
| Composer audit --locked | 复审收尾已通过：symfony/mime 7.4.16 → 7.4.22，仅此一个包升级，composer.json 未修改 |

全套首轮的路由/迁移清单摘要失败已按本次新增路由、迁移更新，定向兼容检查及最终全套重跑均通过。复审后的完整日志位于 C:/Temp/xboardme-reset-review-phpunit.log、xboardme-reset-review-phpstan.log。原功能日志保留在 C:/Temp/xboardme-reset-phpunit-final.log、xboardme-reset-targeted.log、xboardme-reset-js.log；旧 xboardme-reset-audit.log 记录的是升级前漏洞状态，不代表当前审计结果。5 项 skipped 为现有环境相关集成测试；未声称真实 MySQL 并发或原生 Redis 全部验证通过。

Codex 子代理完成鉴权、前端、节点导出及锁顺序交叉审查。随后已取得 Antigravity 完整首轮审查，实际复核与处理见下节；不能把首轮审查计为无问题通过。

对照任务开始时的逐文件备份，复审修复后共有 14 个既有受跟踪文件发生本次范围内变动，清单保存于 C:/Temp/xboardme-reset-baseline-changed-files.json；既有无关修改未被覆盖。代码尚未提交、推送或部署，未执行真实生产凭据重置。

## 9. Antigravity 审查修复与依赖安全收尾

首轮提出 3 组 P1、4 组 P2。复核结果及处理：

2026-10-30 已通过 Computer Use 收取 Antigravity 会话“Xboardme 分销订阅重置代码审查”（eb91a764-af2e-4b6e-8d05-8087ea175b43）的最终只读复审。结论：当前整体重置的生产代码、测试与设计文档没有剩余 P1/P2 阻断缺陷，首轮 7 组问题均已闭环。复审逐项检查源码，并明确要求将混合工作区拆出实际提交后重新通过含 MySQL/Redis 的 CI 门禁；真实节点与发布资产验收仍未执行。该结论不是已发布或全链路生产验收证明。

| 发现 | 处理 |
| --- | --- |
| 遗留 getConfig 绕过 HWID | 分销内部客户统一拒绝，普通订阅兼容不变；补旧/新 token 与 HWID 开关测试 |
| MySQL 幂等快照读 | 首轮审查时观察的旧代码已被当前读修复；保留 lockForUpdate，明确锁序禁忌 |
| 取消确认后无法查看、跨标签页旧图/ZIP | 未提交操作不写 pending Map；单张、订单、批量轮询比较版本，先废弃旧资源，刷新后重建；异步生成序号阻止旧任务覆盖 |
| 拒绝响应仍发观察任务、检查位置和读库 | 拒绝/异常/非成功响应不发布 observation；主库新鲜度检查放在全部响应头处理之后；拒绝响应 no-store |
| claim 重定向窗口 | 发送 302 前复核原 token，失效 410；事务允许三次重试；作废 hash 的原像加 revoked 域标记 |
| UUID、空关联、停用账号 | 标准 UUID 与安全随机 token；空关联返回业务错误；二维码和关闭接口统一限制有效分销商；原有 no-store 已存在 |
| 测试入口缺失 | 增加路径式 token、legacy 接口、Surge/Surfboard managed URL、队列静默、claim 提交后撤销覆盖 |

不采用先查 token 不存在再保存作为全局唯一保证，因为它不能消除并发插入竞态；本次使用 128 位安全随机 token，显式排除旧值，不扩展全库唯一索引迁移。

额外集成修复：浏览器回归接入 package.json 和现有 CI browser job；审计失败回滚测试用 Connection::beforeExecuting 单次故障注入，避免 MySQL CREATE TRIGGER 隐式提交破坏 RefreshDatabase 外层事务。

安全升级依据：[Symfony 官方修复版本更正](https://symfony.com/blog/about-the-security-entries-in-symfony-5-4-55-6-4-49-7-4-22-and-8-1-10)。7.4.22 才实际包含 CVE-2026-45070 修复。锁定和本机安装版本均为 7.4.22，Composer audit 无漏洞；24 项 MIME 参数名/CRLF 拒绝、合法参数、序列化、NullTransport 验证通过，无外发邮件。Windows 依赖更新仅在命令中忽略既有 Horizon 的 pcntl/posix 环境检查，未改变项目平台约束。

## 10. 提交范围与 PR 准备条件

读取最新 origin 后，develop、HEAD、origin/main 均为 0235c10，main 祖先检查通过，安全 hooks 生效。本轮未提交、推送或创建 PR。

工作区在任务开始前已有订阅性能、批量交付、诊断、部署、admin 子模块等未提交修改。本功能在这些文件上增量实现，保留原工作。以 C:/Temp/xboardme-reset-baseline-20261030-025402 为基线生成本次增量，执行只读 git apply --check --cached 后确认：ClientController、DistributorController、UserRoute、DistributorHwidService 和 distributor.js 的增量不能直接应用到 HEAD。不能把“整个工作区测试通过”等同于“独立 reset 提交包已验证”。

独立提交须定向整理，或将必要前置功能明确组成可审阅提交：

- 当前控制器/HWID 服务依赖原未提交的 SubscriptionTiming、DistributorHwidObservationPublisher、UpdateDistributorHwidObservation、HwidObservationPersistenceException；保留异步路径还需 SubscriptionServiceProvider、HwidObservationConfigValidator、app/Queue、config/subscription.php 及相关配置注册。
- 分销批量交付前置功能与当前 UI、Controller、OrderService 交叠，不能整体拣选这些文件后声称只提交 reset。
- 新测试依赖 BuildsSubscriptionFixtures、InteractsWithIsolatedSubscriptionSqlite、LoopbackRespRedisServer 及其 daemon、sqlite-subscription-worker 和 sqlite-distributor-reset-worker，需显式纳入实际测试包。
- 排除无关诊断与部署改动、admin 子模块/样式、清理交接资料、.codex-browser-fixtures、__pycache__、订阅原型 HTML。不得执行 git add .。
- composer.lock 的 MIME 安全升级无上述交叠，可以单独形成依赖安全提交。

本地实现的验证结果供代码审查；当前混合工作区尚不能直接整体提交成范围清楚的 reset PR。实际提交范围形成后必须在该内容上验证 CI。真实节点旧/新凭据、候选与公网资产身份和浏览器验收仍属于发布门禁，不作为本地开发完成的替代证据。
