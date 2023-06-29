<?php
define("SWROOT",str_replace(basename(__FILE__),'',__FILE__).'../../');
/* crontab
* * * * * /usr/local/php/bing/php swServer.php
*/
if (version_compare(phpversion('swoole'), '4.6.2', '<')) {
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
$server = new Swoole\Http\Server("0.0.0.0", $port);
$server->set(['max_request'=>10000,'pid_file'=>$pidFile, 'enable_coroutine' => false]);
$server->on('request', function ($request, $response) {
    
    //注册异常返回
    register_shutdown_function(function () use ($response) {
        shutdownCall($response);
    });

    //处理GET POST REQUEST $_FILES
    $_GET=$request->get;
    $_POST=$request->post;
    $_SERVER['REMOTE_ADDR']=$request->server['remote_addr'];
    $_FILES=$request->files;
    $file=$request->server['request_uri'];
    $_REQUEST=array_merge((array)$_GET,(array)$_POST);
    //runkit和uopz扩展二选一
    if(function_exists('runkit7_constant_redefine')){
        defined('NOW')  && runkit7_constant_redefine('NOW', time());
        defined('SGSID')  &&  runkit7_constant_redefine('SGSID',$_REQUEST['sid']?$_REQUEST['sid']:0);
        defined('SGLID')  &&  runkit7_constant_redefine('SGLID',$_REQUEST['lid']?$_REQUEST['lid']:0);
    }elseif(function_exists('uopz_redefine')){
        defined('NOW')  && uopz_redefine('NOW', time());
        defined('SGSID')  &&  uopz_redefine('SGSID',$_REQUEST['sid']?$_REQUEST['sid']:0);
        defined('SGLID')  &&  uopz_redefine('SGLID',$_REQUEST['lid']?$_REQUEST['lid']:0);
    }
	
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
	    $response->status(403);
	    $response->end();
	}else{
	    $response->header("Content-Type",$mime_types[$ext]);
	    $response->sendfile($filename);
	}
    }else{
      
	    ob_start();
	    try {
		include "index.php";//这个是你的框架入口文件
		$response->end(ob_get_clean());
	    } catch (Swoole\ExitException $e) {
		$res=ob_get_clean();
		if(!$res){
		    $res=$e->getStatus();
		}
		$response->end($res);
	    }catch (Throwable  $e) {
		 ob_end_clean();   
		//写错误日志
		$response->status(500,"Server Error");
		$response->end("Server Error");
	    }
    }
});

$server->on('finish', function () {
    echo "task finish\r\n";
});

$server->on("workerError", function (Swoole\Server $server, int $worker_id, int $worker_pid, int $exit_code, int $signal) {
    echo "WorkerError: Worker {$worker_id} error with exit code {$exit_code}, signal {$signal}\n";
});

//热重启检测
swoole_timer_tick(1000*45, function () use ($server) {
    if (checkChange()) {
        // 触发重启
        $server->reload();
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


