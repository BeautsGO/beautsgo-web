<?php
// +----------------------------------------------------------------------
// | BeautsGO 路由定义
// |   保持与 UniApp H5 端 URL 模式一致：/{lang}/hospital/{slug}
// +----------------------------------------------------------------------
use think\facade\Route;

// 全局占位符 pattern：slug 允许字母数字+连字符（默认只允许 \w 不含 -，会截断 slug）
$slugPattern = ['slug' => '[A-Za-z0-9\-]+'];

// 任何带尾斜杠的非根 URL → 301 redirect 到去掉斜杠的版本
// 避免 /cn/ /cn/hospital/ 等被 route_complete_match=true 拒匹配
$_uri  = $_SERVER['REQUEST_URI'] ?? '';
$_path = parse_url($_uri, PHP_URL_PATH) ?: '/';
if ($_path !== '/' && substr($_path, -1) === '/') {
    $qs = parse_url($_uri, PHP_URL_QUERY);
    $target = rtrim($_path, '/') . ($qs !== null && $qs !== '' ? '?' . $qs : '');
    header('Location: ' . $target, true, 301);
    exit;
}

// 首页
Route::get('/',  'Index/index');

// 默认语言（不带 lang 段，等价于 cn）
Route::get('hospital/:slug', 'Hospital/detail')->pattern($slugPattern);
Route::get('project/:slug',  'Project/detail')->pattern($slugPattern);
Route::get('doctor/:slug',   'Doctor/detail')->pattern($slugPattern);

// 用户态(登录/我的/预约)
use app\middleware\AuthMiddleware;
Route::group(':lang', function () {
    Route::get('login',           'Auth/loginPage');
    Route::post('login',          'Auth/submitLogin');
    Route::post('login/send-code','Auth/sendCode');
    Route::get('logout',          'Auth/logout');
})->pattern(['lang' => 'cn|zh|en|ja|th']);

Route::group(':lang', function () {
    Route::get('me',                 'Me/index');
    Route::any('me/profile',         'Me/profile');
    Route::get('me/collect',         'Me/collect');
    Route::get('me/order',           'Me/order');
    Route::get('me/news',            'Me/news');
    Route::get('me/system',          'Me/system');
    Route::get('me/news/:id',        'Me/newsDetail')->pattern(['id' => '\d+']);
    Route::get('me/order/:id',       'Me/orderDetail')->pattern(['id' => '\d+']);
    Route::get('me/record',          'Me/record');
    Route::get('me/express/:id',     'UserExtra/expressData')->pattern(['id' => '\d+']);
    Route::get('point/team',         'Points/team');
    Route::get('point/task-share',   'Points/taskShare');
    Route::any('point/share-submit', 'Points/shareSubmit');
    Route::get('point/order/:id',    'Points/orderDetail')->pattern(['id' => '\d+']);
    Route::any('me/feedback',        'Me/feedback');
    Route::get('me/wallet',          'Me/wallet');
    Route::get('me/points-log',      'Me/pointsLog');
    Route::get('me/settings',        'Me/settings');
    Route::any('me/change-phone',    'UserExtra/changePhone');
    Route::get('me/comments',        'UserExtra/myComments');
    Route::get('me/cases',           'UserExtra/myCases');
    Route::any('me/cases/new',       'UserExtra/newCase');
    Route::get('share',              'UserExtra/share');
    Route::get('point/:id/redeem',   'UserExtra/redeem')->pattern(['id' => '\d+']);
    Route::post('point/confirm',     'UserExtra/pointConfirm');
    Route::get('compare/select',     'Compare/select');
    Route::get('compare/add',        'Compare/add');
    Route::get('compare/save-image', 'Compare/saveImage');
    Route::get('compare',            'Compare/detail');
    Route::any('comment/publish',    'Comments/publish');
    Route::post('comment/add-tag',   'Comments/addTag');
    Route::get('chat',               'Chat/index');
    Route::any('chat/:hid',          'Chat/detail')->pattern(['hid' => '\d+']);
    Route::any('point/sign',         'Points/sign');
    Route::get('point/task',         'Points/task');
    Route::get('appointment',                'Appointment/form');
    Route::post('appointment',               'Appointment/submit');
    Route::get('appointment/select-hospital','Appointment/makeHospital');
})->pattern(['lang' => 'cn|zh|en|ja|th'])->middleware([AuthMiddleware::class]);

