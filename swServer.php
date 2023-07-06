<?php
ini_set('opcache.enable',0);
ini_set('opcache.enable_cli',0);
define("SWROOT",str_replace(basename(__FILE__),'',__FILE__).'../../');
/*
*注意禁止开启协程功能,如果开启不得使用超全局变量,全局变量,静态类,单例数据库模式
*如果开启协程超全局变量需要使用协程上下文,数据库 redis等要用连接池
*/
if (version_compare(phpversion('swoole'), '4.5.0', '<')) {
    echo 'Requires version 4.6.2 or higher'."\r\n";
    exit(0);
}
$pidFile=sys_get_temp_dir().'/'.basename(__FILE__).'.pid';
$port=isset($argv[1])?intval($argv[1]):9501;
if(file_exists($pidFile)){
    $pid = intval(file_get_contents($pidFile));
    if (posix_kill($pid, SIG_DFL)) {//判断该进程是否正常运行中
        return $pid;
    } 
}

define("SOOLEWEB",1);
error_reporting(0);
// 创建一个共享内存表
$globalTable = new Swoole\Table(256);
$globalTable->column('time', Swoole\Table::TYPE_INT);
$globalTable->create();

$server = new Swoole\Http\Server("0.0.0.0", $port);
$conf=array(
    'max_request'=>10000,
    'pid_file'=>$pidFile,
    'dispatch_mode'=>3, 
    'task_worker_num' =>2,//这个只能一个进程否则.. 
    'enable_coroutine' => false,
);
$server->set($conf);
$server->on('request', function ($request, $response)  use ($server,$globalTable) {
    //注册异常返回
    register_shutdown_function(function () use ($response) {
        shutdownCall($response);
    });

    //处理GET POST REQUEST $_FILES
    $_GET=$request->get;
    $_POST=$request->post;
    $_SERVER['REMOTE_ADDR']=$request->server['remote_addr'];
    $_FILES=$request->files;
    if(!$request->files){
        $GLOBALS['raw_content'] = $request->rawContent();
    }
    $file=$request->server['request_uri'];
    $_REQUEST=array_merge((array)$_GET,(array)$_POST);
    $startTime=microtime(true);
    //保存请求时间
    $globalTable->set($server->worker_pid, [
        'time' => $startTime,
    ]);
    //载入你的入口文件
    include "index.php";
    //更新请求时间
    $globalTable->del($server->worker_pid);
    //慢日志(大于300毫秒的)
    $runtime=microtime(true) - $startTime;
    if($runtime*1000>300){
        $server->task(json_encode(['url'=>$request->server['request_uri'],'data'=>$_REQUEST, 'runtime'=>$runtime]));
    }
});



$server->on("workerError", function (Swoole\Server $server, int $worker_id, int $worker_pid, int $exit_code, int $signal) {
    echo "WorkerError: Worker {$worker_id} error with exit code {$exit_code}, signal {$signal}\n";
});

// 监听Task事件
$server->on('Task', function ($http, $task_id, $worker_id, $dataStr) use ($server) {
    $file = SWROOT.'data/phperror/swRequest.php';
    $data=json_decode($dataStr,true);
    $line=date('Y-m-d H:i:s')." slow url:".$data['url'].' data:'.json_encode($data['data']).' runtime:'.number_format($data['runtime']*1000, 3)."ms\r\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    
});
//热重启检测
$server->tick(1000*30, function () use ($server,$globalTable) {
    if (checkChange()) {
        // 触发重启
        $server->reload();
    }
    //检测空闲数
    $stats = $server->stats();
    if($stats['idle_worker_num']<1){
        $file = SWROOT.'data/phperror/swRequest.php';
        $line=date("Y-m-d h:i:s")." not free worker \r\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
    //检测卡死
    $pids=array();
    foreach($globalTable as $pid=>$row)
    {
        if($row['time']>0&&(microtime(true) - $row['time'])*1000>1000*30){
            echo "stop pid:{$pid}\r\n";
            posix_kill($pid, SIGKILL);
            $pids[]=$pid;
        } 
    }
    //删除key
    foreach($pids as $pid){
        $globalTable->del($pid);
    }
});

$server->start();
//错误返回500
function shutdownCall($response){
    $response->status(500,"Server Error");
    $response->end('');
    $aError = error_get_last();
	if ((!empty( $aError)) && ($aError['type'] !== E_NOTICE)) {
		$file = SWROOT.'data/phperror/swphperr.php';
		$error .= date( 'Y-m-d H:i:s') . '---';
		$error .= 'Error:' . $aError['message'] . '--';
		$error .= 'File:' . $aError['file'] . '--';
		$error .= 'Line:' . $aError['line'];
		@file_put_contents( $file, $error . " \n ", FILE_APPEND | LOCK_EX);
	}
}


//检测文件改变
function checkChange(){
    global $fileInfo;
    //监控的目录
    $dirs=array(SWROOT.'model',
                SWROOT.'api',
                SWROOT.'config',
                SWROOT.'lib');
    $newFileInfo=array();
    clearstatcache(true);
    foreach($dirs as $dir){
        $reDir = new RecursiveDirectoryIterator($dir);
        $iterator = new RecursiveIteratorIterator($reDir, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if(pathinfo($file, PATHINFO_EXTENSION) != 'php')
                {
                    continue;
                }
                $newFileInfo[$file->getPathname()]=$file->getCTime();
            }
        }
    }
    foreach($newFileInfo as $file=>$z){
        if($z!=$fileInfo[$file]&&!empty($fileInfo)){
            $fileInfo=array();
            return true;
        }
    }
    $fileInfo=$newFileInfo;
    return false;
}
function exceptionLog(Throwable $e){
    $file = SWROOT.'data/phperror/swphperr.php';
    $error = date('Y-m-d H:i:s') . '---';
    $error .= 'Error:' . $e->getMessage() . '--';
    $error .= 'File:' . $e->getFile() . '--';
    $error .= 'Line:' . $e->getLine();
    @file_put_contents($file, $error . " \n ", FILE_APPEND | LOCK_EX);
}
