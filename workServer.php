<?php
ini_set('opcache.enable',0);
ini_set('opcache.enable_cli',0);
define("SWROOT",str_replace(basename(__FILE__),'',__FILE__).'../../');
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
$server->count = 1;
$server->reloadable = true;
$server->pidFile = $pidFile;


$server->onMessage = function ($connection, $request) {
    static $request_count;
    // 处理 GET POST REQUEST $_FILES
    $_GET = $request->get();
    $_POST = $request->post();
    $_FILES = $request->file();
    $file = $request->path();
    $_SERVER['REMOTE_ADDR']=$connection->getRemoteIp();
    $_REQUEST=array_merge((array)$_GET,(array)$_POST);

   	$filename = str_replace(basename(__FILE__),'',__FILE__) .'public/'.$file;
	$ext = pathinfo($filename, PATHINFO_EXTENSION);
	   
	$mime_types=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
	      'png'=>'image/png','gif'=>'image/gif',
	      'html'=>'text/html','css'=>'text/css',
	      'js'=>'application/javascript'
	    );
     //处理文件
    if (in_array($ext, ["html", "css", "js", "jpg", "png", "gif",'jpeg'])) {
	//有相对路径处理(不然不安全)
	if (strpos($file, "../") !== false || strpos($file, "/") !== 0) {
	    $connection->status(403);
	    $connection->end();
	}else{
	    $connection->header("Content-Type",$mime_types[$ext]);
	    $connection->sendfile($filename);
	}
    }else{
          $response = new Response();
	    ob_start();
	    try {
    		include "index.php";//这个是你的框架入口文件
    		  $response->withBody(ob_get_clean());
          $connection->send($response);
          $connection->close();

	    }catch (Throwable  $e) {
    		  ob_end_clean();
        
          $response = new Response();
          $response->withStatus(500);
          $response->withBody("Server Error");
          $connection->send($response);
          $connection->close();
	    }
    }
    if(++$request_count >100000) {
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

$server->onWorkerStop = function ($worker) {
    \Workerman\Lib\Timer::del($worker->timer_id);
};

Worker::runAll();


//检测文件改变(当然inotify性能更好)
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
