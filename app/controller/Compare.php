<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;

/**
 * 对比 —— /{lang}/compare?type=1&ids=1,2,3   /{lang}/compare/select
 * type: 1 医院 / 2 医生 / 3 项目
 */
class Compare extends BaseController
{
    public function select()
    {
        $type = max(1, min(3, (int) $this->request->param('type', 1)));
        $this->seo->setTdk('选择对比项 - BeautsGO', '选择多个医院/医生/项目进行对比', '对比')
            ->buildOrganization();
        return $this->render('pages/compare/select', [
            'user' => (new AuthService())->getCurrentUser(),
            'type' => $type,
        ]);
    }

    public function detail()
    {
        $type = max(1, min(3, (int) $this->request->param('type', 1)));
        $ids  = trim((string) $this->request->param('ids', ''));
        $idArr = array_values(array_filter(array_map('intval', explode(',', $ids))));

        if (empty($idArr)) {
            return redirect('/' . $this->langSeg() . '/compare/select?type=' . $type);
        }

        $auth = new AuthService();
        $endpoint = ['', '/Compared/hospital', '/Compared/doctor', '/Compared/project'][$type];
        $resp = $auth->call('GET', $endpoint, ['ids' => implode(',', $idArr)]);
        $list = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);

        $typeName = ['', '机构', '医生', '项目'][$type];
        $this->seo->setTdk($typeName . '对比 - BeautsGO', $typeName . '横向对比', '对比')
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => $typeName . '对比', 'url' => '/compare?type=' . $type]]);

        return $this->render('pages/compare/detail', [
            'user'     => $auth->getCurrentUser(),
            'type'     => $type,
            'typeName' => $typeName,
            'ids'      => $idArr,
            'list'     => $list,
        ]);
    }

    /**
     * 候选添加页(beauts_app 的 doctor/add.vue + contrast/projectadd.vue 等价)
     * 根据 type 调对应的"分页列表"接口,展示卡片网格,前端 JS 加进 localStorage 对比池。
     */
    public function add()
    {
        $type = max(1, min(3, (int) $this->request->param('type', 1)));
        $q    = trim((string) $this->request->param('q', ''));
        $base = (int) $this->request->param('base', 0);  // 项目对比的基准项目 id
        $page = max(1, (int) $this->request->param('page', 1));

        $list = [];
        $total = 0;
        if ($type === 1) {
            $resp = $this->api->post('/Hospital/pageList', ['page' => $page, 'limit' => 10, 'key' => $q]);
            $list = (array) ($resp['data']['list'] ?? []);
            $total = (int) ($resp['data']['count'] ?? count($list));
        } elseif ($type === 2) {
            $resp = $this->api->post('/Doctors/pageList', ['page' => $page, 'limit' => 10, 'key' => $q]);
            $list = (array) ($resp['data']['list'] ?? []);
            $total = (int) ($resp['data']['count'] ?? count($list));
        } else { // 3
            if ($base > 0) {
                $resp = $this->api->post('/Project/sameCateProject/' . $base, ['page' => $page, 'limit' => 10]);
            } else {
                $resp = $this->api->post('/Project/pageList', ['page' => $page, 'limit' => 10, 'key' => $q]);
            }
            $list = (array) ($resp['data']['list'] ?? []);
            $total = (int) ($resp['data']['count'] ?? count($list));
        }

        $typeName = ['', '机构', '医生', '项目'][$type];
        $this->seo->setTdk('添加' . $typeName . '到对比 - BeautsGO', '搜索' . $typeName . ',加入对比清单', '对比,搜索')
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => $typeName . '对比', 'url' => '/compare?type=' . $type],
                ['name' => '添加', 'url' => '/compare/add?type=' . $type],
            ]);

        return $this->render('pages/compare/add', [
            'user'       => (new AuthService())->getCurrentUser(),
            'type'       => $type,
            'typeName'   => $typeName,
            'q'          => $q,
            'base'       => $base,
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => max(1, (int) ceil($total / 10)),
        ]);
    }

    private function langSeg(): string
    {
        return (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
    }
}
