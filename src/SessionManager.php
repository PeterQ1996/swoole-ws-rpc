<?php

namespace WsRpc;

use Swoole\Lock;
use Swoole\Table;

class SessionManager {

    protected $server;

    protected $sessionId;

    /**
     * @var Table
     */
    protected $sessionIdMap;

    protected $savePath;

    /**
     * @var Lock
     */
    protected $lock;

    // 不允许修改session中的fd
    protected $protectFd = true;

    protected $fd = 0;

    protected $clearTaskTable;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->savePath = '/dev/shm/' . $server->getConfig()['app-name'] . '/' . 'session/';
        $this->init();
    }

    protected function init()
    {
        // fd 到 session 的映射表
        $this->sessionIdMap = $table = new Table(1024);
        $table->column('sessionId', Table::TYPE_STRING, 32);
        $table->create();
        // 创建读写锁
        $this->lock = $lock = new Lock(SWOOLE_RWLOCK);

        // 删除之前遗留的文件
        if (file_exists($this->savePath))
            echo shell_exec("rm -rf " . $this->savePath);
        mkdir($this->savePath, 0777, true);

        // 创建session清理任务表
        $this->clearTaskTable = $table = new \swoole_table(1024);
        $table->column('time', \swoole_table::TYPE_INT);
        $table->column('fd', \swoole_table::TYPE_INT);
        $table->column('sessionId', \swoole_table::TYPE_STRING, 32);
        $table->create();

        $masterPid = posix_getpid();
        $p = new \swoole_process(function (\swoole_process $process) use($masterPid) {
            swoole_set_process_name($this->server->getConfig()['app-name'] . ' session cleaner');
            swoole_timer_tick(5e3, [$this, 'clearSeesion']);
            swoole_timer_tick(1e3, function () use ($process, $masterPid) {
                if (!\swoole_process::kill($masterPid, 0)) {
                    dump('master: '.$masterPid.' gone, im going to die...');
                    exit(0);
                }
            });
        });
        $p->start();
    }

    // 清除失效的session的处理函数
    protected function clearSeesion () {
        $rows = [];
        $time = time();
        foreach($table = $this->clearTaskTable as $row)
        {
            if ($row['time'] <= $time) {
                $rows[] = $row;
            }
        }
        foreach ($rows as $row) {
            $file = $this->savePath . $row['sessionId'];
            if (file_exists($file))
                unlink($file);
            $table->del($row['sessionId']);
        }
    }

    // 切换session, 不同的连接使用不同的session,在调用get和set时如果省略sessionId参数,这里一定要调用这里
    public function resetSessionIdByFd($fd)
    {
        $this->fd = $fd;
        $key = 'session_' . $fd;
        $row = $this->sessionIdMap->get($key);
        if (!$row) {
            $sessionId = md5($fd . uniqid());
            $this->sessionId = $sessionId;
            $this->sessionIdMap->set($key, ['sessionId' => $sessionId]);
            $this->set('sessionId', $sessionId);
            $this->server->onNewSession($fd, $sessionId);
        } else {
            $sessionId = $row['sessionId'];
            $this->sessionId = $sessionId;
        }
        return $sessionId;
    }

    public function hasFd($fd){
        return $this->sessionIdMap->exist('session_' . $fd);
    }

    // 当连接断开的时候解绑fd和sessionid的关联
    public function dettachFd($fd) {
        dump('dettach ' . $fd);
        $sessionId = $this->sessionIdMap->get('session_' . $fd, 'sessionId');
        // 如果被迁移session, 有可能会不存在
        if ($sessionId) {
            dump('add clear session task ' . 'session_' . $fd .' ' . $sessionId);
            $this->sessionIdMap->del('session_' . $fd);
            $this->clearTaskTable->set($sessionId, [
                'time' => time() + 60 * 10,
                'sessionId' => $sessionId,
                'fd' => $fd
            ]);
        }
    }

    public function getFdBySesssionId($sessionId)
    {
        $fd = $this->get('fd', null, $sessionId);
        if (!$fd) return null;
        if (!$this->hasFd($fd)) return null;
        return $fd;
    }


    // 把session转移到新的fd上
    public function mvSession($sessionId, $fd) {
        if (!$this->exist($sessionId)) return false;
        $deleteTask = $this->clearTaskTable->get($sessionId);
        if (!empty($deleteTask)) {
            if ($deleteTask['time'] - time() < 2) return false;
            $this->clearTaskTable->del($sessionId);
        }
        $oldFd = $this->get('fd', null, $sessionId);
        if ($this->sessionIdMap->exist('session_' . $oldFd)) {
            $this->server->onSessionMv($oldFd, $fd);
            $ret = $this->sessionIdMap->del('session_' . $oldFd);
        }
        dump('mv session old fd ' .$oldFd);
        $this->changeFd($fd, $sessionId);
        $this->sessionIdMap->set('session_' . $fd, ['sessionId' => $sessionId]);
        return true;
    }

    // session中的fd字段是不允许外部修改的, 内部通过此方法修改
    protected function changeFd($fd, $sessionId) {
        $this->protectFd = false;
        $this->set('fd', $fd, $sessionId);
        $this->protectFd = true;
    }

    public function exist($sessionId) {
        return file_exists($this->savePath . $sessionId);
    }

    public function get($key, $default = null, $sessionId = null)
    {
        $sessionId or $sessionId =  $this->sessionId;
        // 判断session 文件是否存在
        if (!file_exists($this->savePath . $sessionId))
            return null;
        // 添加读锁
        $this->lock->lock_read();
        $str = file_get_contents($this->savePath . $sessionId);
        $this->lock->unlock();
        $data = unserialize($str);
        return $data[$key] ?? $default;
    }

    public function set($key, $data, $sessionId = null)
    {
        if ($key == 'fd' && $this->protectFd) return false;
        $sessionId or $sessionId =  $this->sessionId;
        $file = $this->savePath . $sessionId;
        $this->lock->lock();
        // 判断session 文件是否存在
        if (!file_exists($file))
            file_put_contents($file, serialize([$key => $data, 'fd' => $this->fd]));
        else {
            $str = file_get_contents($this->savePath . $sessionId);
            $ori = unserialize($str);
            $ori[$key] = $data;
            file_put_contents($file, serialize($ori));
        }
        $this->lock->unlock();
    }
}