# 晓白龍 eSchool 集成保护说明

晓白龍是教师工作区的一部分。更新 eSchool 时必须保留以下集成点：

- 教师菜单：`resources/views/layouts/sidebar.blade.php` 中的 `XIAOBAILONG-INTEGRATION` 标记块。
- 教师路由：`routes/web.php` 中受 `permission:xiaobailong-use` 保护的两个路由。
- 服务交换路由：`routes/api.php` 中的 `api/integrations/xiaobailong/exchange`。
- 控制器、中间件与配置：`XiaobailongController`、`XiaobailongExchangeController`、`XiaobailongServiceAuth`、`config/xiaobailong.php`。
- 教师嵌入页：`resources/views/teacher/xiaobailong.blade.php`。
- 新学校继承：`app/Services/SchoolDataService.php` 必须同时在权限清单和 Teacher 默认权限中保留 `xiaobailong-use`。
- 租户权限自检：`xiaobailong:reconcile-permissions` 命令及其每日计划任务。
- Nginx：`/xiaobailong/`、`/xiaobailong-api/` 和 Bowen SSO launch/complete 反向代理。
- Laravel 定时任务必须以 `www` 用户运行，避免生成 PHP-FPM 无法写入的 root 缓存目录。

每次发布新代码后执行：

```bash
sudo /usr/local/sbin/eschool-xiaobailong-check
```

出现失败时不要清空数据库或重新生成密钥，应先恢复上述源码集成点，再运行：

```bash
php artisan xiaobailong:reconcile-permissions --dry-run
php artisan xiaobailong:reconcile-permissions
php artisan optimize:clear
```

集成密钥只保存在生产 `.env`，不得提交到代码包或聊天记录。
