<?php

include 'vendor/autoload.php';


try {
    echo 'This is a CLI tool to download, index, cache, sort and lookup pwned password. Type --help for list of commands.' . PHP_EOL . PHP_EOL;

    $method = null;

    if (PHP_SAPI === 'cli') {
        array_splice($argv, 0, 1);//Remove index.php

        if (count($argv) > 0) {
            if ($argv[0] === '--help' || $argv[0] === '-h') {//help
                (new \PHPPwnedPasswordsDownloader\Help($method))->show();

                return true;
            } else {
                $argv = processArguments($argv, $method);

                if (array_key_exists('--help', $argv)) {
                    (new \PHPPwnedPasswordsDownloader\Help())->show($method);

                    return true;
                }

                if ($method === 'download') {//download
                    (new \PHPPwnedPasswordsDownloader\Download($argv))->run();
                } else if ($method === 'sort') {//sort
                    (new \PHPPwnedPasswordsDownloader\Sort($argv))->run();
                } else if ($method === 'index') {//index
                    (new \PHPPwnedPasswordsDownloader\Index($argv))->run();
                } else if ($method === 'lookup') {//lookup
                    (new \PHPPwnedPasswordsDownloader\Lookup($argv))->search();
                }
            }
        }
    }
} catch (\Exception $e) {
    echo $e->getMessage() . PHP_EOL;
}

function processArguments($argv, &$method)
{
    $method = $argv[0];

    array_splice($argv, 0, 1);

    if (count($argv) === 0) {
        $argv['--defaults'] = true;

        return $argv;
    }

    $arguments = [];

    foreach ($argv as $key => $args) {
        if (!str_contains($args, '--')) {
            throwInvalidArgumentException($args);
        }

        $args = explode('=', $args);

        if (count($args) === 1 && !in_array('--help', $args)) {
            throwInvalidArgumentException($args[0]);
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
    throw new \Exception('Incorrect argument format of argument ' . $arg . '. Argument format is --{argument_name}={argument_value}. See --help for more details.');
}