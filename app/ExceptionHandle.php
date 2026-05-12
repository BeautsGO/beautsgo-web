<?php
declare(strict_types=1);

namespace app;

use think\exception\Handle;
use think\exception\HttpException;
use think\Response;
use Throwable;

class ExceptionHandle extends Handle
{
    /**
     * 不需要记录到日志的异常
     */
    protected $ignoreReport = [
        \think\exception\HttpException::class,
        \think\exception\HttpResponseException::class,
        \think\exception\ValidateException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        // 404 走自定义模板
        if ($e instanceof HttpException && $e->getStatusCode() === 404) {
            return view('pages/error/404')->code(404);
        }
        return parent::render($request, $e);
    }
}
