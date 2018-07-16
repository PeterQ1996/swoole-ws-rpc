<?php
namespace WsRpc;

class ResponseableException extends \Exception {

    public function toArray()
    {
        return [
            'message' => $this->message,
        ];
    }
}
