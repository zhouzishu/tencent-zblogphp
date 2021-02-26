<?php

// ZBP 初始化安装函数

require __DIR__ . '/cos-sdk-v5.phar';
require __DIR__ . '/pclzip.class.php';

function handler($event, $context)
{
    $bak_dir = '/mnt/zbp_bak';
    $download_path = '/mnt/zbp_bak/code.zip';
    $code_dir = '/mnt/zbp';
    if (!is_dir($bak_dir)) mkdir($bak_dir);
    if (!is_dir($code_dir)) mkdir($code_dir);

    $cosClient = new \Qcloud\Cos\Client(
        array(
            'region' => $event->ZbpCosRegion,
            'schema' => 'https', //协议头部，默认为http
            'credentials'=> array(
                'secretId'  => $event->SecretId,
                'secretKey' => $event->SecretKey,
                'token' => $event->Token)));    
    try {
        $cosClient->getObject(array(
            'Bucket' => $event->ZbpCosBucket,
            'Key' => $event->ZbpCosPath,
            'SaveAs' => $download_path,
        ));
        echo "Downloaded code from COS successfully!";
    } catch (\Exception $e) {
        return (object) [
            'status' => 'failure',
            'reason' => 'Downloaded code from COS failed!'
        ];
    }

    $result = zip_extract($download_path, $code_dir);
    if (! $result) {
        return (object) [
            'status' => 'failure',
            'reason' => 'Extracted code failed!'
        ];
    }

    if (file_exists($code_dir . '/zb_install/index.php')) {
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
    }

    return (object) [
        'status' => 'success'
    ];
}

function zip_extract($zip_file, $dir)
{
    if (! is_dir($dir)) {
        mkdir($dir);
    }

	$zip = new PclZip($zip_file);
	$res = $zip->extract(PCLZIP_OPT_PATH, $dir, PCLZIP_OPT_SET_CHMOD, 0777, PCLZIP_OPT_REPLACE_NEWER);

    return $res;
}
