<?php
error_reporting(0);
include "inc.php";
include "app.php";
include SWROOT. 'lib/workerman/Autoloader.php';

use \Workerman\Worker;
use \Workerman\Connection\TcpConnection;
use \Workerman\Lib\Timer;
$pidFile=sys_get_temp_dir().'/'.basename(__FILE__).'.pid';
$port=isset($argv[2])?intval($argv[2]):9502;
$demo=isset($argv[3])?intval($argv[3]):0;
if(checkRun($pidFile,$port)){
    return false;
}

$server = new Worker("http://0.0.0.0:{$port}");
$server->name = 'workmanServer';
$server->count =$demo?10:100;
$server->reloadable = true;
if(stripos($_ENV['OS'],'windows')===false){
    $server->reusePort=true;
}
//使用swoole(为啥使用,因为它可以捕获exit函数)
if(extension_loaded('swoole')){
    Worker::$eventLoopClass = 'Workerman\Events\Swoole';
}
Worker::$pidFile = $pidFile;
TcpConnection::$defaultMaxPackageSize = 8*1024*1024;

//也使用swoole,我还没找到更好用的
$globalTable=null;
if(extension_loaded('swoole')){
    $globalTable = new Swoole\Table(128);
    $globalTable->column('time', Swoole\Table::TYPE_INT);
    $globalTable->create();
}
$server->onMessage = function ($connection, $request)  use ($server,$globalTable,$demo) {
    static $request_count;
    $_SERVER['REMOTE_ADDR']=$connection->getRemoteIp();
  
    //保存请求时间
    if($globalTable){
        $globalTable->set(posix_getpid(), [
            'time' => microtime(true),
        ]);
    }
    $app=new ninekeApp($demo);
    $psr7Request=workermanToPsr7Request($request);
    $psr7Response=$app->handle($psr7Request);
    $connection->send(GuzzleHttp\Psr7\Message::toString($psr7Response),true);
    //更新请求时间
    if($globalTable){
        $globalTable->del(posix_getpid());
    }
    $connection->close();
    if(++$request_count >10000) {
        // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
        Worker::stopAll();
    }
};

$server->onWorkerStart =function(Worker $worker) use ($globalTable)
{
    function_exists('opcache_reset') && opcache_reset();
    if($worker->id==0){
        ///检测代码是否变了
        if (function_exists('posix_kill')) {
            $GLOBALS['lastTime']=time();
            Timer::add(15, function ()  {
                if (checkChange()) {
                    posix_kill(posix_getppid(), SIGUSR1);
                }
            });
        }

        //检查卡死
        if (function_exists('pcntl_alarm')) {
            Timer::add(60, function () use ($globalTable) {
                checkStuck($globalTable);
            });
        }
    }
};
Worker::runAll();
