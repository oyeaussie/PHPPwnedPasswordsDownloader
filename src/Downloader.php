<?php

namespace PHPPwnedPasswordsDownloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use cli\progress\Bar;

class Downloader
{
    protected $apiUri = 'https://api.pwnedpasswords.com/range/';

    protected $ntlm = false;

    protected $hashCounter;

    protected $hashRangesStart = 0;

    protected $hashRangesEnd = 1024 * 1024;

    protected $resume = true;

    protected $resumeFrom = 0;

    public $remoteWebContent;

    public $localContent;

    protected $new = 0;

    protected $check = 0;

    protected $noChange = 0;

    protected $now;

    public $progress;

    public $force = false;

    public $concurrent = 0;

    public $poolCount = 0;

    public $lastReadHash;

    public $compress = false;

    public function __construct($guzzleOptions = [])
    {
        $this->now = date('Y_m_d_H_i_s');

        $this->remoteWebContent = new Client($guzzleOptions);

        $this->localContent = new Filesystem(
            new LocalFilesystemAdapter(
                __DIR__ . '/../data/',
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS
            ),
            []
        );
    }

    public function run($arg = [])
    {
        //Change execution time to 5Hrs as download could take a while. Change as needed
        if ((int) ini_get('max_execution_time') < 18000) {
            set_time_limit(18000);
        }

        try {
            if ($this->localContent->fileExists('resume.txt')) {
                $this->resumeFrom = $this->localContent->read('resume.txt');
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            echo $e->getMessage();

            return false;
        }

        if (count($arg) > 0) {
            $arg = array_values($arg);

            if (strtolower($arg[array_key_last($arg)]) === 'compress') {
                $this->compress = true;

                if (isset($arg[array_key_last($arg) - 1]) &&
                    strtolower($arg[array_key_last($arg) - 1]) === 'ntlm'
                ) {
                    $this->ntlm = true;
                }
            } else if (strtolower($arg[array_key_last($arg)] === 'ntlm')) {
                $this->ntlm = true;
            }

            $method = strtolower($arg[0]);

            if ((int) $method > 0) {//Perform concurrent Pool Requests
                $this->concurrent = (int) $method;

                try {
                    if ($this->localContent->fileExists('pool.txt')) {
                        $this->localContent->delete('pool.txt');
                    }
                } catch (UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                    echo $e->getMessage();

                    return false;
                }

                if (PHP_SAPI === 'cli') {
                    $this->newProgress();
                }
            }

            if (count($arg) > 1) {
                array_splice($arg, 0, 1);//Remove Method
            }

            if ($method === 'ntlm') {
                $this->ntlm = true;

                if (PHP_SAPI === 'cli') {
                    $this->newProgress();
                }
            } else if ($method === 'force') {
                $this->force = true;

                if (PHP_SAPI === 'cli') {
                    $this->newProgress();
                }
            } else if ($method === 'compress') {
                if (PHP_SAPI === 'cli') {
                    $this->newProgress();
                }
            } else if ($method === 'check') {
                try {
                    if ($this->localContent->fileExists('checkfile.txt')) {
                        $this->localContent->delete('checkfile.txt');
                    }
                    if ($this->localContent->fileExists('resume.txt')) {
                        $this->localContent->delete('resume.txt');
                    }
                } catch (UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                    echo $e->getMessage();

                    return false;
                }

                $this->resume = false;

                $this->check = 1;

                if ($arg[0] === 'download') {
                    $this->check = 2;
                    $this->resume = true;

                    if (isset($arg[1]) && (int) $arg[1] > 0) {
                        $this->concurrent = (int) $arg[1];

                        try {
                            if ($this->localContent->fileExists('pool.txt')) {
                                $this->localContent->delete('pool.txt');
                            }
                        } catch (UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                            echo $e->getMessage();

                            return false;
                        }
                    }
                }

                if (PHP_SAPI === 'cli') {
                    $this->newProgress('Checking...');
                }
            } else if ($method === 'range') {
                if (isset($arg[0]) && isset($arg[1])) {
                    if ($arg[0] === $arg[1]) {
                        echo 'Range Hash cannot be same!' . PHP_EOL;

                        return false;
                    }

                    try {
                        $unPackedStart = $this->convert(null, $arg[0]);
                        $unPackedEnd = $this->convert(null, $arg[1]);

                        if (is_int($unPackedStart) && is_int($unPackedEnd)) {
                            $this->resume = false;
                            $this->hashRangesStart = $unPackedStart;
                            $this->hashRangesEnd = $unPackedEnd + 1;
                        } else {
                            echo 'Please provide correct start and end range!' . PHP_EOL;

                            return false;
                        }
                    } catch (\Exception $e) {
                        echo $e->getMessage();

                        return false;
                    }
                } else {
                    echo 'Please provide start and end range!' . PHP_EOL;

                    return false;
                }

                if (PHP_SAPI === 'cli') {
                    $this->newProgress();
                }
            } else if ($method === 'one' ||
                       $method === 'count' ||
                       $method === 'multiple' ||
                       $method === 'hashfile' ||
                       $method === 'intfile'
            ) {
                $this->resume = false;

                if (count($arg) > 0) {
                    if ($method === 'one') {
                        $this->hashRangesEnd = 1;

                        if (PHP_SAPI === 'cli') {
                            $this->newProgress();
                        }

                        $this->downloadHash(strtoupper(trim($arg[0])));

                        if (PHP_SAPI === 'cli') {
                            $this->updateProgress();
                        }

                        return true;
                    } else if ($method === 'multiple') {
                        $arg = trim(trim($arg[0]), ',');

                        $arg = explode(',', $arg);

                        $this->hashRangesEnd = count($arg);

                        if (PHP_SAPI === 'cli') {
                            $this->newProgress();
                        }

                        foreach ($arg as $hashKey => $hash) {
                            $this->downloadHash(strtoupper(trim($hash)));

                            if (PHP_SAPI === 'cli') {
                                $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                           'Skipped (same eTag) : ' . $this->noChange .
                                           ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                                $this->updateProgress($message);
                            }
                        }
                    } else if ($method === 'hashfile') {
                        try {
                            if ($this->localContent->fileExists('hashfile.txt')) {
                                $hashfile = $this->localContent->read('hashfile.txt');

                                $hashfile = trim(trim($hashfile), ',');
                            } else {
                                echo 'Hashfile does not exists! Please add comma separated hash to hashfile.txt in folder data.' . PHP_EOL;

                                return false;
                            }

                            $hashfile = explode(',', $hashfile);

                            if (count($hashfile) === 0) {
                                echo 'Hashfile has no hashes. Please add comma separated hash to hashfile!' . PHP_EOL;

                                return false;
                            }

                            $this->hashRangesEnd = count($hashfile);

                            if (PHP_SAPI === 'cli') {
                                $this->newProgress();
                            }

                            foreach ($hashfile as $hashKey => $hash) {
                                $this->downloadHash(strtoupper(trim($hash)));

                                if (PHP_SAPI === 'cli') {
                                    $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                               'Skipped (same eTag) : ' . $this->noChange .
                                               ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                                    $this->updateProgress($message);
                                }
                            }
                        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                            echo $e->getMessage();

                            return false;
                        }
                    } else if ($method === 'intfile') {
                        try {
                            if ($this->localContent->fileExists('intfile.txt')) {
                                $intfile = $this->localContent->read('intfile.txt');

                                $intfile = trim(trim($intfile), ',');
                            } else {
                                echo 'Intfile does not exists! Please add comma separated hash to intfile.txt in folder data.' . PHP_EOL;

                                return false;
                            }

                            $intfile = explode(',', $intfile);

                            if (count($intfile) === 0) {
                                echo 'Intfile has no integers. Please add comma separated integers to intfile!' . PHP_EOL;

                                return false;
                            }

                            $this->hashRangesEnd = count($intfile);

                            if (PHP_SAPI === 'cli') {
                                $this->newProgress();
                            }

                            foreach ($intfile as $hashKey => $hashCounter) {
                                $this->hashCounter = $hashCounter;

                                $convertedHash = $this->convert($hashCounter);

                                if (!$convertedHash) {
                                    throw new \Exception('Failed to convert counter ' . $hashCounter . ' to hash!' . PHP_EOL);
                                }

                                $this->downloadHash(strtoupper(trim($convertedHash)));

                                if (PHP_SAPI === 'cli') {
                                    $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                               'Skipped (same eTag) : ' . $this->noChange .
                                               ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                                    $this->updateProgress($message);
                                }
                            }
                        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                            echo $e->getMessage();

                            return false;
                        }
                    }

                    if (PHP_SAPI === 'cli') {
                        $this->progress->finish();
                    }

                    return true;
                } else {
                    if ($method === 'one') {
                        echo 'Please provide hash!' . PHP_EOL;
                    } else if ($method === 'multiple') {
                        echo 'Please provide hashes!' . PHP_EOL;
                    }

                    return false;
                }
            } else if ($this->concurrent === 0 && !$this->ntlm) {
                echo 'Unknown argument ' . $method . '!' . PHP_EOL;

                return false;
            }
        } else {
            if (PHP_SAPI === 'cli') {
                $this->newProgress();
            }
        }

        //Run Counter
        for ($hashCounter = ($this->resume && $this->resumeFrom > 0) ? $this->resumeFrom : $this->hashRangesStart; $hashCounter < $this->hashRangesEnd; $hashCounter++) {
            $this->hashCounter = $hashCounter;

            $convertedHash = $this->convert($hashCounter);

            if (!$convertedHash) {
                if ($this->force) {
                    $this->writeToLogFile('converterror.txt', $hashCounter);
                } else {
                    throw new \Exception('Failed to convert counter ' . $hashCounter . ' to hash!' . PHP_EOL);
                }

                continue;
            }

            if ($this->check) {
                try {
                    if (!$this->localContent->fileExists('downloads/' . strtoupper($convertedHash) . '.txt') &&
                        !$this->localContent->fileExists('downloads/' . strtoupper($convertedHash) . '.zip')
                    ) {
                        $this->writeToLogFile('checkfile.txt', $convertedHash);
                    }
                } catch (UnableToCheckExistence | FilesystemException $e) {
                    echo $e->getMessage();

                    return false;
                }
            } else {
                if ($this->concurrent > 0) {
                    $this->writeToLogFile('pool.txt', $convertedHash);

                    $this->poolCount++;
                } else {
                    $this->downloadHash($convertedHash, $hashCounter);
                }
            }

            if (PHP_SAPI === 'cli') {
                $message = null;

                if ($this->check) {
                    $message = 'Checking hash ' . strtoupper($convertedHash) . '... (' . ($hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
                }
                if ($this->concurrent > 0) {
                    $message = 'Adding hash to pool ' . strtoupper($convertedHash) . '... (' . ($hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
                }

                $this->updateProgress($message);
            }
        }

        if ($this->check) {
            try {
                $checkfile = $this->localContent->fileExists('checkfile.txt');

                if ($checkfile) {
                    $checkfile = $this->localContent->read('checkfile.txt');

                    $checkfile = trim(trim($checkfile), ',');

                    $checkfile = explode(',', $checkfile);
                }

                if (!$checkfile ||
                    ($checkfile && is_array($checkfile) && count($checkfile) === 0)
                ) {
                    if (PHP_SAPI === 'cli') {
                        $this->progress->finish();
                    }

                    echo 'Check found no missing hashes!' . PHP_EOL;

                    return false;
                }

                if ($this->check === 1) {
                    if (PHP_SAPI === 'cli') {
                        $this->progress->finish();
                    }

                    echo 'Check found ' . count($checkfile) . ' hashes missing! Use the argument "check download" to check & download the missing hashes.' . PHP_EOL;

                    return true;
                } else if ($this->check === 2) {
                    if (PHP_SAPI === 'cli') {
                        $this->progress->finish();

                        echo 'Check found ' . count($checkfile) . ' hashes missing! Downloading missing hashes...' . PHP_EOL;
                    }

                    $this->hashRangesEnd = count($checkfile);

                    if (PHP_SAPI === 'cli') {
                        $this->newProgress();
                    }

                    foreach ($checkfile as $hashKey => $hash) {
                        if ($this->concurrent > 0) {
                            $this->writeToLogFile('pool.txt', $hash);

                            $this->poolCount++;

                            if (PHP_SAPI === 'cli') {
                                $this->updateProgress('Adding hash to pool ' . strtoupper($hash) . '... (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')');
                            }
                        } else {
                            $this->downloadHash(strtoupper(trim($hash)));

                            if (PHP_SAPI === 'cli') {
                                $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                           'Skipped (same eTag) : ' . $this->noChange .
                                           ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                                $this->updateProgress($message);
                            }
                        }

                    }
                }
            } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                echo $e->getMessage();

                return false;
            }
        }

        if ($this->poolCount > 0) {
            if (PHP_SAPI === 'cli') {
                $this->progress->finish();

                echo 'Added ' . $this->poolCount . ' hashes to pool! Downloading pool hashes...' . PHP_EOL;

                $this->newProgress();
            }

            $this->downloadHashUsingPool();
        }

        if (PHP_SAPI === 'cli') {
            $this->progress->finish();
        }
    }

    protected function downloadHash(string $hash, int $hashCounter = null)
    {
        $headers = $this->getHeaders($hash);

        try {
            $response = $this->remoteWebContent->request('GET', $this->apiUri . $hash . ($this->ntlm === true ? '?mode=ntlm' : ''), $headers);
        } catch (\Exception $e) {
            if ($this->force) {
                $this->writeToLogFile('errors.txt', $hash);

                return;
            } else {
                throw new \Exception('Failed to download file with ' . strtoupper($hash) . '. Error : ' . $e->getMessage());
            }
        }

        $this->processResponse($response, $hash, $hashCounter);
    }

    protected function downloadHashUsingPool()
    {
        try {
            if (!$this->localContent->fileExists('pool.txt')) {
                echo 'Poolfile does not exists!.' . PHP_EOL;

                return false;
            }

            $poolRequests = function() {
                $poolFile = fopen(__DIR__ . '/../data/pool.txt', "r");

                while(!feof($poolFile)) {
                    $hash = trim(fgets($poolFile));

                    if ($hash !== '') {
                        $headers = $this->getHeaders($hash);

                        yield $hash => new Request('GET', $this->apiUri . $hash . ($this->ntlm === true ? '?mode=ntlm' : ''), $headers['headers'] ?? []);

                        $this->lastReadHash = $hash;
                    }
                }

                fclose($poolFile);
            };

            $this->hashCounter = 0;

            $pool = new Pool($this->remoteWebContent, $poolRequests($this->poolCount), [
                'concurrency'   => $this->concurrent,
                'fulfilled'     => function (Response $response, $index) {
                    $this->processResponse($response, $index);

                    if (PHP_SAPI === 'cli') {
                        $this->updateProgress();
                    }

                    $this->hashCounter = $this->hashCounter + 1;
                },
                'rejected'      => function (RequestException $reason, $index) {
                    $this->processResponse($reason, $index);
                },
            ]);

            $promise = $pool->promise();

            $promise->wait();
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            echo $e->getMessage();

            return false;
        }
    }

    public function processResponse($response, $hash, $hashCounter = null)
    {
        if (!$hashCounter) {
            if ($this->lastReadHash) {
                $hashCounter = $this->convert(null, $this->lastReadHash);
            } else {
                $hashCounter = $this->convert(null, $hash);
            }
        }

        if ($response instanceof RequestException) {
            if ($this->force) {
                $this->writeToLogFile('errors.txt', $hash);

                return;
            } else {
                throw new \Exception('Failed to download file with ' . strtoupper($hash) . '. Error : ' . $response->getMessage());
            }
        } else {
            if ($response->getStatusCode() === 200) {
                try {
                    $this->localContent->write('downloads/' . strtoupper($hash) . '.txt', $response->getBody()->getContents());

                    if ($this->compress) {
                        if ($this->compressHashFile(strtoupper($hash))) {
                            $this->localContent->delete('downloads/' . strtoupper($hash) . '.txt');
                        }
                    }

                    if ($response->getHeader('eTag') && isset($response->getHeader('eTag')[0])) {
                        $etag = $response->getHeader('eTag')[0];
                    }
                    $this->localContent->write('etags/' . strtoupper($hash) . '.txt', $etag);

                    $this->new = $this->new + 1;

                    if ($this->resume) {
                        $this->localContent->write('resume.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
                    }

                    $this->writeToLogFile('new.txt', strtoupper($hash));
                } catch (UnableToWriteFile | FilesystemException $e) {
                    echo $e->getMessage();

                    return false;
                }

                return true;
            } else if ($response->getStatusCode() === 304) {
                $this->noChange = $this->noChange + 1;

                if ($this->resume) {
                    $this->localContent->write('resume.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
                }

                if ($this->compress) {
                    if ($this->compressHashFile(strtoupper($hash))) {
                        $this->localContent->delete('downloads/' . strtoupper($hash) . '.txt');
                    }
                }

                $this->writeToLogFile('nochange.txt', strtoupper($hash));
            }
        }
    }

    public function compressHashFile($file)
    {
        try {
            if ($this->localContent->fileExists('downloads/' . strtoupper($file) . '.txt')) {
                if ($this->localContent->fileExists('downloads/' . strtoupper($file) . '.zip')) {
                    $this->localContent->delete('downloads/' . strtoupper($file) . '.zip');
                }

                $zip = new \ZipArchive;

                $zip->open(__DIR__ . '/../data/downloads/' . strtoupper($file) . '.zip', $zip::CREATE);

                $zip->addFile(__DIR__ . '/../data/downloads/' . $file . '.txt', $file . '.txt');

                $zip->close();
            }

            return true;
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            echo $e->getMessage();

            return false;
        }
    }

    public function getHeaders($hash)
    {
        $headers =
            [
                'verify'            => false,
            ];

        $etag = $this->getEtagForHash($hash);

        if ($etag) {
            $headers = array_merge($headers,
                [
                    'headers'           =>
                    [
                        'If-None-Match' => $etag
                    ]
                ]
            );
        }

        return $headers;
    }

    protected function getEtagForHash($hash)
    {
        try {
            if ($this->localContent->fileExists('downloads/' . strtoupper($hash) . '.txt') ||
                $this->localContent->fileExists('downloads/' . strtoupper($hash) . '.zip')
            ) {
                if ($this->localContent->fileExists('etags/' . strtoupper($hash) . '.txt')) {
                    return $this->localContent->read('etags/' . strtoupper($hash) . '.txt');
                }
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            echo $e->getMessage();

            return false;
        }

        return false;
    }

    protected function convert(int $counter = null, string $hex = null)
    {
        if ($hex !== null) {
            if (strlen($hex) === 5) {
                $hex = '000' . $hex;
            }

            $unpacked = unpack('N', hex2bin(strtoupper($hex)));

            if ($unpacked && count($unpacked) === 1) {
                return $unpacked[1];
            }

            throw new \Exception('Could not convert hex to integer! Please provide correct hash.');
        }

        if ($counter !== null) {
            return strtoupper(substr(bin2hex(pack('N', $counter)), 3));
        }

        return false;
    }

    protected function writeToLogFile($file, $hash)
    {
        try {
            $separator = ',';

            if ($file === 'checkfile.txt' || $file === 'pool.txt') {
                if ($file === 'pool.txt') {
                    $separator = PHP_EOL;
                }

                $fileLocation = $file;
            } else {
                $fileLocation = 'logs/' . $this->now . '/' . $file;
            }

            if ($this->localContent->fileExists($fileLocation)) {
                @file_put_contents(__DIR__ . '/../data/' . $fileLocation, $hash . $separator, FILE_APPEND | LOCK_EX);
            } else {
                $this->localContent->write($fileLocation, $hash . $separator);
            }

            return true;
        } catch (UnableToCheckExistence | UnableToWriteFile | FilesystemException $e) {
            echo $e->getMessage();

            return false;
        }
    }

    protected function newProgress($message = 'Downloading...')
    {
        $this->progress = new Bar($message, ($this->resume && $this->resumeFrom > 0) ? ($this->hashRangesEnd - $this->resumeFrom) : $this->hashRangesEnd);

        $this->progress->display();
    }

    public function updateProgress($message = null)
    {
        if (!$message) {
            $message =
                'Downloading new hash: ' . $this->new . ' | ' .
                'Skipped (same eTag) : ' . $this->noChange .
                ' (' . ($this->hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
        }

        $this->progress->tick(1, $message);
    }
}