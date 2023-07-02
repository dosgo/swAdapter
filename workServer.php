<?php
ini_set('opcache.enable',0);
ini_set('opcache.enable_cli',0);
define("SWROOT",str_replace(basename(__FILE__),'',__FILE__).'../../');
include "inc.php";
include SWROOT. 'lib/workerman/Autoloader.php';
use \Workerman\Worker;
use \Workerman\Protocols\Http\Request;
use \Workerman\Protocols\Http\Response;
use \Workerman\Protocols\Http\RawResponse;
use \Workerman\Protocols\Http\ResponseHeader;
use \Workerman\Protocols\Http\ResponseCookie;
/*
* * * * * /usr/local/php/bing/php workServer.php
*/
$pidFile=sys_get_temp_dir().'/'.basename(__FILE__).'.pid';
$port=isset($argv[2])?intval($argv[2]):9502;
if(file_exists($pidFile)){
    $pid = intval(file_get_contents($pidFile));
    if (posix_kill($pid, SIG_DFL)) {//判断该进程是否正常运行中
        return $pid;
    } 
}

//define("SOOLEWEB",1);

error_reporting(0);

$server = new Worker("http://0.0.0.0:{$port}");
$server->name = 'MyWebsocketServer';
$server->count = 4;
$worker->reusePort = true;
$server->reloadable = true;
$server->pidFile = $pidFile;
//使用swoole(为啥使用,因为它可以捕获exit函数)
Worker::$eventLoopClass = 'Workerman\Events\Swoole';

//也使用swoole,我没找到更好用的
$globalTable = new Swoole\Table(10);
$globalTable->column('time', Swoole\Table::TYPE_INT);
$globalTable->create();


$server->onMessage = function ($connection, $request)  use ($server,$globalTable) {
    static $request_count;
    // 处理 GET POST REQUEST $_FILES
    $_GET = $request->get();
    $_POST = $request->post();
    $_FILES = $request->file();
    $file = $request->path();
    $_SERVER['REMOTE_ADDR']=$connection->getRemoteIp();
    $_REQUEST=array_merge((array)$_GET,(array)$_POST);
    if(!$request->file()){
        $GLOBALS['raw_content'] = $request->rawBody();
    }
    $response = new Response();
    $startTime=microtime(true);
    //保存请求时间
    $globalTable->set(posix_getpid(), [
        'time' => $startTime,
    ]);
	//include "index.php";

    //更新请求时间
    $globalTable->set(posix_getpid(), [
        'time' => 0,
    ]);
    $connection->send($response);
    $connection->close();
    if(++$request_count >10000) {
        // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
        Worker::stopAll();
    }
};

// 热重启检测
$server->onWorkerStart = function ($worker) {
    $worker->timer_id = \Workerman\Lib\Timer::add(45, function () use ($worker) {
        if (checkChange()) {
            echo 'reload'."\r\n";
            posix_kill(posix_getppid(), SIGUSR1);
        }
    });
};

 \Workerman\Lib\Timer::add(10, function () use ($globalTable) {
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



$server->onWorkerStop = function ($worker) {
    \Workerman\Lib\Timer::del($worker->timer_id);
};

Worker::runAll();
