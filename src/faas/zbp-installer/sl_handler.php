<?php

// ZBP 下载安装函数

function handler($event, $context)
{
    $code_dir = '/mnt/zbp';

    $xml = http_get_content('http://update.zblogcn.com/zblogphp/?install');

    if (! $xml) {
        die('Downloaded Z-BlogPHP Release XML failed!');
    }

    echo "Downloaded Z-BlogPHP Release XML successfully!\n";

    $xml = simplexml_load_string($xml, 'SimpleXMLElement');
    $old = umask(0);
    foreach ($xml->file as $f) {
        $filename = str_replace('\\', '/', $f->attributes());
        $dirname = $code_dir . '/' . dirname($filename);
        if (! is_dir($dirname)) {
            mkdir($dirname, 0755, true);
        }
        $fn = $code_dir . '/' . $filename;
        file_put_contents($fn, base64_decode($f));
        echo 'Put file successfully! Filename: '.$fn."\n";
    }
    umask($old);

    echo "Extracted ZBP successfully!";

    $install_content = str_replace(
        array(
            '<?php echo $option[\'ZC_MYSQL_SERVER\']; ?>',
            '<?php echo $option[\'ZC_MYSQL_NAME\']; ?>',
            '<?php echo $option[\'ZC_MYSQL_USERNAME\']; ?>',
            '<?php echo $option[\'ZC_MYSQL_PASSWORD\']; ?>',
        ),
        array(
            getenv('DB_HOST'),
            getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASSWORD')
        ),
        file_get_contents($code_dir . '/zb_install/index.php')
    );

    file_put_contents($code_dir . '/zb_install/index.php', $install_content);
    echo "Replace default installer successfully!\n";

    return (object) [
        'status' => 'success'
    ];
}

function http_get_content($url)
{
    $r = null;
    if (function_exists("curl_init") && function_exists('curl_exec')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (ini_get("safe_mode") == false && ini_get("open_basedir") == false) {
            curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        }
        if (extension_loaded('zlib')) {
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $r = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get("allow_url_fopen")) {
        if (function_exists('ini_set')) {
            ini_set('default_socket_timeout', 300);
        }
        $r = file_get_contents((extension_loaded('zlib') ? 'compress.zlib://' : '') . $url);
    }

    return $r;
}
