# 部署架构 V2：隔离拓扑与独立 Redis

## 状态和边界

本阶段交付的是可验证、显式启用的 `compose.v2.sample.yaml`，用于后续影子环境和迁移演练。它不会被现有生产工作流自动采用，也不会修改当前 Caddy 上游、旧 Compose 容器、生产数据库或生产 Redis。

本阶段包含：

- `edge`、`web`、`ws`、`horizon`、`scheduler`、`maintenance`、`redis` 单一职责服务；
- HTTP/WS 边缘网络、应用后端网络和受控出站网络；
- 独立命名卷和 Redis AOF 持久化；
- Redis Secret 注入、默认拒绝无认证访问、无宿主端口；
- 每个长期运行服务的内存、CPU、PID 和日志上限；
- 绑定发布实例的 WS、scheduler 健康信号；
- Compose 语义、Caddy 配置、Redis 认证和重启持久化测试。

本阶段不包含：

- 生产数据复制、Redis 双写或切流；
- Redis ACL 用户和按角色命令授权；
- WS `strict` 所有权模式；
- 生产发布工作流接入和旧锚点退役；
- Xboard-Node PR #1 的合并或部署。

## 拓扑

```text
host Caddy -> 127.0.0.1:${XBOARD_HTTP_PORT} -> edge
                                                   |-> web:7001
                                                   `-> ws:8076 (/ws)

web/ws/horizon/scheduler/maintenance -> backplane -> redis:6379
web/ws/horizon/scheduler/maintenance -> egress -> external dependencies
```

只有 `edge` 发布宿主端口，并强制绑定 `127.0.0.1`，模板不提供改成公网地址的变量。`XBOARD_HTTP_PORT` 没有默认值，启动前必须显式选择经预检确认未占用的端口，避免覆盖当前蓝/绿上游。`web`、`ws` 和 `redis` 均不可从宿主或公网直接访问。`edge` 与 `backplane` 是 Docker internal 网络；应用角色单独加入 `egress`，保证 SMTP、外部 API 或远程数据库仍可访问，同时 Redis 不具备出站网络。

`horizon` 和 `scheduler` 位于显式 `owners` profile；普通 `up` 不会启动它们，防止影子环境意外出现双队列消费者或双调度器。只有所有权交接门禁通过后，才允许显式启用 `--profile owners`。`maintenance` 同样位于独立 profile，不会作为常驻服务启动。

## 镜像和发布身份

`XBOARD_IMAGE` 必须是完整镜像摘要，例如 `ghcr.io/hao-monster/xboardme@sha256:<64 hex>`。所有应用角色必须使用完全相同的摘要。Caddy 和 Redis 也固定到官方多架构摘要，避免浮动标签在不同时间拉取不同内容。

`XBOARD_RELEASE_ID` 必须为当前候选发布的唯一标识。Compose 将它写入每个角色的 `RUNTIME_INSTANCE_ID`，健康检查只接受当前实例自己的心跳；兼容期内仍同时发布旧全局心跳，现有生产监控不会中断。

## Redis 安全和持久化

Redis 密码只从 `XBOARD_REDIS_PASSWORD_FILE` 指向的 Docker Secret 文件读取，不进入 Compose、镜像或命令行。Secret 必须至少 32 个字符，且只能包含 URL-safe Base64 字符。Redis：

- 只连接 `backplane`，不发布端口；
- 根文件系统只读，仅 `/data` 持久卷和受限 `/tmp` 可写；
- 开启 protected mode 和密码认证；
- 使用 AOF `everysec` 与 RDB 快照；
- 使用 `noeviction`，内存耗尽时显式失败，避免静默逐出队列、锁和在线状态；
- 默认 Redis 数据上限为 256 MiB，容器上限为 384 MiB；正式演练前必须按生产实际峰值复核。

Redis ACL 和密钥轮换属于后续独立阶段。本阶段继续使用 Redis 默认用户，以减少首次拓扑迁移变量。

## 数据卷

以下数据由 V2 命名卷持久化：

| 卷 | 路径 | 内容 |
|---|---|---|
| `app_data` | `/www/.docker/.data` | SQLite、session 等私有运行数据 |
| `app_logs` | `/www/storage/logs` | 应用日志 |
| `app_theme` | `/www/storage/theme` | 主题资源 |
| `app_knowledge` | `/www/storage/app/knowledge-attachments` | 知识附件 |
| `app_plugins` | `/www/plugins` | 插件文件 |
| `redis_data` | `/data` | AOF/RDB |

不能直接在生产对空卷执行 `up`。下一阶段必须先从只读备份恢复到新的 V2 卷，在隔离网络中完成数据库、Redis、附件、主题和插件一致性演练。

## 本地门禁

Compose 和 Caddy 静态验证：

```bash
bash .github/scripts/validate-v2-compose.sh
```

独立 Redis 认证和重启持久化验证：

```bash
bash .github/scripts/test-v2-redis.sh
```

上述测试使用临时 Secret 和独立 Compose project；Redis 测试退出时删除其容器、网络和测试卷，不接触现有 Compose project。

## 后续上线顺序

1. 建立只读生产备份并恢复到隔离 V2 卷。
2. 使用同一不可变镜像启动影子拓扑，不发布公网端口。
3. 验证数据库版本、Redis 数据、附件、主题、插件、队列、scheduler 和 WS。
4. 演练候选失败、Redis 重启、角色重启和完整回滚。
5. 在后续 PR 中把 V2 prepare/switch/rollback 和 `owners` profile 交接固化到生产工作流。
6. WS 保持 `rollout`，完成切流和旧 WS 退役后才进入 `strict`。
7. 独立执行 Redis ACL/密钥轮换，再观察并退役旧锚点。

任一阶段失败都停止推进，当前生产拓扑保持不变。V2 命名卷保留供诊断，只有确认不再需要时才通过明确授权删除。
