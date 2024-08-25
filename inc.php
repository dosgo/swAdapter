<?php
$GLOBALS['lastTime']=time();
//检测文件改变
function checkChange(){
    //监控的目录
    $dirs=array(SWROOT.'model',
                SWROOT.'api',
                SWROOT.'config',
                SWROOT.'lib');
    clearstatcache(true);
    foreach($dirs as $dir){
        if(!is_dir($dir)){
            continue;
        }
        $reDir = new RecursiveDirectoryIterator($dir);
        $iterator = new RecursiveIteratorIterator($reDir, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if(pathinfo($file, PATHINFO_EXTENSION) != 'php')
                {
                    continue;
                }
                if($file->getCTime()>$GLOBALS['lastTime']){
                    $GLOBALS['lastTime']=time();
                    return true;
                }
            }
        }
    }
    return false;
}

function checkRun($pidFile,$port){
    $client = new Swoole\Client(SWOOLE_SOCK_TCP);
    $run=true;
    if (!$client->connect('127.0.0.1', intval($port), 0.5)) {
        $run=false;
    } 
    $client->close();
    if(file_exists($pidFile)){
        $pid = intval(file_get_contents($pidFile));
        $cmdline=file_get_contents("/proc/{$pid}/cmdline");
        //表示僵尸进程(崩溃了)
        if(strlen($cmdline)<3||!posix_kill($pid, SIG_DFL)){
            $run=false;
        }
        //如果是swoole manager进程崩溃也不行
        if(defined('SOOLEWEB')){
            $managerPid = intval(file_get_contents($pidFile.'.manager'));
            $cmdline=file_get_contents("/proc/{$managerPid}/cmdline");
            //表示僵尸进程(崩溃了)
            if(strlen($cmdline)<3||!posix_kill($managerPid, SIG_DFL)){
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
        $run=false;
    }
    return $run;
}

function checkStuck($globalTable,$stuckTime=300) {
    //检测卡死
    $pids=array();
    foreach($globalTable as $pid=>$row)
    {
        if($row['time']>0&&(microtime(true) - $row['time'])>$stuckTime){
            posix_kill($pid, SIGKILL);
            $pids[]=$pid;
        }
    }
    //删除key
    foreach($pids as $pid){
        $globalTable->del($pid);
    }
}