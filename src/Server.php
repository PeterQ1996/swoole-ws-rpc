<?php

namespace WsRpc;

class Server
{

    protected $config;

    protected $defaultConfig = [
        'ip'   => '0.0.0.0',
        'port' => '8080',
        'app-name' => 'ws-rpc',
        'ws-server' => []
    ];

    /**
     * @var \Swoole\WebSocket\Server;
     */
    protected $wsServer;

    /**
     * @var SessionManager
     */
    protected $session;

    protected $contorllers = [];

    protected $cbs = [];

    public function getConfig()
    {
        return $this->config;
    }

    public function getRealServer()
    {
        return $this->wsServer;
    }

    public function __construct($config)
    {
        $this->defaultConfig['temp-path'] = realpath('.'). '/temp';
        $this->defaultConfig['public-path'] = realpath('.') . '/public';
        $this->config = array_replace_recursive($this->defaultConfig, $config);
        // 创建一个websocket服务器
        $this->wsServer = new \Swoole\WebSocket\Server($this->config['ip'], $this->config['port']);
    }

    public function addController($name, Controller $controller)
    {
        $this->contorllers[$name] = $controller;
    }

    public function controllers()
    {
        return $this->contorllers;
    }

    public function setCb($name, $cb)
    {
        $this->cbs[$name] = $cb;
    }

    public function bootstrap()
    {
        // 创建session
        $this->session = new SessionManager($this);
        // 设置属性
        $this->wsServer->set($this->config['ws-server']);
        // 注册事件监听
        $this->registerServerEvents();
        // 启动服务器
        $this->addController('system', new SystemContorller($this, $this->session));
        $this->wsServer->start();
    }

    public function onRequest($data, $fd)
    {
        try {
            $this->validate($data);
            $func = explode('.', $data['func']);
            if (count($func) < 2)
                array_unshift($func, 'default');
            list($controller, $method) = $func;
            $method = 'action' . ucfirst($method);
            if (!isset($this->contorllers[$controller]))
                throw new ResponseableException('控制器不存在');
            $controller = $this->contorllers[$controller];
            if (!method_exists($controller, $method))
                throw new ResponseableException('控制器方法不存在');
            if ($controller instanceof SystemContorller) {
                $result = call_user_func([$controller, $method], $fd, $data['param']);
            } else {
                $this->session->resetSessionIdByFd($fd);
                $result = call_user_func([$controller, $method], $this->session, $data['param']);
            }
            $this->wsServer->push($fd, json_encode([
                'type' => 'result',
                'result' => $result,
                'reqid' => $data['reqid']
            ]));
        } catch (\Throwable $exception) {
            $error = $exception instanceof ResponseableException ? $exception->toArray() : [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTrace()
            ];
            $this->wsServer->push($fd, json_encode([
                'type' => 'error',
                'error' => $error,
                'reqid' => $data['reqid'] ?? null
            ]));
        }
    }

    protected function validate($data)
    {
        $keys = array_keys($data);
        $need = ['func', 'param', 'reqid'];
        if (count(array_intersect($keys , $need)) < count($need) || !$data['func'])
            throw new ResponseableException('缺少参数');
    }

    public function onSessionMv($oldFd, $newFd)
    {
        $this->sendEventToFd($oldFd, 'session.moved', $newFd);
        swoole_timer_after(2e3, function () use ($oldFd) {
            $this->wsServer->close($oldFd);
        });
    }

    public function onNewSession($fd, $sessionId) {
        $this->sendEventToFd($fd, 'session.new', $sessionId);
    }

    public function sendEventToFd($fd, $event, $data)
    {
        $this->wsServer->push($fd, json_encode([
            'type' => 'event',
            'event' => $event,
            'data' => $data,
        ]));
    }

    public function notify($sessionId, $event, $data)
    {
        $fd = $this->session->getFdBySesssionId($sessionId);
        if (!$fd) return false;
        $this->sendEventToFd($fd, $event, $data);
        return true;
    }

    public function onClose($fd, $reactorId)
    {
        try {
            if (($cb = $this->cbs['disconnect'] ?? null) && $this->session->hasFd($fd)) {
                $this->session->resetSessionIdByFd($fd);
                $cb($this->session);
            };
        } catch (\Throwable $exception) {
            throw $exception;
        } finally {
            $this->session->dettachFd($fd);
        }
    }


    public function onHttpRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        if (!isset($this->cbs['http-request']))
            return false;
        $this->cbs['http-request']($request, $response, $this->session);
        return true;
    }

    protected function registerServerEvents()
    {
        $handler = new WsServerEventHandler($this);
        $methods = get_class_methods(get_class($handler));
        foreach ($methods as $m) {
            if (strpos($m, 'on') === 0)
                $this->wsServer->on(substr($m, 2), [$handler, $m]);
        }
    }
}
