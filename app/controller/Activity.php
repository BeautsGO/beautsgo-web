<?php
declare(strict_types=1);

namespace app\controller;

/**
 * 活动 —— /{lang}/activity  /{lang}/activity/:id
 *   - listing(): HospitalAdvertise/hospitalsList + HospitalAdvertise/pageList
 *   - detail():  Activity/projectDetail/:id + Marketing/projectList(相关)
 */
class Activity extends BaseController
{
    public function listing()
    {
        $page = max(1, (int) $this->request->param('page', 1));
        $hid  = (int) $this->request->param('h', 0);

        $hospitals = (array) ($this->api->get('/HospitalAdvertise/hospitalsList')['data'] ?? []);
        $resp = $this->api->get('/HospitalAdvertise/pageList', [
            'page' => $page, 'limit' => 10, 'h_id' => $hid,
        ]);
        $list  = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));

        $base = rtrim((string) config('seo.site_url'), '/');
        $canonical = $base . '/' . $this->langSeg() . '/activity' . ($hid ? '?h=' . $hid : '');
        $this->seo->setTdk('优惠活动 - 韩国医美 - BeautsGO', 'BeautsGO 韩国医美优惠活动汇总,折扣项目实时更新', '优惠,活动,医美折扣')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '活动', 'url' => '/activity']]);

        return $this->render('pages/activity/list', [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => max(1, (int) ceil($total / 10)),
            'hospitals'  => $hospitals,
            'currentHid' => $hid,
        ]);
    }

    public function detail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing activity id');
        $resp = $this->api->get('/Activity/projectDetail/' . $id);
        $info = (array) ($resp['data'] ?? []);
        if (empty($info)) $this->abort404('Activity not found');

        // 相关活动
        $related = (array) ($this->api->get('/Marketing/projectList', ['page' => 1, 'limit' => 6])['data']['list'] ?? []);

        $base = rtrim((string) config('seo.site_url'), '/');
        $canonical = $base . '/' . $this->langSeg() . '/activity/' . $id;
        $name = (string) ($info['name'] ?? '医美活动');
        $desc = mb_substr(strip_tags((string) ($info['content'] ?? $info['desc'] ?? $name)), 0, 155);
        $cover = '';
        if (!empty($info['banner']) && is_array($info['banner'])) {
            $cover = $info['banner'][0]['cover'] ?? ($info['banner'][0]['url'] ?? '');
        }
        $this->seo->setTdk($name . ' - 活动 - BeautsGO', $desc, '医美活动,韩国医美')
            ->setCanonical($canonical)
            ->setOg(['title' => $name, 'description' => $desc, 'image' => $cover, 'url' => $canonical, 'type' => 'article'])
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => '活动', 'url' => '/activity'],
                ['name' => $name, 'url' => '/activity/' . $id],
            ]);

        return $this->render('pages/activity/detail', [
            'info'    => $info,
            'cover'   => $cover,
            'related' => $related,
        ]);
    }

    private function langSeg(): string
    {
        return (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
    }
}
