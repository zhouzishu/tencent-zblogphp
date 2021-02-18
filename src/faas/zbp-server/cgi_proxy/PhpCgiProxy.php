<?php

class PhpCgiProxy
{

    private $is_php_cgi_start = false;

    private function check_php_cgi(){

        $fp = fsockopen("127.0.0.1", PHP_CGI_PORT, $errno, $errstr, 1);

        if ($fp) {
            fclose($fp);
            return true;
        }

        return false;
    }

    private function start_php_cgi()
    {

        if ($this->is_php_cgi_start && $this->check_php_cgi()) {
            return true;
        }

        // 查找 php-cgi
        exec("ps aux | grep  /var/lang/php7/bin/php-cgi | grep -v grep", $output, $return_var);
        if (count($output) == 1) {
            // 强制重启
            exec("kill -s 9 `ps -aux | grep /var/lang/php7/bin/php-cgi | awk '{print $2}'`");
            // 重启写到日志里
            echo "PhpCgiProxy kill at " . microtime(true) . PHP_EOL;
        }

        // 构建执行命令
        $cmd = sprintf("nohup /var/lang/php7/bin/php-cgi -c " . dirname(__FILE__) . "/php.ini -b 127.0.0.1:%s > /tmp/scf-php-cgi.log 2>&1 &", PHP_CGI_PORT);

        // 执行命令
        exec($cmd, $output, $status);

        $start_exec_time = microtime(true);

        // 启动写到日志里
        echo "PhpCgiProxy exec start at " . $start_exec_time . PHP_EOL;

        // 判断执行结果
        if ($status != 0) {
            // 将输出转换为json
            $output_json = json_encode($output, JSON_UNESCAPED_UNICODE);
            // 读取错误日志
            $log_str = file_get_contents("/tmp/scf-php-cgi.log");
            // 打印到SCF函数日志中
            echo "status: " . $status . "; \n output: " . $output_json . ";\n php-cgi output: " . $log_str . PHP_EOL;

            return false;
        }

        // 先等待0.03秒，再进行第一次启动状态检测
        usleep(30000);
        
        // 循环10秒，每隔一秒检测一次
        $wait_time = 10 * 1000000;

        while ($wait_time > 0) {

            if($this->check_php_cgi()){
                echo "PhpCgiProxy exec end at " . microtime(true) . PHP_EOL;
                break;
            }

            $wait_time = $wait_time - 1000000;

            // 等待一秒后再检测
            usleep(1000000);
        }

        echo "PhpCgiProxy exec used " . (microtime(true)-$start_exec_time) . PHP_EOL;

        // 修改状态
        $this->is_php_cgi_start = true;

        return true;
    }

    // 发起请求
    public function request_php_cgi($params = array(), $input, $options = array())
    {

        $this->start_php_cgi();

        $cgi_params = array(
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REMOTE_PORT'       => '10086',
            'SERVER_ADDR'       => '127.0.0.1',
            'SERVER_PORT'       => '80',
            'SERVER_PROTOCOL'   => 'HTTP/1.1',
            'SERVER_SOFTWARE'   => 'php/fastcgiclient',
        );

        $cgi_params = array_merge($cgi_params, $params);

        // 将请求地址加入到SCF日志中
        echo 'URL_' . $cgi_params['SERVER_NAME'] . $cgi_params['REQUEST_URI'] . PHP_EOL;

        // 判断是否记录下计算后的请求参数
        if (isset($options['log_cgi_params']) && $options['log_cgi_params']) {
            // 打印整个数组到SCF日志中
            var_dump($cgi_params);
        }

        try {
            $php_cgi_client = new Adoy\FastCGI\Client('127.0.0.1', PHP_CGI_PORT);
            $php_cgi_client->setReadWriteTimeout(10000);

            $raw_response = $php_cgi_client->request($cgi_params, $input);

            preg_match('#(Status:.*)[\S\s]X-Powered-By: PHP#', $raw_response, $matches);

            if ($matches) {
                $error_pos = strpos($raw_response, $matches[1]);
            } else {
                $error_pos = strpos($raw_response, 'X-Powered-By: PHP');
            }

            if ($error_pos !== 0) {
                // 存在错误信息
                $error_msg = substr($raw_response, 0, $error_pos);

                if($error_msg){
                    echo $error_msg;
                }
            }

            $raw_response = substr($raw_response, $error_pos);

            $headers = array();
            $body    = '';

            $lines = explode(PHP_EOL, $raw_response);
            $last  = 0;
            foreach ($lines as $num => $line) {

                if (preg_match('#^([^\:]+):(.*)$#', $line, $matches)) {
                    $last  = $num;
                    $key   = trim($matches[1]);
                    $value = trim($matches[2]);
                    if (array_key_exists($key, $headers)) {
                        $headers[$key][] = $value;
                    } else if (in_array(strtoupper($key), array('CONTENT-TYPE'))) {
                        // 云函数API网关的CONTENT-TYPE只支持字符串，不支持传递数组
                        $headers[$key] = $value;
                    } else {
                        $headers[$key] = array($value);
                    }
                    continue;
                }
                break;
            }

            $body = implode(PHP_EOL, array_slice($lines, $last + 2));

            $status = isset($headers['Status']) ? $headers['Status'][0] : '200 OK';

            $status = explode(" ", $status);

            $statusCode = intval($status[0]);

            unset($headers['Status']);

            unset($php_cgi_client);

            return array(
                'isBase64Encoded' => false,
                'statusCode'      => $statusCode,
                'headers'         => $headers,
                'body'            => $body ? $body : ($error_msg ? $error_msg : ''),
            );

        } catch (Exception $e) {

            $this->is_php_cgi_start = false;

            $err = array(
                "errorMessage" => $e->getMessage(),
                "errorType"    => get_class($e),
                "stackTrace"   => array(
                    "file"        => $e->getFile(),
                    "line"        => $e->getLine(),
                    "traceString" => $e->getTraceAsString(),
                ),
            );

            $errStr = var_export($err, true);

            // 将错误信息写入到SCF日志
            echo $errStr;

            unset($php_cgi_client);

            return array(
                'isBase64Encoded' => false,
                'statusCode'      => 502,
                'headers'         => array(
                    'Content-Type'   => 'application/octet-stream',
                    'Content-Length' => strlen($errStr),
                    'Connection'     => 'keep-alive',
                ),
                'body'            => $errStr,
            );
        }
    }
}
