<?php

// 在框架开始时，设置基础的错误报告
// 必须加上，没加的话，云函数每次调用大概耗费60MB内存
// 加上后内存耗费就减少到13MB左右
error_reporting(E_ALL ^ E_NOTICE);

// 简化系统路径斜杠
define('DS', DIRECTORY_SEPARATOR);

// 定义CGI端口
define('PHP_CGI_PORT', '9527');

// 容器识别码
define('DOCKER_ID', session_create_id());

// MIME TYPES列表
define('MIME_TYPES', include_once "./cgi_proxy/MimeTypes.php");

// 加载FastCGIClient
include_once "./cgi_proxy/FastCGIClient.php";
// 加载PhpCgiProxy
include_once "./cgi_proxy/PhpCgiProxy.php";
// 实例化代理对象
$proxy = new PhpCgiProxy();

// 云函数
function handler($event, $context)
{
    // 输出容器识别码，方便在日志中判断是否同一个容器
    echo 'DOCKER_ID_' . DOCKER_ID . PHP_EOL;

    // PHP代码目录
    // 可以是自动读取云函数当前的目录
    // $doc_root = dirname(__FILE__);
    // 也可以手动自行设定CFS文件挂载目录
    $doc_root = '/mnt/zbp';

    // 根据请求地址判断php执行文件名称，默认为index.php
    $script_name = 'index.php';

    // 分析请求地址
    $path_info = pathinfo($event->path);

    // 如果存在后缀
    if ($path_info['extension']) {

        // 判断是否为PHP后缀
        if ($path_info['extension'] != 'php') {

            $statusCode = 404;
            $contents = '';

            // 后缀不是PHP，视为静态资源文件，直接加载资源文件并输出
            $file_name = $doc_root . $event->path;

            // 对请求地址中已编码的 URL 字符串进行解码
            $file_name = rawurldecode($file_name);

            // 判断文件是否存在
            if(file_exists($file_name)){
                $statusCode = 200;
                $contents  = file_get_contents($file_name);
            } else {
                // 伪静态
                return handle_request($event, $doc_root, $script_name);
            }

            return array(
                'isBase64Encoded' => true, // API网关要求图片类数据需要进行Base64编码转换
                'statusCode'      => $statusCode,
                'headers'         => array(
                    'Content-Type'  => MIME_TYPES[$path_info['extension']],
                    'Cache-Control' => "max-age=8640000",
                    'Accept-Ranges' => 'bytes',
                ),
                'body'            => base64_encode($contents), // API网关要求图片类数据需要进行Base64编码转换
            );
        }
        // 后缀为PHP，则直接采用请求地址作为执行脚本名称
        $script_name = $event->path;
    } else {
        // 不存在后缀时，将整个path地址作为路径
        $script_name = rtrim($event->path, '/') . DS . $script_name;
    }

    return handle_request($event, $doc_root, $script_name);
}

function handle_request($event, $doc_root, $script_name)
{
    global $proxy;

    // 激活 MySQL
    active_mysql_connect();

    // 分析查询参数
    $uri_query = $event->queryString ? http_build_query($event->queryString) : '';
    // 拼接完整请求地址
    $request_uri = $event->path . ($uri_query ? '?' . $uri_query : '');

    // 准备请求参数
    $params = array(
        'SERVERLESS'      => 'SCF',
        'SERVER_PORT'     => '80',
        'DOCKER_ID'       => DOCKER_ID,
        'DOCUMENT_ROOT'   => $doc_root,
        'SCRIPT_NAME'     => $script_name,
        'SCRIPT_FILENAME' => $doc_root . '/' . $script_name,
        'QUERY_STRING'    => $uri_query,
        'REQUEST_URI'     => $request_uri,
        'SERVER_NAME'     => $event->headers->{'host'},
        'REQUEST_METHOD'  => $event->httpMethod,
        'CONTENT_TYPE'    => $event->headers->{'content-type'},
        'CONTENT_LENGTH'  => $event->headers->{'content-length'},
        'REMOTE_ADDR'     => $event->requestContext->sourceIp,
        'REQUEST_SCHEME'  => $event->headers->{'x-api-scheme'} ?? 'http',
    );

    $headers = (array) $event->headers;

    foreach ($headers as $name => $value) {

        if (in_array($name, array('content-type', 'content-length'), true)) {
            continue;
        }

        // 将名称转换为大写，再替换-为_
        $name = 'HTTP_' . str_replace("-", "_", strtoupper($name));
        // 保存到$params数组中
        $params[$name] = $value;
    }

    // 发起请求
    return $proxy->request_php_cgi($params, ($event->isBase64Encoded ? base64_decode($event->body) : $event->body), ['log_cgi_params' => true]);
}

function active_mysql_connect()
{
    $connect_db_retry_num = 0;
    $conn = new mysqli();

    while ($connect_db_retry_num <= 200) {
        $conn->connect(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"));
        if ($conn->connect_error == "CynosDB serverless instance is resuming, please try connecting again") {
            $connect_db_retry_num += 1;
            usleep(100000);
            continue;
        } else {
            break;
        }
    }
    return $connect_db_retry_num;
}
