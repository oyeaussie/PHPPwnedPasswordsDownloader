<?php

include 'vendor/autoload.php';

try {
    $downloader = new \Hibp\Downloader();

    if (PHP_SAPI === 'cli') {
        array_splice($argv, 0, 1);//Remove index.php
    } else {
        $argv = [];
    }

    $downloader->run($argv);
} catch (\Exception $e) {
    echo $e->getMessage();
}