// 多语言路由组(列表+详情);必须放在 :lang 独立 rule 之前,避免单段 :lang 贪婪吃掉
Route::group(':lang', function () use ($slugPattern) {
    // 列表页(放在 :slug 之前,避免被吃)
    Route::get('hospital',  'Listing/hospitalList');
    Route::get('doctor',    'Listing/doctorList');
    Route::get('project',   'Listing/projectList');
    Route::get('search',    'Listing/search');
    // 项目分类列表 (id 老链接) + slug 新链接 /{lang}/projects/category/{slug}
    Route::get('project/cate/:id',          'Listing/projectList')->pattern(['id' => '\d+']);
    Route::get('projects/category/:slug',   'Listing/projectByCategory')->pattern($slugPattern);
    // 收藏代理(AJAX,静默 token 已写入 cookie)
    Route::post('collect',                  'Collect/toggle');
    // 分享行为上报代理
    Route::post('share/save',               'Share/save');
    // 案例
    Route::get('case',      'Cases/listing');
    Route::get('case/:id',  'Cases/detail')->pattern(['id' => '\d+']);
    // 活动
    Route::get('activity',      'Activity/listing');
    Route::get('activity/:id',  'Activity/detail')->pattern(['id' => '\d+']);
    // 评论列表 /comment/:type/:with_id
    Route::get('comment/:type/:with_id', 'Comments/listing')->pattern(['type' => '[123]', 'with_id' => '\d+']);
    // 积分商城
    Route::get('point/shop',   'Points/shop');
    Route::get('point/:id',    'Points/detail')->pattern(['id' => '\d+']);
    // 专题
    Route::get('topics',       'Topics/listing');
    // 静态页
    Route::get('about',          'Stat1c/about');
    Route::get('terms',          'Stat1c/terms');
    Route::get('privacy',        'Stat1c/privacy');
    Route::get('qualifications', 'Stat1c/qualifications');
    Route::get('point/info',     'Stat1c/pointInfo');
    Route::get('login/user',     'Stat1c/userAgreement');
    Route::get('login/conceal',  'Stat1c/conceal');
    Route::get('cancellation',   'Stat1c/cancellation');
    // 杂项页 (Misc)
    Route::get('contact',          'Misc/contact');
    Route::get('contact/detail',   'Misc/contactDetail');
    Route::get('customer-service', 'Misc/customerService');
    Route::get('skillmake',        'Misc/skillmake');
    Route::get('doc',              'Misc/doc');
    Route::get('sms-code',         'Misc/smsCode');
    // 医院价目表
    Route::get('hospital/:slug/price',      'Hospital/price')->pattern($slugPattern);
    Route::get('hospital/:slug/allproject', 'Hospital/allProject')->pattern($slugPattern);
    Route::get('hospital/:slug/caselist',   'Hospital/caseList')->pattern($slugPattern);
    Route::get('doctor/:slug/caselist',     'Doctor/caseList')->pattern($slugPattern);
    Route::get('project/:slug/caselist',    'Project/caseList')->pattern($slugPattern);
    // 详情页
    Route::get('hospital/:slug', 'Hospital/detail')->pattern($slugPattern);
    Route::get('project/:slug',  'Project/detail')->pattern($slugPattern);
    Route::get('doctor/:slug',   'Doctor/detail')->pattern($slugPattern);
})->pattern(['lang' => 'cn|zh|en|ja|th']);

// 不带 lang 的列表(默认 zh-Hans)
Route::get('hospital',  'Listing/hospitalList');
Route::get('doctor',    'Listing/doctorList');
Route::get('project',   'Listing/projectList');
Route::get('search',    'Listing/search');
Route::get('case',      'Cases/listing');
Route::get('case/:id',  'Cases/detail')->pattern(['id' => '\d+']);

// sitemap & robots
Route::get('sitemap.xml',  'Stat1c/sitemap');
Route::get('robots.txt',   'Stat1c/robots');

// 多语言首页(独立 rule,放在 group 之后)
Route::get(':lang', 'Index/index')->pattern(['lang' => 'cn|zh|en|ja|th']);

// MISS 兜底 —— 走 controller 以确保 SEO/i18n 变量注入到 base 模板
Route::miss('Stat1c/notFound');
