<?php
define("SOOLEWEB",1);
error_reporting(0);
include "inc.php";
include "app.php";
/*
*注意禁止开启协程功能,如果开启不得使用超全局变量,全局变量,静态类,单例数据库模式
*如果开启协程超全局变量需要使用协程上下文,数据库 redis等要用连接池
*/
if (version_compare(phpversion('swoole'), '4.6.2', '<')) {
    echo 'Requires version 4.6.2 or higher'."\r\n";
    exit(0);
}
$pidFile=sys_get_temp_dir().'/'.basename(__FILE__).'.pid';
$port=isset($argv[1])?intval($argv[1]):9501;
$demo=isset($argv[2])?intval($argv[2]):0;
if(checkRun($pidFile,$port)){
    return false;
}
// 创建一个共享内存表
$globalTable = new Swoole\Table(128);
$globalTable->column('time', Swoole\Table::TYPE_INT);
$globalTable->create();
$server = new Swoole\Http\Server("0.0.0.0", $port);
$conf=array(
    'max_request'=>10000,
    'pid_file'=>$pidFile,
    'dispatch_mode'=>7,//3 ro 7
    'task_max_request'=>10000,
    'worker_num'=>$demo?10:100,
    'package_max_length'=> 1024*1024*8,//8M
    'task_worker_num' =>2,
    'enable_coroutine' => false,
    'enable_reuse_port'=>true,
    'log_file' => SWROOT.'data/phperror/swoole.log.php',
    'log_level' => 4,
);
$server->set($conf);
$server->on('request', function ($request, $response)  use ($server,$globalTable,$demo) {
    $startTime=microtime(true);
    //保存请求时间
    $globalTable->set($server->worker_pid, [
        'time' => $startTime,
    ]);
    $app=new app($demo);
    $psr7Request=swooleToPsr7Request($request);
    $psr7Response=$app->handle($psr7Request);
    
    $server->send($response->fd,GuzzleHttp\Psr7\Message::toString($psr7Response));
    $server->close($response->fd);
    //删除请求时间
    $globalTable->del($server->worker_pid);
    //慢日志(大于300毫秒的)
    $runtime=microtime(true) - $startTime;
    if($runtime*1000>300){
        $server->task(['url'=>$request->server['request_uri'],'data'=>$_REQUEST, 'runtime'=>$runtime]);
    }
});
$server->on("workerError", function ($server, int $worker_id, int $worker_pid, int $exit_code, int $signal) {
    echo "WorkerError: Worker {$worker_id} error with exit code {$exit_code}, signal {$signal}\n";
});

// 监听Task事件
$server->on('Task', function ($http, $task_id, $worker_id, $data) use ($server) {
    $file = SWROOT.'data/phperror/swRequest.php';
    $line=date('Y-m-d H:i:s')." slow url:".$data['url'].' data:'.json_encode($data['data']).' runtime:'.number_format($data['runtime']*1000, 3)."ms\r\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    
});


//在worker进程执行的
$server->on('WorkerStart', function ($server, $worker_id)  use($globalTable,$pidFile){
    function_exists('opcache_reset') && opcache_reset();
    //task进程来触发
    if ($worker_id == $server->setting['worker_num']&&$server->taskworker) {
        file_put_contents($pidFile.'.manager', $server->manager_pid);
        $GLOBALS['lastTime']=time();
        //热重启检测
        Swoole\Timer::tick(1000*15, function ()  {
            if (checkChange()) {
               posix_kill(posix_getppid(), SIGUSR1);
            }
        });

        //卡死检测
        Swoole\Timer::tick(1000*60,function () use ($globalTable) {
            checkStuck($globalTable);
        });
    }
});
$server->start();
