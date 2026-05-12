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
        $resp = $auth->call('GET', '/Chat/unreadMessageList', []);
        $list = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);

        $this->seo->setTdk('客服会话 - BeautsGO', '在线咨询会话', '客服,咨询')->buildOrganization();
        return $this->render('pages/chat/list', [
            'user' => $auth->getCurrentUser(),
            'list' => $list,
        ]);
    }

    public function detail(int $hid = 0)
    {
        if (!$hid) $this->abort404('Missing hospital id');
        $auth = new AuthService();

        // 初始化会话 / 拿 chat_gid + chat_uid
        $start = $auth->call('GET', '/Chat/start/' . $hid, []);
        $chat  = (array) ($start['data'] ?? []);
        $chatGid = (string) ($chat['chat_gid'] ?? '');
        $chatUid = (string) ($chat['chat_uid'] ?? '');

        $error = '';
        $sent  = false;
        if ($this->request->isPost()) {
            $content = trim((string) $this->request->param('content', ''));
            if ($content === '') {
                $error = '请输入消息内容';
            } else {
                $resp = $auth->call('POST', '/Chat/sendMessage', [
                    'h_id'    => $hid,
                    'content' => $content,
                    'chat_gid' => $chatGid,
                    'chat_uid' => $chatUid,
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
            'user'     => $auth->getCurrentUser(),
            'hospital' => $hospital,
            'history'  => $history,
            'chat_gid' => $chatGid,
            'chat_uid' => $chatUid,
            'error'    => $error,
            'sent'     => $sent,
        ]);
    }
}
