<?php
define("SWROOT",realpath(str_replace(basename(__FILE__),'',__FILE__)).'/');
include_once SWROOT. '/vendor/autoload.php';
$GLOBALS['lastTime']=time();
//检测文件改变
function checkChange(){
    //监控的目录
    $dirs=array(SWROOT.'model',
                SWROOT.'api',
                SWROOT.'config',
                SWROOT.'module',
                SWROOT.'lib');
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
                if($file->getCTime()>$GLOBALS['lastTime']||$file->getMTime()>$GLOBALS['lastTime']){
                    $GLOBALS['lastTime']=time();
                    return true;
                }
            }
        }
    }
    return false;
}

function checkRun($pidFile,$port){
    $run=true;  
    $socket = stream_socket_client("tcp://127.0.0.1:".$port, $errno, $errstr, 0.5);
    if ($socket===false) {
        $run=false;
    } 

    if(file_exists($pidFile)){
        $pid = intval(file_get_contents($pidFile));
        $output = shell_exec("ps -ef |grep -v 'defunct' | grep '{$pid}' | awk '{print $2}'");
        if(strpos($output,"{$pid}")===false){
            echo "master err\r\n";
            $run=false;
        }
        //如果是swoole manager进程崩溃也不行
        if(defined('SOOLEWEB')){
            $managerPid = intval(file_get_contents($pidFile.'.manager'));
            $output = shell_exec( "ps -ef |grep -v 'defunct'| grep '{$managerPid}' | awk '{print $2}'");
            //表示僵尸进程(崩溃了)
            if(strpos($output,"{$managerPid}")===false){
                echo "manager err\r\n";
                $run=false;
            }
        }
        if(!$run){
            //结束子进程
            $pgid=posix_getpgid($pid);
            if($pgid>0){
                posix_kill(-$pgid,SIGKILL);
            }else{
                posix_kill(-$pid,SIGKILL);
            }
            usleep(1000*200);
        }
    }else{
        echo "pid null err\r\n";
        $run=false;
    }
    return $run;
}




/*检测卡死*/
function checkStuck($globalTable) {
    //检测卡死
    $pids=array();
    foreach($globalTable as $pid=>$row)
    {
        if($row['time']>0&&(microtime(true) - $row['time'])>150){
            posix_kill($pid, SIGKILL);
            $pids[]=$pid;
        } 
    }
    //删除key
    foreach($pids as $pid){
        $globalTable->del($pid);
    }
 }

 /*全局表多进程共享(用于模拟Swoole\Table 需要sysvshm)*/
 class globalTable{
    private $shm_id;
    private $globalTable=null;
    public function __construct()
    {
        if (!function_exists('shm_attach')) {
            echo "Requires  sysvshm extension\r\n";
        }
        $this->shm_id = shm_attach(6666);
        // 尝试从共享内存加载现有数据
        $this->loadData();
    }

    private function loadData(){
        $this->globalTable = [];
        for ($i = 1; ; $i++) {
            $var = shm_get_var($this->shm_id, $i);
            if ($var === false) {
                break;
            }
            $this->globalTable[$i] = $var;
        }
    }

    public function set($key,$data)
    {
        // 将数据存储到共享内存中
        shm_put_var($this->shm_id, $key, $data);
        // 同步内存中的数据副本
        $this->globalTable[$key] = $data;
    
    }
    public function del($key)
    {
    
        shm_remove_var($this->shm_id, $key);
        // 从内存中的数据副本移除
        unset($this->globalTable[$key]);
    }
    public function getIterator() {
        // 如果不是 Swoole\Table，则使用 ArrayIterator
        $this->loadData();
        return new ArrayIterator($this->globalTable);   
    }

    public function __destruct() {
        // 分离共享内存段
        shm_detach($this->shm_id);
    }
 }


function swooleToPsr7Request ($swooleRequest) {
    //这些不在psr7范围内直接用全局变量
    $_COOKIE = $swooleRequest->cookie;
    $_SERVER['SCRIPT_NAME'] = $swooleRequest->server['request_uri'];
    // 提取基本信息
    $method = $swooleRequest->getMethod();
    $uri =$swooleRequest->server['request_uri'].'?'.$swooleRequest->server['query_string'];
    $_SERVER ["REMOTE_ADDR"]=$swooleRequest->server['remote_addr'];
    $body=null;
    if(!$swooleRequest->files&&$swooleRequest->rawContent()){
        $body =$swooleRequest->rawContent();
    }
    $serverRequest = new GuzzleHttp\Psr7\ServerRequest($method, $uri, $swooleRequest->header, $body);

    return $serverRequest
        ->withCookieParams($_COOKIE?$_COOKIE:[])
        ->withQueryParams($swooleRequest->get?$swooleRequest->get:[])
        ->withParsedBody($swooleRequest->post?$swooleRequest->post:[])
        ->withUploadedFiles($serverRequest::normalizeFiles($swooleRequest->files?$swooleRequest->files:[]));
}

function workermanToPsr7Request ($workerManRequest) {
    $body=null;
    if(!$workerManRequest->file()){
        $body =$workerManRequest->rawBody();
    }
    $serverRequest = new GuzzleHttp\Psr7\ServerRequest($workerManRequest->method(),$workerManRequest->uri(),  $workerManRequest->header(),$body, $workerManRequest->protocolVersion(),$_SERVER);
    return $serverRequest
        ->withCookieParams($workerManRequest->cookie())
        ->withQueryParams( $workerManRequest->get())
        ->withParsedBody($workerManRequest->post())
        ->withUploadedFiles($serverRequest::normalizeFiles($workerManRequest->file()));
}
