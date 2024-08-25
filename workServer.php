<?php
error_reporting(0);
define("SWROOT",str_replace(basename(__FILE__),'',__FILE__));
include "inc.php";
include SWROOT. 'workerman/Autoloader.php';
use \Workerman\Worker;
use \Workerman\Protocols\Http\Request;
use \Workerman\Protocols\Http\Response;
use \Workerman\Protocols\Http\RawResponse;
use \Workerman\Protocols\Http\ResponseHeader;
use \Workerman\Protocols\Http\ResponseCookie;
use \Workerman\Connection\TcpConnection;
use \Workerman\Lib\Timer;

$pidFile=sys_get_temp_dir().'/'.basename(__FILE__).'.pid';
$port=isset($argv[2])?intval($argv[2]):9502;
if(checkRun($pidFile,$port)){
    return false;
}


$server = new Worker("http://0.0.0.0:{$port}");
$server->name = 'workmanServer';
$server->count =4;
$worker->reusePort = true;
$server->reloadable = true;
//使用swoole(为啥使用,因为它可以捕获exit函数)
Worker::$eventLoopClass = 'Workerman\Events\Swoole';
Worker::$pidFile = $pidFile;
TcpConnection::$defaultMaxPackageSize = 8*1024*1024;

//也使用swoole,我还找到更好用的
$globalTable = new Swoole\Table(128);
$globalTable->column('time', Swoole\Table::TYPE_INT);
$globalTable->create();

$server->onMessage = function ($connection, $request)  use ($server,$globalTable) {
    static $request_count;
    // 处理 GET POST REQUEST $_FILES
    $_GET = $request->get();
    $_POST = $request->post();
    $_FILES = $request->file();
    $_SERVER['REMOTE_ADDR']=$connection->getRemoteIp();
    $_REQUEST=array_merge((array)$_GET,(array)$_POST);
    if(!$request->file()){
        $GLOBALS['raw_content'] = $request->rawBody();
    }
    $startTime=microtime(true);
    //保存请求时间
    $globalTable->set(posix_getpid(), [
        'time' => $startTime,
    ]);

    ob_start(function($buffer) use($connection){ 
        $connection->send($buffer);
    },1024); 
    include "index.php";//
    ob_end_clean();
    //更新请求时间
    $globalTable->del(posix_getpid());
    $connection->close();
    if(++$request_count >10000) {
        // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
        Worker::stopAll();
    }
};

// 热重启检测
Timer::add(10, function () use ($worker) {
    if (checkChange()) {
        echo 'reload'."\r\n";
        posix_kill(posix_getpid(), SIGUSR1);
    }
});
Timer::add(60, function () use ($globalTable) {
    checkStuck($globalTable,5*60);
});
Worker::runAll();
