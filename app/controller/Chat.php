<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;
use app\repository\HospitalRepository;

/**
 * 客服 IM(简化版,SSR 端用 form 同步刷新)
 *   GET /{lang}/chat               未读会话列表
 *   GET /{lang}/chat/:hid          单个医院会话历史
 *   POST /{lang}/chat/:hid         发送消息(form,刷新返回)
 */
class Chat extends BaseController
{
    public function index()
    {
        $auth = new AuthService();
        // 1:1 对齐 chatList.vue:186 $http.get('Chat/unreadMessageList')
        $resp = $auth->call('GET', '/Chat/unreadMessageList', []);
        $list = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);

        // 归一化嵌套字段(对齐 vue filteredChatList computed map):
        //   item.hospital.name → hospitalName
        //   item.hospital.cover[0].url → hospitalCover
        //   item.last_content → displayContent
        //   item.last_timestamp → displayTime
        //   item.unread_count → unreadCount
        foreach ($list as &$it) {
            $hosp = $it['hospital'] ?? [];
            $it['hospitalName']  = $hosp['name'] ?? ($it['hospital_name'] ?? ($it['name'] ?? '客服'));
            $cov = $hosp['cover'] ?? null;
            if (is_array($cov)) {
                $first = $cov[0] ?? $cov;
                $it['hospitalCover'] = $first['url'] ?? ($first['cover'] ?? '');
            } else {
                $it['hospitalCover'] = (string) ($it['cover_url'] ?? $it['cover'] ?? '');
            }
            $it['displayTime']    = $it['last_timestamp'] ?? ($it['last_time'] ?? '');
            $it['displayContent'] = $it['last_content']  ?? ($it['last_message'] ?? '');
            $it['unreadCount']    = (int) ($it['unread_count'] ?? 0);
            $it['hospitalId']     = (int) ($hosp['id'] ?? ($it['h_id'] ?? ($it['hospital_id'] ?? 0)));
        }
        unset($it);

        // 空会话时回落到医院推荐列表(对齐 vue chatList.length === 0 → hospitalList)
        $hospitalList = [];
        if (empty($list)) {
            try {
                $hr = $auth->call('GET', '/getHospital', ['page' => 1, 'limit' => 10]);
                $hospitalList = (array) ($hr['data']['list'] ?? []);
                foreach ($hospitalList as &$h) {
                    if (empty($h['cover_url'])) {
                        $c = $h['cover'] ?? null;
                        if (is_array($c)) {
                            $first = $c[0] ?? $c;
                            $h['cover_url'] = $first['url'] ?? ($first['cover'] ?? '');
                        }
                    }
                    if (empty($h['slug'])) $h['slug'] = (string) ($h['id'] ?? '');
                }
                unset($h);
            } catch (\Throwable $e) {}
        }

        $this->seo->setTdk('客服会话 - BeautsGO', '在线咨询会话', '客服,咨询')->buildOrganization();
        return $this->render('pages/chat/list', [
            'user'         => $auth->getCurrentUser(),
            'list'         => $list,
            'hospitalList' => $hospitalList,
        ]);
    }

    public function detail(int $hid = 0)
    {
        if (!$hid) $this->abort404('Missing hospital id');
        $auth = new AuthService();

        // 初始化会话 / 拿 chat_gid + chat_uid + 进度状态 + 快捷回复
        $start = $auth->call('GET', '/Chat/start/' . $hid, []);
        $chat  = (array) ($start['data'] ?? []);
        $chatGid = (string) ($chat['chat_gid'] ?? '');
        $chatUid = (string) ($chat['chat_uid'] ?? '');
        $progressSteps = (array) ($chat['lockAptCrmStatus'] ?? []);
        $progressDesc  = (string) ($chat['lockAptCrmDesc'] ?? '');
        $quickReplyList = (array) ($chat['quickReplyList'] ?? []);

        $error = '';
        $sent  = false;
        if ($this->request->isPost()) {
            $content = trim((string) $this->request->param('content', ''));
            if ($content === '') {
                $error = '请输入消息内容';
            } else {
                // 1:1 对齐 chat.vue sendMessage payload
                // content 保留换行(原 vue:replace(/\r\n|\r/g, '\n'))
                $content = str_replace(["\r\n", "\r"], "\n", $content);
                $resp = $auth->call('POST', '/Chat/sendMessage', [
                    'chat_gid' => $chatGid,
                    'chat_uid' => $chatUid,
                    'content'  => $content,
                ]);
                if ($resp['ok']) $sent = true;
                else $error = $resp['msg'] ?: '发送失败';
            }
        }

        // 历史
        $history = [];
        if ($chatGid && $chatUid) {
            $h = $auth->call('GET', '/Chat/history/' . $chatGid . '/' . $chatUid, []);
            $history = (array) ($h['data']['list'] ?? $h['data'] ?? []);
        }

        // 医院信息
        $hospital = (new HospitalRepository($this->lang))->detailById($hid);

        $this->seo->setTdk(($hospital['name'] ?? '客服') . ' 在线咨询', '客服咨询', '客服')->buildOrganization();
        return $this->render('pages/chat/detail', [
            'user'           => $auth->getCurrentUser(),
            'hospital'       => $hospital,
            'history'        => $history,
            'chat_gid'       => $chatGid,
            'chat_uid'       => $chatUid,
            'error'          => $error,
            'sent'           => $sent,
            'progressSteps'  => $progressSteps,
            'progressDesc'   => $progressDesc,
            'quickReplyList' => $quickReplyList,
        ]);
    }
}
