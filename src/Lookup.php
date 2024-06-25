<?php

namespace PHPPwnedPasswordsDownloader;

use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPPwnedPasswordsDownloader\Base;
use PHPPwnedPasswordsDownloader\Cache;
use cli\Table;
use cli\table\Ascii;

class Lookup extends Base
{
    protected $cache;

    protected $microtime = 0;

    protected $memoryusage = 0;

    protected $microTimers = [];

    protected $isNtlm = false;

    protected $lookupMethod;

    protected $hashFoundArr = [];

    protected $method;

    protected $incorectHashTypeFile = false;

    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->settings = array_merge(
            [
                '--ntlm'            => false,
                '--search'          => 'password',
                '--search-method'   => 'stream'
            ],
            $settings
        );
    }

    public function search()
    {
        if (isset($this->settings['--ntlm']) && (bool) $this->settings['--ntlm']) {
            $this->isNtlm = true;
        }

        if (!method_exists($this, 'search' . ucfirst($this->settings['--search-method']))) {
            \cli\line('%rUnknown lookup method%w');

            exit;
        }

        echo 'Enter ' . ucfirst($this->settings['--search']) . ': ';

        $cliHandle = fopen('php://stdin','r');

        if (strtolower($this->settings['--search']) === 'password') {
            system("stty -icanon");
            system('stty -echo');

            $input = "";

            while ($char = fread(STDIN, 1)) {
                if ($char !== PHP_EOL) {
                    $input .= $char;

                    echo '*';
                } else {
                    break;
                }
            }

            if ($this->isNtlm) {
                $hash = strtoupper(hash('md4',iconv('UTF-8','UTF-16LE',$input)));
            } else {
                $hash = strtoupper(sha1(trim($input)));
            }

            $hashFile = substr($hash, 0, 5);

            system('stty echo');

            echo PHP_EOL;
        } else if (strtolower($this->settings['--search']) === 'hash') {
            $input = rtrim(fgets($cliHandle), "\r\n");

            $hash = strtoupper(trim($input));

            $hashFile = substr($hash, 0, 5);

            if (strlen($input) === 32 || strlen($input) === 40) {
                if (strlen($input) === 32) {
                    $this->isNtlm = true;
                }
            } else {
                \cli\line('%rHash length incorrect! Please provide correct hash%w');

                return false;
            }
        } else {
            \cli\line('%rUnknown lookup type%w');

            exit;
        }

        $method = 'search' . ucfirst($this->settings['--search-method']);

        $foundHash = $this->{$method}($hashFile, $hash);

        if (isset($foundHash) && $foundHash === true) {
            if (count($this->hashFoundArr) > 1) {
                $this->showFoundDetails();
            } else if (count($this->hashFoundArr) === 1 && isset($this->hashFoundArr['timer'][1])) {
                if ($this->incorectHashTypeFile) {
                    echo 'Hash file is of type NTLM. Set ntlm argument for lookup.' . PHP_EOL;
                } else {
                    echo 'Hash not found in ' . $hashFile . '.txt using method ' . ucfirst($this->settings['--search-method']) . '.' . PHP_EOL;
                    echo 'Search took ' . $this->hashFoundArr['timer'][1]['difference'] . '(s) and ' . $this->hashFoundArr['timer'][1]['memoryusage'] . ' of memory.' .  PHP_EOL;
                }
            }
        } else {
            if ($this->incorectHashTypeFile) {
                echo 'Hash file is of type NTLM. Set ntlm argument for lookup.' . PHP_EOL;
            } else {
                echo 'Hash not found in ' . $hashFile . '.txt using method ' . ucfirst($this->settings['--search-method']) . '.' . PHP_EOL;
            }

            return false;
        }
    }

    protected function searchCache($hashFileName, $hash)
    {
        $this->microtime = 0;

        $this->cache = new Cache($this->settings);

        $settings = $this->cache->getSettings();

        $hashKey = str_replace($hashFileName, $settings['--cache-prefix'], $hash);

        $this->setMicroTimer('searchCacheStart', true);

        $cachedHash = $this->cache->redis->get($hashKey);

        if ($cachedHash) {
            $this->addToHashFoundArr($hashFileName . '.txt', trim(substr($hash, 5, null)), trim($cachedHash), false, true);

            $this->setMicroTimer('searchIndexEnd', true);

            $this->hashFoundArr['timer'] = $this->getMicroTimer();

            return true;
        }

        return false;
        var_dump();die();
    }

    protected function searchIndex($hashFileName, $hash)
    {
        try {
            if ($this->localContent->fileExists('index/' . $hashFileName . '/' . substr($hash, 5, null) . '.txt')) {
                $this->microtime = 0;

                $this->setMicroTimer('searchIndexStart', true);

                $this->addToHashFoundArr(
                    $hashFileName . '.txt',
                    trim(substr($hash, 5, null)),
                    trim($this->localContent->read('index/' . $hashFileName . '/' . substr($hash, 5, null) . '.txt')),
                    true
                );

                $this->setMicroTimer('searchIndexEnd', true);

                $this->hashFoundArr['timer'] = $this->getMicroTimer();

                return true;
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException | Exception $e) {
            echo $e->getMessage() . PHP_EOL;

            return false;
        }

        return false;
    }

    protected function searchArray($hashFileName, $hash)
    {
        try {
            $this->microtime = 0;

            $this->setMicroTimer('arrLookupStart', true);

            $file = $this->getFileContents('downloads/' . $hashFileName . '.txt');

            $filesContent = explode(PHP_EOL, $file);

            $hashFoundArr = [];

            foreach ($filesContent as $fileHash) {
                $fileHash = explode(':', $fileHash);

                if (count($fileHash) === 2) {
                    if (($this->isNtlm && strlen($fileHash[0]) !== 27) ||
                        (!$this->isNtlm && strlen($fileHash[0]) === 27)
                    ) {
                        $this->incorectHashTypeFile = true;

                        return false;
                    } else {
                        if ($hashFileName . $fileHash[0] === $hash) {
                            $this->addToHashFoundArr($hashFileName . '.txt', trim($fileHash[0]), trim($fileHash[1]));

                            break;
                        }
                    }
                }
            }

            $this->setMicroTimer('arrLookupEnd', true);

            $this->hashFoundArr['timer'] = $this->getMicroTimer();

            return true;
        } catch (UnableToReadFile | FilesystemException | \Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return false;
    }

    protected function searchString($hashFileName, $hash)
    {
        try {
            $this->microtime = 0;

            $this->setMicroTimer('stringLookupStart', true);

            $file = $this->getFileContents('downloads/' . $hashFileName . '.txt');

            $stringSearch = preg_grep('/' . substr($hash, 5, null) . ':/', explode("\n", $file));

            if (count($stringSearch) === 1) {
                $fileHash = explode(':', $stringSearch[array_key_first($stringSearch)]);

                if ($fileHash[0] === substr($hash, 5, null)) {
                    $this->addToHashFoundArr($hashFileName . '.txt', trim($fileHash[0]), trim($fileHash[1]));
                }
            }

            $this->setMicroTimer('stringLookupEnd', true);

            $this->hashFoundArr['timer'] = $this->getMicroTimer();

            return true;
        } catch (UnableToReadFile | FilesystemException | \Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return false;
    }

    protected function searchStream($hashFileName, $hash)
    {
        try {
            $this->microtime = 0;

            $this->setMicroTimer('streamLookupStart', true);

            $hashFileContent = $this->getFileContents('downloads/' . $hashFileName . '.txt', true);

            while(!feof($hashFileContent)) {
                $fileHash = explode(':', trim(fgets($hashFileContent)));

                if (count($fileHash) === 2) {
                    if (($this->isNtlm && strlen($fileHash[0]) !== 27) ||
                        (!$this->isNtlm && strlen($fileHash[0]) === 27)
                    ) {
                        $this->incorectHashTypeFile = true;

                        return false;
                    } else {
                        if ($hashFileName . $fileHash[0] === $hash) {
                            $this->addToHashFoundArr($hashFileName . '.txt', trim($fileHash[0]), trim($fileHash[1]));

                            break;
                        }
                    }
                }
            }

            $this->setMicroTimer('streamLookupEnd', true);

            $this->hashFoundArr['timer'] = $this->getMicroTimer();

            return true;
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            echo $e->getMessage();
        }

        return false;
    }

    protected function addToHashFoundArr($hashFile, $hash, $count, $indexed = false, $cached = false)
    {
        $this->hashFoundArr['hashFile'] = $hashFile;
        $this->hashFoundArr['hash'] = $hash;
        $this->hashFoundArr['count'] = $count;
        $this->hashFoundArr['indexed'] = $indexed;
        $this->hashFoundArr['cached'] = $cached;
    }

    protected function showFoundDetails()
    {
        $helpTable = new Table();

        $helpTable->setHeaders($this->getHeaders());

        $helpTable->setRows($this->getRow());

        $helpTable->setRenderer(new Ascii());

        $helpTable->display();
    }

    protected function setMicroTimer($reference, $calculateMemoryUsage = false)
    {
        $microtime['reference'] = $reference;

        if ($this->microtime === 0) {
            $microtime['difference'] = 0;
            $this->microtime = microtime(true);
        } else {
            $now = microtime(true);
            $microtime['difference'] = $now - $this->microtime;
            $this->microtime = $now;
        }

        if ($calculateMemoryUsage) {
            if ($this->memoryusage === 0) {
                $microtime['memoryusage'] = 0;
                $this->memoryusage = memory_get_usage();
            } else {
                $currentMemoryUsage = memory_get_usage();
                $microtime['memoryusage'] = $this->getMemUsage($currentMemoryUsage - $this->memoryusage);
                $this->memoryusage = $currentMemoryUsage;
            }
        }

        array_push($this->microTimers, $microtime);
    }

    public function getMicroTimer()
    {
        return $this->microTimers;
    }

    protected function getMemUsage($bytes)
    {
        $unit=array('b','kb','mb','gb','tb','pb');

        return @round($bytes/pow(1024,($i=floor(log($bytes,1024)))),2).' '.$unit[$i];
    }

    protected function getHeaders()
    {
        return ['Hash', 'Hash File', 'Method', 'Count', 'NTLM', 'Indexed', 'Cached', 'Time (s)', 'Memory (kb)'];
    }

    protected function getRow()
    {
        return
            [
                [
                    (str_replace('.txt', '', $this->hashFoundArr['hashFile'])) . $this->hashFoundArr['hash'],
                    $this->hashFoundArr['hashFile'],
                    ucfirst($this->settings['--search-method']),
                    $this->hashFoundArr['count'],
                    ($this->isNtlm === true) ? 'Yes' : 'No',
                    ($this->hashFoundArr['indexed'] === true) ? 'Yes' : 'No',
                    ($this->hashFoundArr['cached'] === true) ? 'Yes' : 'No',
                    $this->hashFoundArr['timer'][1]['difference'],
                    $this->hashFoundArr['timer'][1]['memoryusage']
                ]
            ];
    }

    protected function getFileContents($filePath, $stream = false)
    {
        try {
            if ($this->localContent->fileExists($filePath)) {
                if ($stream) {
                    $fileContent = $this->localContent->readStream($filePath);

                    return $fileContent;
                }

                return $this->localContent->read($filePath);
            } else if ($this->localContent->fileExists(str_replace('.txt', '.zip', $filePath))) {
                $this->deCompressHashFile(str_replace('.txt', '.zip', $filePath));

                if ($this->localContent->fileExists($filePath)) {
                    if ($stream) {
                        $fileContent = $this->localContent->readStream($filePath);
                    } else {
                        $fileContent = $this->localContent->read($filePath);
                    }

                    $this->localContent->delete($filePath);

                    return $fileContent;
                }
            }
        } catch (UnableToCheckExistence | UnableToReadFile | UnableToDeleteFile | FilesystemException $e) {
            echo $e->getMessage();
        }

        return false;
    }
}