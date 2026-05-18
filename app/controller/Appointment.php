<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;
use app\repository\HospitalRepository;
use app\repository\DoctorRepository;
use app\repository\ProjectRepository;

/**
 * 预约面诊
 *   GET  /{lang}/appointment?h_id=N&d_id=N&p_id=N    表单
 *   POST /{lang}/appointment                           提交
 */
class Appointment extends BaseController
{
    /**
     * 预约面诊表单(对齐 appointmentInput.vue)
     *   GET /Appointment/showForm  动态字段列表
     *   POST /Appointment/saveForm 提交
     */
    public function form()
    {
        $auth = new AuthService();
        $user = $auth->getCurrentUser();

        $hid = (int) $this->request->param('h_id', 0);
        $did = (int) $this->request->param('d_id', 0);
        $pid = (int) $this->request->param('p_id', 0);

        $hospital = $hid ? (new HospitalRepository($this->lang))->detailById($hid) : null;
        $doctor   = $did ? (new DoctorRepository($this->lang))->detailById($did)   : null;
        $project  = $pid ? (new ProjectRepository($this->lang))->detailById($pid)  : null;

        // 动态字段(对齐 appointmentInput.vue:110)
        $resp = $auth->call('GET', '/Appointment/showForm');
        $initForm = (array) ($resp['data'] ?? []);
        foreach ($initForm as &$f) $f['value'] = '';
        unset($f);

        $this->seo->setTdk('预约面诊 - BeautsGO', '在线预约面诊', '预约面诊')->buildOrganization();
        return $this->render('pages/appointment/form', [
            'user'     => $user,
            'hospital' => $hospital,
            'doctor'   => $doctor,
            'project'  => $project,
            'initForm' => $initForm,
            'error'    => (string) $this->request->param('error', ''),
            'success'  => '',
        ]);
    }

    public function submit()
    {
        $auth = new AuthService();
        // 基础字段
        $payload = [
            'h_id'   => (int) $this->request->param('h_id', 0),
            'd_id'   => (int) $this->request->param('d_id', 0),
            'p_id'   => (int) $this->request->param('p_id', 0),
            'remark' => (string) $this->request->param('remark', ''),
        ];
        // 动态字段(form[key]=value)
        $extra = (array) $this->request->param('form', []);
        foreach ($extra as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            $payload[$k] = is_array($v) ? '' : (string) $v;
        }

        $name = trim((string) ($payload['name'] ?? $this->request->param('name', '')));
        $phone = trim((string) ($payload['phone'] ?? $this->request->param('phone', '')));
        if ($name !== '') $payload['name'] = $name;
        if ($phone !== '') $payload['phone'] = $phone;

        if ($name === '' || $phone === '') {
            return redirect($this->request->url() . '?error=' . urlencode('请填写姓名与联系方式'));
        }

        // 对齐 appointmentInput.vue:186 POST Appointment/saveForm
        $resp = $auth->call('POST', '/Appointment/saveForm', $payload);
        if ($resp['ok']) {
            return $this->render('pages/appointment/success', [
                'message' => $resp['data']['msg'] ?? '预约提交成功,工作人员会尽快联系您',
            ]);
        }
        return redirect($this->request->url() . '?error=' . urlencode($resp['msg'] ?: '提交失败'));
    }

    /**
     * 选择医院页(beauts_app/pages/make/hospital.vue)
     *   GET  hospital/levelList   筛选项(医院等级)
     *   POST Hospital/searchByLevel  按等级 + 关键词搜索
     */
    public function makeHospital()
    {
        $auth = new AuthService();
        $page = max(1, (int) $this->request->param('page', 1));
        $level = (int) $this->request->param('level', 0);
        $q = trim((string) $this->request->param('q', ''));

        $levels = (array) ($auth->call('GET', '/hospital/levelList')['data']['list'] ?? []);
        $resp = $auth->call('POST', '/Hospital/searchByLevel', [
            'page' => $page, 'limit' => 10, 'keywords' => $q, 'level_id' => $level,
        ]);
        $list = (array) ($resp['data']['list'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));

        $this->seo->setTdk('选择预约机构 - BeautsGO', '选择想要预约的韩国医美机构', '预约,选择机构')
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '预约', 'url' => '/appointment'], ['name' => '选择机构', 'url' => '/appointment/select-hospital']]);

        return $this->render('pages/appointment/select-hospital', [
            'user'       => $auth->getCurrentUser(),
            'levels'     => $levels,
            'list'       => $list,
            'total'      => $total,
            'level'      => $level,
            'q'          => $q,
            'page'       => $page,
            'totalPages' => max(1, (int) ceil($total / 10)),
        ]);
    }
}
