<?php
$lastTime=time();
//检测文件改变
function checkChange(){
    global $lastTime;
    //监控的目录
    $dirs=array(SWROOT.'model',
                SWROOT.'api',
                SWROOT.'config',
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
                if($file->getMTime()>$lastTime){
                    $lastTime=$file->getMTime();
                    return true;
                }
            }
        }
    }
    return false;
}

function checkRun($pidFile){
    if(file_exists($pidFile)){
        $pid = intval(file_get_contents($pidFile));
        if (posix_kill($pid, SIG_DFL)) {//判断该进程是否正常运行中
            return $pid;
        }
    }
    return false;
}
