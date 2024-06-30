<?php

use PHPPwnedPasswordsDownloader\Compare;
use PHPPwnedPasswordsDownloader\Download;
use PHPPwnedPasswordsDownloader\Help;
use PHPPwnedPasswordsDownloader\Index;
use PHPPwnedPasswordsDownloader\Lookup;
use PHPPwnedPasswordsDownloader\Sort;
use PHPPwnedPasswordsDownloader\Update;

include 'vendor/autoload.php';

try {
    if (PHP_SAPI === 'cli') {
        \cli\line('%wThis is a CLI tool to download, index, cache, sort and lookup pwned password. Type ./hibp --help for list of commands.%w');
        \cli\line('');

        $method = null;

        array_splice($argv, 0, 1);//Remove index.php

        if (count($argv) > 0) {
            if ($argv[0] === '--help' || $argv[0] === '-h') {//help
                (new Help($method))->show();

                return true;
            } else {
                $argv = processArguments($argv, $method);

                if (array_key_exists('--help', $argv)) {
                    (new \PHPPwnedPasswordsDownloader\Help())->show($method);

                    return true;
                }

                if ($method === 'download') {//download
                    (new Download($argv))->run();
                } else if ($method === 'sort') {//sort
                    (new Sort($argv))->run();
                } else if ($method === 'index') {//index
                    (new Index($argv))->run();
                } else if ($method === 'lookup') {//lookup
                    (new Lookup($argv))->search();
                } else if ($method === 'compare') {//compare downloads to generate update json and update php file.
                    (new Compare($argv))->run();
                }
            }
        }
    } else if (isset($_GET['since'])) {
        $updates = (new Update())->getUpdates();

        if ($updates) {
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode($updates);
        }
    } else {
        echo "This is a CLI tool to download, index, cache, sort and lookup pwned password. From CLI, type ./hibp --help for list of commands.";

        exit;
    }
} catch (\Exception $e) {
    echo $e->getMessage() . PHP_EOL;
}

function processArguments($argv, &$method)
{
    $method = $argv[0];

    array_splice($argv, 0, 1);

    if (count($argv) === 0) {
        return [];
    }

    $arguments = [];

    foreach ($argv as $key => $args) {
        if (!str_contains($args, '--')) {
            throwInvalidArgumentException($args);

            exit;
        }

        $args = explode('=', $args);

        if (count($args) === 1 && !in_array('--help', $args)) {
            throwInvalidArgumentException($args[0]);

            exit;
        } else if (count($args) === 1 && in_array('--help', $args)) {
            $arguments['--help'] = true;

            return $arguments;
        } else {
            $arguments[$args[0]] = $args[1];
        }

    }

    return $arguments;
}

function throwInvalidArgumentException($arg)
{
    \cli\line('%RIncorrect argument format of argument ' . $arg . '. Argument format is --{argument_name}={argument_value}. See --help for more details.%w');
}