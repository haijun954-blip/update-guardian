# Update Guardian 0.1.0 验收报告

验收日期：2026-11-08

## 结论

WordPress 功能验收通过。WordPress 官方 Plugin Check 最终结果：

```text
Checks complete. No errors found.
```

允许进入 `v0.1.0` 发布阶段。

## 发布结果

- GitHub 公开仓库：<https://github.com/haijun954-blip/update-guardian>
- GitHub Release：<https://github.com/haijun954-blip/update-guardian/releases/tag/v0.1.0>
- 正式安装包：<https://github.com/haijun954-blip/update-guardian/releases/download/v0.1.0/update-guardian-0.1.0.zip>
- Release 状态：正式发布，非草稿，非预发布
- 线上安装包大小：47,366 字节
- 线上安装包 SHA-256：`0BC5A6C421AAC6CCA14D9530CB043E51BB873C537F09BCE3057C52360F37E84A`
- 线上安装包哈希与本地产物：一致

WordPress.org 插件目录尚未提交。该渠道需要单独的 WordPress.org 登录账号和审核流程。

## 验收环境

- WordPress 6.8
- PHP 8.3
- WordPress Playground 临时站点
- Google Chrome 无界面自动化
- WordPress 官方 Plugin Check 2.2.0

## 功能验收

| 场景 | 结果 |
| --- | --- |
| 插件安装与激活 | 通过 |
| 后台菜单与仪表盘加载 | 通过 |
| 更新前 ZIP 文件快照 | 通过 |
| 测试插件从 1.0.0 变为 2.0.0 | 通过 |
| 后台“一键回滚”恢复文件 | 通过 |
| 恢复后版本为 1.0.0 | 通过 |
| 恢复后状态为“已回滚” | 通过 |
| 恢复后风险分数归零 | 通过 |
| 紧急恢复入口生成与访问 | 通过 |
| 紧急恢复实际还原文件 | 通过 |
| 单次恢复令牌使用后失效 | 通过 |
| 设置保存与持久化 | 通过 |
| 恢复默认设置 | 通过 |
| 390px 移动端布局 | 通过 |
| 页面横向溢出 | 未发现 |
| JavaScript 运行时错误 | 未发现 |

## 静态与发布检查

| 检查 | 结果 |
| --- | --- |
| PHP 语法解析 | 12 个 PHP 文件全部通过 |
| JavaScript 语法检查 | 通过 |
| GPLv2 许可证声明 | 通过 |
| Plugin Check 全部类别 | 无错误 |
| 发布 ZIP 顶层目录 | `update-guardian/` |
| 测试文件进入发布包 | 无 |

## 已知边界

1. 回滚恢复的是插件文件，不会自动撤销插件执行的数据库迁移。
2. 通过 SFTP、Git 或主机面板手动覆盖文件的更新不会被拦截。
3. 当前没有截图级视觉差异比较，只覆盖可捕获的 PHP、浏览器、HTTP、编码和页面结构异常。
4. Nginx 站点应禁止直接访问 `/wp-content/update-guardian/`。
5. 整目录回滚采用同文件系统原子重命名。Playground 的外部目录挂载不允许验证该重命名动作，正式生产站仍应使用普通目录型插件再做一次验收。

