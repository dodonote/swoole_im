<?php

//创建websocket服务器对象，监听0.0.0.0:9502端口
$ws = new swoole_websocket_server('0.0.0.0', 9502);
$ws->redis = new Redis();
$ws->redis->connect('127.0.0.1');
$i = 0; //定时任务重复次数，无意义
$ws->redis->set('PeopleNum', 0); //当前聊天室人数

/*
 * 监听WebSocket开始事件
 */
$ws->on('start', function () {
    //定时任务
//    swoole_timer_tick(1000, function ($timeId, $params) use (&$i) {
//        $i ++;
//        echo "hello, {$params} --- {$i}\n";
//        if ($i >= 5) {
//            swoole_timer_clear($timeId);
//        }
//    }, 'world');
    //推送到前端
});

/*
 * 监听WebSocket连接打开事件
 */
$ws->on('open', function ($ws, $request) {
//    print_r($request);
    if ($request->header['origin']) {
        if (1 == $request->fd) {
            $ws->redis->set('str', '');
        }
        $str = $ws->redis->get('str');
        wLog('open redis->str：'.$str);
        if (empty($str)) {
            $ws->redis->set('str', $request->fd.';');
        } else {
            $ws->redis->set('str', $str.$request->fd.';');
        }
        $toFront['text'] = "【用户{$request->fd}】欢迎来到在线聊天室！\n";
        $jsonToFront = json_encode($toFront);
        $msg = '【用户'.$request->fd."】进入\n";
        $ws->push($request->fd, $jsonToFront);

        $num = $ws->redis->get('PeopleNum');
        ++$num;
        $ws->redis->set('PeopleNum', $num);

        sendToAll($request, $ws, $msg, 1);
        sendToAllNumber($ws, $num);
        $ws->redis->set('ID'.$request->fd, $request->fd);
        echo $msg;
    } else {
        //WebSocketClient
        $msg = '【Client'.$request->fd."】偷偷的进来了\n";
        $ws->redis->set('ID'.$request->fd, 1);
        echo $msg;
    }
});

/*
 * 监听WebSocket消息事件
 */
$ws->on('message', function ($ws, $frame) {
//    print_r($frame);
    $ID = $ws->redis->get('ID'.$frame->fd);
    $data = json_decode($frame->data, true);
    wLog('message redis->ID：'.$ID);
    if (1 == $ID) {
        $msg = '【机器人'.$frame->fd."】说:{$data['content']}\n";
    } else {
        $msg = '【用户'.$frame->fd."】说:{$data['content']}\n";
    }

    sendToAll($frame, $ws, $msg, 1, 1);
});

/*
 * 监听WebSocket连接关闭事件
 * 删除已断开的客户端
 */
$ws->on('close', function ($ws, $fd) {
    $ID = $ws->redis->get('ID'.$fd);
    wLog('close redis->ID：'.$ID);
//    echo $ID;
    if (1 == $ID) {
        //WebSocketClient
        echo '【Client'.$fd."】跑了\n";
    } else {
        $str = $ws->redis->get('str');
        $Arr = explodeStr($str);
        if (empty($Arr)) {
            echo '用户'.$fd."退出\n无用户\n";
        } else {
            $string = '';
            foreach ($Arr as $k => $v) {
                if ($v == $fd) {
                    unset($Arr[$k]);
                } else {
                    $string = $string.$v.';';
                }
            }
            $ws->redis->set('str', $string);
            $msg = '【用户'.$fd."】退出\n";
            sendToAll($fd, $ws, $msg, 2, 1);

            $num = $ws->redis->get('PeopleNum');
            --$num;
            $ws->redis->set('PeopleNum', $num);

            sendToAllNumber($ws, $num);
            echo $msg;
        }
    }
});

$ws->start();

/**
 * 将字符串处理成数组.
 *
 * @param $str
 *
 * @return bool|string
 */
function explodeStr($str)
{
    if (2 == strlen($str)) {
        //只有一个数据 3;
        $Arr[] = substr($str, 0, 1);
//        print_r($Arr);
        return $Arr;
    } elseif (empty($str)) {
        $Arr = [];

        return $Arr;
    } else {
        $str = rtrim($str, ';');
        $Arr = explode(';', $str);

        return $Arr;
    }
}

/**
 * 群发给$Arr.
 */
function sendToAll($frame, $ws, $msg, $status, $exit = 0)
{
    wLog('sendToAll frame：'.var_export($frame, true));
    if (!empty($frame->header['origin']) || 1 == $exit) {
        $str = $ws->redis->get('str');
        $Arr = explodeStr($str);
        if (1 == $status) {
            $id = $frame->fd;
        } else {
            $id = $frame;
        }
        foreach ($Arr as $v) {
            if ($id == $v) {
                echo '【用户'.$id.'】广播给【用户'.$v.'】:'.$msg;
                $toFront['text'] = '<b style="color: crimson">【我】'.$msg.'</b>';
                $jsonToFront = json_encode($toFront);
                $ws->push(intval($v), $jsonToFront);
            } else {
                //发消息类型
                $kefu = 0; //客服
                if ($status == 1 && $exit == 1) {
                    $data = json_decode($frame->data, true);
                    $kefu = $data['kefu'];
                }
                echo '【用户'.$id.'】广播给【用户'.$v.'】:'.$msg;
                $toFront['text'] = $msg;
                $toFront['fd'] = $v;
                $jsonToFront = json_encode($toFront);
                if ($kefu > 0 && $v != $kefu) {
                    continue;
                }
                if ($kefu > 0) {
                    $ws->push(intval($kefu), $jsonToFront);
                } else {
                    $ws->push(intval($v), $jsonToFront);
                }
            }
        }
    } else {
    }
}

/**
 * 更新在线人数.
 *
 * @param $ws
 * @param $msg
 */
function sendToAllNumber($ws, $msg)
{
    $str = $ws->redis->get('str');
    wLog('sendToAllNumber str：'.$str);
    $Arr = explodeStr($str);
    foreach ($Arr as $v) {
        $toFront['ppp'] = $msg;
        $jsonToFront = json_encode($toFront);
        $ws->push(intval($v), $jsonToFront);
    }
}

/**
 * 写入日志
 */
function wLog($msg)
{
    $file = 'log.txt';
    $curDate = date('Y-m-d H:i:s');
    $msg = "【{$curDate}】".$msg.PHP_EOL;
    $fp = fopen($file, 'a+');
    fwrite($fp, $msg);
    fclose($fp);
    return true;
}
