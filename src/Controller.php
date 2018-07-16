<?php

namespace WsRpc;

class Controller {

    static function action($name)
    {
        return static::class . '@' .$name;
    }

    protected function success($data = []) {
        return [
            'success' => true,
            'data' => $data
        ];
    }

    public function onWorkerStart($worker_id)
    {

    }

    protected function fail($message = 'fail') {
        return [
            'success' => false,
            'message' => $message
        ];
    }
}