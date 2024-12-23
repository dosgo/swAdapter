<?php
include_once SWROOT. 'lib/guzzleHttp/autoload.php';
class app
{
    private $demo=0;
    private static $headers=array();
    public function __construct($demo)
    {
        $this->demo=$demo;
    }

    /*设置响应对象*/
    public static function header($key,$value){
       self::$headers[$key]=$value;
    }

    /*增加header*/
    public static function buildResponse($response){
        if($response){
            foreach(self::$headers as $key=>$value){
                if(stripos($key,"Location")!==false){
                    $response=$response->withStatus(302);
                }
                $response=$response->withHeader($key,$value);
            }
        }
        return $response;
    }

 
  
     /*requestToGlobals*/
     public static function requestToGlobals($request,$demo)
     {

        $_SERVER['SCRIPT_NAME'] =$request->getUri()->getPath();
        // 处理 GET POST REQUEST $_FILES
        $_GET  = $request->getQueryParams();
        $_POST  = $request->getParsedBody();
        $_FILES = self::convertUploadedFilesToPhpStyle($request->getUploadedFiles());
        $_SERVER['SERVER_NAME']= $request->getHeader('host')[0];
        $_SERVER['REQUEST_URI']=$request->getUri()->getPath();
        $_SERVER['QUERY_STRING']=$request->getUri()->getQuery();

        $_SERVER['HTTP_HOST']= $request->getHeader('host')[0];
        $_SERVER['HTTP_USER_AGENT'] =$request->getHeader('user-agent')[0];
        if($request->getBody()){
            $GLOBALS['raw_content'] = $request->getBody()->getContents();
        }
         $_REQUEST=array_merge((array)$_GET,(array)$_POST);
         $_REQUEST['demo'] =  $demo; //内网
     }
     /*psr7文件转成$_FILES*/
     static function  convertUploadedFilesToPhpStyle($uploadedFiles) {
        $filesArray = [];
        foreach ($uploadedFiles as $name => $file) {
            if ($file instanceof  Psr\Http\Message\UploadedFileInterface) {
                // 如果是单个文件
                $filesArray[$name] = [
                    'name'     => $file->getClientFilename(),
                    'type'     => $file->getClientMediaType(),
                    'tmp_name' => $file->getStream()->getMetadata('uri'),
                    'error'    => $file->getError(),
                    'size'     => $file->getSize(),
                ];
            } elseif (is_array($file)) {
                // 如果是多个文件（数组）
                $filesArray[$name] = [
                    'name'     => [],
                    'type'     => [],
                    'tmp_name' => [],
                    'error'    => [],
                    'size'     => []
                ];
    
                foreach ($file as $index => $singleFile) {
                    if ($singleFile instanceof Psr\Http\Message\UploadedFileInterface) {
                        $filesArray[$name]['name'][]     = $singleFile->getClientFilename();
                        $filesArray[$name]['type'][]     = $singleFile->getClientMediaType();
                        $filesArray[$name]['tmp_name'][] = $singleFile->getStream()->getMetadata('uri');
                        $filesArray[$name]['error'][]    = $singleFile->getError();
                        $filesArray[$name]['size'][]     = $singleFile->getSize();
                    }
                }
            }
        }
    
        return $filesArray;
    }

    public function handle($request)
    {
        self::requestToGlobals($request,$this->demo);
        //覆盖
        $_SERVER['REQUEST_TIME']=time();
        if(ob_get_level()){
            ob_end_clean();
        }
        ob_start();
        $response= new GuzzleHttp\Psr7\Response(200,['Access-Control-Allow-Origin' => '*']);
        try {
            $response= $response->withBody( GuzzleHttp\Psr7\Utils::streamFor(ob_get_clean()));
        }catch (Throwable  $e) {
            if( strpos(get_class($e),'ExitException')!==false){
                $res=ob_get_clean();
                if(!$res){
                    $code=$e->getStatus();
                    $res=$code?$code:$res;
                }
                $response= $response->withBody(GuzzleHttp\Psr7\Utils::streamFor($res));
            }else{
                exceptionLog($e);
                return new GuzzleHttp\Psr7\Response(500,['Access-Control-Allow-Origin' => '*']);
            }
        }
        return self::buildResponse($response);
    }
}


