<?php
// +----------------------------------------------------------------------
// | 应用公共函数
// +----------------------------------------------------------------------

/* ============================================================
 *  PHP 8 函数 polyfill (供 PHP 7.2 使用)
 * ============================================================ */

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle !== '' && strncmp((string) $haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;
        if ($needle === '' || $needle === $haystack) return true;
        if ($haystack === '') return false;
        $len = strlen($needle);
        return $len <= strlen($haystack) && substr_compare($haystack, $needle, -$len) === 0;
    }
}

/* ============================================================
 *  视图层快捷函数
 * ============================================================ */

if (!function_exists('e')) {
    /**
     * HTML 转义快捷函数
     */
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /**
     * 静态资源 URL（自动带版本号）
     */
    function asset($path, $type = 'css')
    {
        $ver  = config('seo.assets.' . $type . '_ver', date('Ymd'));
        $path = '/' . ltrim($path, '/');
        return $path . (strpos($path, '?') !== false ? '&' : '?') . 'v=' . $ver;
    }
}
