<?php
namespace WsRpc;

use Swoole\WebSocket\Frame;

class WsServerEventHandler {

    protected $server;

    protected $ws_table;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->ws_table = $table = new \swoole_table(4096);
        $table->column('ws', \swoole_table::TYPE_INT);
        $table->create();
    }

    public function onStart(\Swoole\Server $server)
    {
        swoole_set_process_name($this->server->getConfig()['app-name'] . ' master process');
        // 把pid写入到文件
        file_put_contents($this->server->getConfig()['temp-path'] . '/pid', $server->master_pid);
        dump("服务已启动, master进程id: " . $server->master_pid);
    }

    public function onManagerStart(\swoole_server $serv)
    {
        swoole_set_process_name($this->server->getConfig()['app-name'] . ' manager process');
    }


    public function onMessage(\Swoole\WebSocket\Server $server, Frame $frame)
    {
        try {
            $this->server->onRequest(json_decode($frame->data, true), $frame->fd);
        } catch (\Throwable $exception) {
            dump($exception->getMessage());
        }
    }

    public function onClose(\swoole_server $server, int $fd, int $reactorId)
    {
        if ($this->ws_table->exist('fd_' . $fd)) {
            $this->ws_table->del('fd_' . $fd);
            $this->server->onClose($fd, $reactorId);
        }
    }

    public function onWorkerStart($serv, $worker_id)
    {
        $controllers = $this->server->controllers();
        swoole_set_process_name($this->server->getConfig()['app-name'] . ' worker process, ' . $worker_id);
        foreach ($controllers as $controller) {
            $controller->onWorkerStart($worker_id);
        }
    }


    public function onOpen(\swoole_websocket_server $svr, \swoole_http_request $req) {
        $this->ws_table->set('fd_' . $req->fd, ['ws' => 1]);
    }

    public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        $path = realpath($this->server->getConfig()['public-path'] . $request->server['request_uri']);
        if (!is_file($path)) {
            if ($this->server->onHttpRequest($request, $response))
                return;
            $response->status(404);
            return $response->end('not found');
        }
        $response->header('Content-Type', $this->get_mime_type($path));
        $response->header('File-Provided-By', 'PeterQ/Swoole');
        $response->sendFile(realpath($path));
    }
    
    protected function get_mime_type($filename)
    {
        static $mime_types = array(

            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'swf' => 'application/x-shockwave-flash',
            'flv' => 'video/x-flv',

            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',

            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload',
            'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed',

            // audio/video
            'mp3' => 'audio/mpeg',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',

            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',

            // ms office
            'doc' => 'application/msword',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',

            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

        $ext = substr(strrchr($filename, '.'), 1);
        if (isset($mime_types[$ext])) {
            return $mime_types[$ext];
        } else {
            return 'application/octet-stream';
        }
    }

}
