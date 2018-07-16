<?php

namespace WsRpc;

class SystemContorller extends Controller {

    protected $server;

    protected $session;

    public function __construct(Server $server, SessionManager $sessionManager)
    {
        $this->server = $server;
        $this->session = $sessionManager;
    }

    // 恢复之前的会话
    public function actionResume($fd, $param)
    {
        $sessionId = $param['sessionId'];
        return $this->session->mvSession($sessionId, $fd) ? $this->success() : $this->fail();
    }

    // 心跳
    public function actionPing($fd, $param)
    {
        return 'pong';
    }

}
