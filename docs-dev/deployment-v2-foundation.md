# 部署架构 V2：兼容基础层

## 本阶段目标

本阶段只建立可安全演进到 V2 的兼容基础，不切换生产容器拓扑，不停止旧容器，也不启用 Redis ACL。现有 `legacy` 全合一运行方式保持默认行为。

完成条件：

- 镜像可以按单一职责启动 `web`、`ws`、`horizon`、`scheduler`、`maintenance`；
- 专用角色不会在 Octane 内重复运行调度器；
- Redis TCP、Unix Socket、密码认证、ACL 认证和 `_FILE` 密钥输入具有统一配置；
- WS Redis 订阅经过连接、认证和全部频道确认后才进入 Ready；
- 新旧 WS 并存时不会由旧连接清除新连接的在线状态；
- 发布状态使用权限为 `0600` 的 JSON，只通过 `jq` 读取和原子更新，状态文件不会作为 Shell 代码执行；
- 每个运行角色具有可机器读取的健康检查。

## 运行角色

通过 `XBOARD_RUNTIME_ROLE` 选择角色：

| 角色 | 责任 | 是否运行 Octane 内置调度 tick |
|---|---|---|
| `legacy` | 保留现有全合一 Supervisor 行为 | 遵循现有 `ENABLE_SCHEDULER` |
| `web` | Octane HTTP | 否 |
| `ws` | 节点 WebSocket 和 Redis 推送订阅 | 否 |
| `horizon` | 队列消费 | 否 |
| `scheduler` | `schedule:work` | 否 |
| `maintenance` | 迁移、维护等一次性命令 | 否 |

专用角色由 `/run-role.sh` 启动；`legacy` 继续使用当前 Supervisor CMD。此次提交不改变现有发布脚本的生产拓扑，专用角色编排在后续拓扑 PR 中接入。

## Redis 配置和密钥

支持以下变量：

- `REDIS_HOST`、`REDIS_PORT`；
- `REDIS_USERNAME`、`REDIS_PASSWORD`；
- `REDIS_USERNAME_FILE`、`REDIS_PASSWORD_FILE`。

同一值不能同时配置内联变量和 `_FILE`。密钥文件必须存在、可读、非空且不包含 NUL；只移除文件末尾换行，不改变密钥中的空格。密钥在 Laravel 配置加载阶段读取。禁止在镜像构建期生成包含生产密钥的 `config:cache`；若运行时使用配置缓存，必须先挂载密钥文件并保护生成的缓存文件。

当前连接所有权 Lua 脚本要求生产 Redis 为单机或 Sentinel 主节点模式。Redis Cluster 跨槽仍由既有发布门禁禁止。

## WS 订阅与连接所有权

WS Ready 必须同时满足：

1. Redis 连接成功；
2. 如配置认证，AUTH 成功；
3. Redis 已确认 `node:push` 和 `node:connection-replaced` 两个频道；
4. WS 心跳与订阅 Ready 时间戳均未过期。

每个节点连接和机器连接都有带 TTL 的 Redis 所有权租约。新连接认领后发布替换事件；旧进程收到事件后关闭本地旧连接。关闭回调只有在 Redis 中仍是当前所有者时才能清理在线状态和设备状态，因此延迟到达的旧 `onClose` 不会破坏新连接。

机器的 `sync.nodes` 事件先验证机器所有权，再认领新节点、条件释放移除节点，最后更新进程内注册表，避免多个 WS 进程并存时由旧进程重新认领节点。

### 首次滚动升级

首次上线时旧 WS 不具备租约，因此采用两阶段模式：

- `rollout`：活跃租约优先；没有租约时暂时承认旧 `node_ws_alive` 心跳；
- `strict`：只承认活跃租约。

查看模式：

```text
php artisan node:connection-ownership status
```

只有确认所有旧 WS 已退役后才允许切换：

```text
php artisan node:connection-ownership strict --confirm-no-legacy-ws
```

回滚到仍不支持租约的版本前，先恢复兼容态：

```text
php artisan node:connection-ownership rollout
```

后续拓扑 PR 必须把上述顺序固化到门禁和自动回滚中，不能依赖人工记忆。

## 发布状态

正式发布链使用 `.codex-release/<release-id>/state.json`，Schema 版本为 `2`。所有字段名使用小写蛇形格式，除 `schema_version` 外的值均为字符串。

安全约束：

- 拒绝符号链接、错误 Schema、非法字段名和非字符串值；
- 新建与更新使用同目录临时文件和原子 `mv`；
- 文件权限固定为 `0600`；
- 发布脚本不得 `source` 状态文件，也不得使用 `eval`；
- 远程执行时，工作流先传输可信解析函数，再传输发布脚本；
- 服务器必须安装 `jq`，缺失时发布立即失败。

## 健康检查

统一命令：

```text
php artisan runtime:health <role>
```

输出单行 JSON 并使用退出码表示健康状态。所有角色检查数据库、Redis 和发布版本身份；`ws` 额外检查进程心跳与 Redis 订阅 Ready；`scheduler` 额外检查最近调度 tick。

## 本阶段明确不做

- 不创建或部署生产 V2 Compose；
- 不拆除当前 Compose 存储、Redis 和回滚锚点；
- 不启用 Redis ACL 或轮换生产密钥；
- 不切换 Caddy 上游或 WS 路由；
- 不启用连接所有权严格态；
- 不合并、不推送、不部署，除非另有明确授权。

## 后续 PR 顺序

1. V2 拓扑与独立 Redis：建立独立网络、数据卷、角色容器和资源限制。设计与本地门禁见 `docs-dev/deployment-v2-topology.md`。
2. 影子环境与数据演练：备份、恢复、迁移、回滚和故障注入。
3. WS 灰度切换：先 rollout，再切流并退役旧 WS，最后 strict。
4. Redis ACL 与密钥轮换：按角色最小权限授权并验证回滚。
5. 旧锚点退役：在观察窗口和恢复演练通过后执行。
