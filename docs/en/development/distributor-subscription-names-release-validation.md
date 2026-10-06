# 分销订阅名称上线前修复与验证

本记录补充此前的命名测试报告，覆盖用户授权推送、合并和流水线发布后完成的测试阻塞修复。实际客户端导入及远端发布状态仍单独验收，不以本地测试或历史发布成功替代。

## 修复内容

- 将3个真实DDL迁移用例移入 `DistributorSubscriptionNameMigrationTest`；普通17个业务用例继续使用事务隔离。MySQL DDL隐式提交不再破坏测试框架的保存点状态。
- 迁移专用类每例 `migrate:fresh`，结束时 `db:wipe` 并断言清理成功，在 `finally` 重置框架迁移状态。本次目标迁移的 `down/up`、重入和部分DDL恢复依旧显式测试；不调用无关历史迁移的整条清理链。
- 原20个测试方法和业务断言完整保留，增加3个清理成功断言。第一次改用默认 `DatabaseMigrations` 时，历史迁移的清理逻辑先后暴露套餐周期键和SQLite旧notice索引问题；最终采用上述独立schema生命周期，没有修改历史生产迁移或弱化断言。
- 发布浏览器夹具增加最长30个UTF-16单位的新名称和独立旧订单。新订单显示名称并保留交易号，旧订单继续显示原号；原布局、设备、操作和价格自动隐藏检查保留。
- 发布检查从预期完整SHA对应的可信Git资源判断名称能力，因此兼容仍在运行的旧版本和回滚目标。不得根据下载到的旧资源自动降低候选版本要求。
- 修复浏览器检查假阳性：成功条件必须是准确的 `mobile-smoke-result` 结果节点为PASS，不能在整个HTML中搜索脚本源码里的PASS字样。

## Red → Green

MySQL原失败：独立运行部分DDL恢复测试稳定返回 `SQLSTATE[42000]: 1305 SAVEPOINT trans2 does not exist`，退出码2。最终隔离生命周期在MySQL8.0和SQLite的两类联合专项均通过20项、587断言，没有跳过。

浏览器原失败判定缺陷：预期新版本但服务旧资源时，7个真实Chrome结果节点均为FAIL，旧脚本却退出0。修复后，同一负例在第一个视口即退出1；当前资源和旧版本各自正确模式下仍通过全部7个视口。结果节点回归测试先RED后GREEN。

## 本地证据

环境为Windows、PHP8.5.9、PHPUnit12.5.33、MySQL8.0.44、SQLite和真实Chrome；MySQL使用全新临时数据目录、独立localhost端口和进程级PDO扩展配置。宿主日志包含明确ISO时区偏移；北京时间为UTC+08:00。

| 检查 | 结果 | 数量/断言 | 退出码 | 墙钟耗时 |
| --- | --- | --- | --- | --- |
| MySQL命名专项 | PASS | 20项/587断言 | 0 | 79.742秒 |
| SQLite命名专项 | PASS | 20项/587断言 | 0 | 13.745秒 |
| MySQL完整PHP回归 | PASS | 299项/3330断言 | 0 | 220.255秒 |
| SQLite完整PHP回归 | PASS | 299项/3330断言 | 0 | 66.494秒 |
| JavaScript | PASS | 125项 | 0 | 约3.30秒 |
| PHPStan | PASS | 无错误 | 0 | 约2.69秒 |
| Composer audit | PASS | 无安全公告 | 0 | 约4.92秒 |
| 发布脚本语法、workflow YAML、diff | PASS | 70条检查 | 0 | 7.934秒 |
| 当前资源、新名称检查 | PASS | 7个视口 | 0 | 36.30秒 |
| 旧资源、旧版本兼容检查 | PASS | 7个视口 | 0 | 34.52秒 |
| 新预期配旧资源 | PASS，正确拒绝 | 结果节点FAIL | 1，预期 | 5.73秒 |
| 非法能力参数 | PASS，正确拒绝 | 参数校验失败 | 1，预期 | 0.16秒 |
| 发布资源与工作流PHP专项 | PASS | 18项/347断言 | 0 | 1.842秒 |

完整命名专项命令为 `php vendor/bin/phpunit --filter DistributorSubscriptionName tests/Feature/Distributor --do-not-cache-result`。MySQL使用 `--no-configuration --bootstrap vendor/autoload.php`、仅测试进程启用pdo_mysql，并在执行前核对连接确实指向本轮隔离数据库。完整回归采用相同MySQL环境运行 `tests`，SQLite采用仓库 `phpunit.xml`。

证据目录：

- `C:/Temp/xboard-prelaunch-20261007-mysql-fix-5cee3974d24f`：RED、最终专项、完整回归、JUnit、命令时间、源文件哈希、数据库生命周期。
- `C:/Temp/xboard-served-name-gate-5c4c25b9`：实际shell/Chrome/localhost HTTP正反例、DOM、原假阳性和修复后的结果。
- `C:/Temp/xboard-release-naming-20261006-095448-7b62fde1`：JavaScript、PHPStan、依赖审计、发布语法检查。

## 证据边界与发布要求

- PHP8.5下现有PDO常量出现1类弃用提示；MySQL全套另有未修改区域的知识库审计action长度告警，未造成测试失败。正式PHP8.3/8.4、MySQL5.7/8.4结果以本PR/最终main CI为准。
- 没有收集行/分支覆盖率，actionlint未安装。YAML解析不代表远端Actions执行成功。
- 真实并行购买压力测试及Karing、标准FlClash、Clash Verge实际导入、更新、扫码和连接仍未执行，QA08不能标记完成。当前浏览器证据来自真实服务资源加受控订单夹具，不会创建生产付费订单。
- 用户明确授权继续推送、合并及流水线部署；该授权不等同于上述客户端业务验收已完成。生产发布必须通过最终完整main SHA的构建、签名镜像解析、预检、数据库clone迁移、认证smoke、实际资源浏览器检查、切流后的公网复验及失败自动回滚。
- 新迁移已列入allowlist，新增可空列和唯一索引，不回填历史订阅。普通应用回滚保留新增列和已分配名称，不执行破坏性migration down。
