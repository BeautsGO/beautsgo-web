<?php
declare(strict_types=1);

namespace app;

use think\Service;

class AppService extends Service
{
    public function register(): void
    {
        // 注册全局服务
    }

    public function boot(): void
    {
        // 应用启动钩子
    }
}
