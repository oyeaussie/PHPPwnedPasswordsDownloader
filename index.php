<?php

include 'vendor/autoload.php';

try {
    $downloader = new \Hibp\Downloader();

    array_splice($argv, 0, 1);//Remove index.php

    $downloader->run($argv);
} catch (\Exception $e) {
    echo $e->getMessage();
}