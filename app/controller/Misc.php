<?php
declare(strict_types=1);

namespace app\controller;

/**
 * 杂项页 —— 无需登录态的简单页面
 *   /{lang}/contact          客服 iframe
 *   /{lang}/contact/detail   同上(详情入口)
 *   /{lang}/customer-service 加客服(二维码)
 *   /{lang}/skillmake/:hslug?/:pslug?  项目预约(背景图导流)
 *   /{lang}/doc              文档/数据表(file project)
 *   /{lang}/sms-code         国际区号选择列表
 */
class Misc extends BaseController
{
    public function contact()
    {
        // 真实端 web-view src
        $url = 'https://kf.yestokr.com/30020?p=yestokr.com&os=11';
        $this->seo->setTdk('联系客服 - BeautsGO', '在线联系 BeautsGO 韩国医美客服', '客服,在线咨询')
            ->setCanonical($this->canonical('contact'))
            ->buildOrganization();
        return $this->render('pages/misc/contact', ['iframeSrc' => $url, 'title' => '联系客服']);
    }

    public function contactDetail()
    {
        return $this->contact();
    }

    public function customerService()
    {
        $cfg = $this->api->get('/getConfig');
        $list = (array) ($cfg['data']['platformConfig']['customer_service'] ?? []);
        $wxCode = $list[0] ?? '';

        $this->seo->setTdk('添加客服 - BeautsGO', '长按保存二维码,添加 BeautsGO 专属客服', '客服,二维码')
            ->setCanonical($this->canonical('customer-service'))
            ->buildOrganization();
        return $this->render('pages/misc/addkefu', ['wxCode' => $wxCode]);
    }

    public function skillmake()
    {
        // beauts_app 此页用作"项目预约入口"的背景图引导,
        // SSR 端只做静态背景 + 跳到 /appointment 表单页
        $hSlug = (string) $this->request->param('h', '');
        $pSlug = (string) $this->request->param('p', '');
        $this->seo->setTdk('预约项目 - BeautsGO', '通过 BeautsGO 预约韩国医美项目', '预约,项目预约')
            ->setCanonical($this->canonical('skillmake'))
            ->buildOrganization();
        return $this->render('pages/misc/skillmake', [
            'h' => $hSlug,
            'p' => $pSlug,
            'bgImage' => 'https://beautsgoimg.59w.net/20240906092129/66da5919194c9.png',
        ]);
    }

    public function doc()
    {
        $resp = $this->api->get('/fileProject/sheetList');
        $sheets = (array) ($resp['data'] ?? []);
        $active = (int) $this->request->param('s', 0);
        $rows = [];
        $columns = [];
        if (!empty($sheets)) {
            $sheetId = $sheets[$active]['id'] ?? ($sheets[0]['id'] ?? 0);
            if ($sheetId) {
                $dataResp = $this->api->post('/fileProject/sheetData', ['sheet_id' => $sheetId]);
                $columns = (array) ($dataResp['data']['columns'] ?? []);
                $rows    = (array) ($dataResp['data']['data'] ?? []);
            }
        }
        $this->seo->setTdk('数据表 - BeautsGO', 'BeautsGO 平台数据表', '数据')
            ->setCanonical($this->canonical('doc'))
            ->buildOrganization();
        return $this->render('pages/misc/doc', [
            'sheets'  => $sheets,
            'active'  => $active,
            'columns' => $columns,
            'rows'    => $rows,
        ]);
    }

    public function smsCode()
    {
        $resp = $this->api->get('/Common/countryList');
        $list = (array) ($resp['data'] ?? []);
        $this->seo->setTdk('选择国家区号 - BeautsGO', '选择国际电话区号', '区号,国际电话')
            ->setCanonical($this->canonical('sms-code'))
            ->buildOrganization();
        return $this->render('pages/misc/sms-code', ['list' => $list]);
    }

    private function canonical(string $path): string
    {
        $base = rtrim((string) config('seo.site_url'), '/');
        $seg  = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        return $base . '/' . $seg . '/' . $path;
    }
}
