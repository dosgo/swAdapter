# swAdapter
用于适配传统php-fpm的php程序用swoole跑


# 难点

### 1.die/exit函数 
    swoole新版本解决,捕获ExitException就行
### 2.sleep/usleep函数禁止使用  
    swoole新版本一键协程会hook这些函数
### 3.session setcookie header 函数 (通过魔法runkit劫持/修改代码替换实现)
    通过魔法runkit劫持
    修改代码替换实现
### 4.超全局变量保留$_GET、$_POST $_RQUEST $_FILES $_SERVER 
    每次请求用$request对象的值替换(由于开启了一键协程化这会不会有问题到现在都不确定，而且里面也使用静态类这种全局变量)
### 5.include require 重复加载和相对路径问题 
    使用include_once和require_once
    使用绝对路径
    对于必须多次include的文件,里面有定义先用function_exists判断再定义,类一样
### 6.针对内存泄漏  
    设置max_request定时自杀
### 7.你的php代码必须有统一的入口比如index.php 
    没有需要自己实现没有统一入口点很麻烦
### 8.所有的redis mysql curl file_get_contents socket
    swoole新版本一键协程会hook这些函数
### 9.传统的echo 
    使用ob_start和ob_get_clean捕获
### 10.通过请求定义的常量
    用静态类属性替换
    使用魔法runkit实现修改常量
### 11.mysql redis失效重连问题  
    需要多测试
### 12.稳定性     
    使用crontab自动启动避免进程奔溃
    前置再套一层nginx,转发多个节点
### 13.代码更新自动重启  
    使用inotify监控文件

经过以上处理基本可以跑起来了,一般的程序至少有5倍qps提升,上面是个例子。
