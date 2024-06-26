<?php

namespace PHPPwnedPasswordsDownloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPPwnedPasswordsDownloader\Base;
use PHPPwnedPasswordsDownloader\Cache;
use PHPPwnedPasswordsDownloader\Index;
use PHPPwnedPasswordsDownloader\Sort;

class Download extends Base
{
    public $remoteWebContent;

    public $poolCount = 0;

    public $lastReadHash;

    protected $apiUri = 'https://api.pwnedpasswords.com/range/';

    protected $new = 0;

    protected $check = 0;

    protected $noChange = 0;

    protected $sort;

    protected $cache;

    protected $index;

    public function __construct(array $settings = [], array $guzzleOptions = [])
    {
        $this->remoteWebContent = new Client($guzzleOptions);

        parent::__construct();

        $this->settings = array_merge(
            [
                '--get'                 => 'all',
                '--max_execution_time'  => 18000,
                '--force'               => false,
                '--resume'              => true,
                '--compress'            => false,
                '--type'                => 'sha',
                '--async'               => false,
            ],
            $settings
        );

        if (isset($this->settings['--sort']) && (bool) $this->settings['--sort'] === true) {
            $this->sort = new Sort($this->settings);
        }
        if (isset($this->settings['--cache']) && (bool) $this->settings['--cache'] === true) {
            $this->cache = new Cache($this->settings);
        }
        if (isset($this->settings['--index']) && (bool) $this->settings['--index'] === true) {
            $this->index = new Index($this->settings);
        }

        if (isset($this->settings['--check']) || isset($this->settings['--check-download'])) {
            $this->settings['--get'] = false;
        }

        if ((int) ini_get('max_execution_time') < (int) $this->settings['--max_execution_time']) {
            set_time_limit((int) $this->settings['--max_execution_time']);
        }
    }

    public function run()
    {
        try {
            if ($this->localContent->fileExists('resume.txt')) {
                $this->resumeFrom = $this->localContent->read('resume.txt');
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        if ($this->settings['--async'] && (int) $this->settings['--async'] > 0) {
            try {
                if ($this->localContent->fileExists('pool.txt')) {
                    $this->localContent->delete('pool.txt');
                }
            } catch (UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }
        }

        if ($this->settings['--get'] &&
            ($this->settings['--get'] === 'one' ||
             $this->settings['--get'] === 'count' ||
             $this->settings['--get'] === 'multiple' ||
             $this->settings['--get'] === 'range' ||
             $this->settings['--get'] === 'hashfile' ||
             $this->settings['--get'] === 'intfile')
        ) {
            if (!isset($this->settings['--hashes'])) {
                if ($this->settings['--get'] !== 'hashfile' && $this->settings['--get'] !== 'intfile') {
                    \cli\line('%rPlease provide --hashes argument.%w');

                    return false;
                }
            }

            $this->settings['--resume'] = false;
            $this->settings['--async'] = false;

            if ($this->settings['--get'] === 'one') {
                if (!$this->checkHashLength($this->settings['--hashes'])) {
                    \cli\line('%rPlease provide correct hash. Hash should be 5 characters long. Error on hash: ' . $this->settings['--hashes'] . '%w');

                    return false;
                }

                $this->hashRangesEnd = 1;

                $this->newProgress();

                $this->downloadHash(strtoupper(trim($this->settings['--hashes'])));

                $message =
                    'Downloading new hash: ' . $this->new . ' | ' .
                    'Skipped (same eTag) : ' . $this->noChange .
                    ' (' . ($this->hashCounter + 1) . '/' . $this->hashRangesEnd . ')';

                $this->updateProgress($message);

                return true;
            } else if ($this->settings['--get'] === 'multiple') {
                $hashes = trim(trim($this->settings['--hashes']), ',');

                $hashes = explode(',', $hashes);

                $this->hashRangesEnd = count($hashes);

                if ($this->hashRangesEnd === 1) {
                    \cli\line('%rPlease provide multiple hashes (comma separated, no spaces).%w');

                    return false;
                }

                foreach ($hashes as $hashKey => $hash) {
                    if (!$this->checkHashLength($hash)) {
                        \cli\line('%rPlease provide correct hash. Hash should be 5 characters long. Error on hash: ' . $hash . '%w');

                        return false;
                    }
                }

                $this->newProgress();

                foreach ($hashes as $hashKey => $hash) {
                    $this->downloadHash(strtoupper(trim($hash)));

                    $message = 'Downloading new hash: ' . $this->new . ' | ' .
                               'Skipped (same eTag) : ' . $this->noChange .
                               ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                    $this->updateProgress($message);
                }
            } else if ($this->settings['--get'] === 'range') {
                $hashes = trim(trim($this->settings['--hashes']), ',');

                $hashes = explode(',', $hashes);

                if (count($hashes) !== 2) {
                    \cli\line('%rPlease provide correct start and end range!%w');

                    return false;
                }

                if (strtoupper($hashes[0]) === strtoupper($hashes[1])) {
                    \cli\line('%rRange Hash cannot be same! Please provide correct start and end range.%w');

                    return false;
                }

                if (!$this->checkHashLength($hashes[0]) || !$this->checkHashLength($hashes[1])) {
                    if (!$this->checkHashLength($hashes[0])) {
                        $errorAt = $hashes[0];
                    } else {
                        $errorAt = $hashes[1];
                    }

                    \cli\line('%rPlease provide correct start and end range. Hash should be 5 characters long. Error on hash: ' . $errorAt . '%w');

                    return false;
                }

                try {
                    $unPackedStart = $this->convert(null, $hashes[0]);
                    $unPackedEnd = $this->convert(null, $hashes[1]);

                    if (is_int($unPackedStart) && is_int($unPackedEnd)) {
                        if ($unPackedStart >= $unPackedEnd) {
                            \cli\line('%rHash start is greater than hash end! Please provide correct start and end range.%w');

                            return false;
                        }

                        $this->hashRangesStart = $unPackedStart;
                        $this->hashRangesEnd = $unPackedEnd + 1;
                    } else {
                        \cli\line('%rPlease provide correct start and end range!%w');

                        return false;
                    }
                } catch (\Exception $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }

                $this->newProgress();
            } else if ($this->settings['--get'] === 'hashfile') {
                $hashfile = 'hashfile.txt';

                if (isset($this->settings['--hashes'])) {
                    $hashfile = $this->settings['--hashes'];
                }

                try {
                    if ($this->localContent->fileExists($hashfile)) {
                        $hashfile = $this->localContent->read($hashfile);

                        $hashfile = trim(trim($hashfile), ',');
                    } else {
                        \cli\line('%rHashfile does not exists! Please add comma separated hash to ' . $hashfile . ' in data folder.%w');

                        return false;
                    }

                    $hashfile = explode(',', $hashfile);

                    if (count($hashfile) === 0) {
                        \cli\line('%rHashfile has no hashes. Please add comma separated hash to' . $hashfile . '%w');

                        return false;
                    }

                    foreach ($hashfile as $hashKey => $hash) {
                        if (!$this->checkHashLength($hash)) {
                            \cli\line('%rPlease provide correct hash. Hash should be 5 characters long. Error on hash: ' . $hash . '%w');

                            return false;
                        }
                    }

                    $this->hashRangesEnd = count($hashfile);

                    $this->newProgress();

                    foreach ($hashfile as $hashKey => $hash) {
                        $this->downloadHash(strtoupper(trim($hash)));

                        $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                   'Skipped (same eTag) : ' . $this->noChange .
                                   ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                        $this->updateProgress($message);
                    }

                    $this->finishProgress();

                    return true;
                } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else if ($this->settings['--get'] === 'intfile') {
                $intfile = 'intfile.txt';

                if (isset($this->settings['--hashes'])) {
                    $intfile = $this->settings['--hashes'];
                }

                try {
                    if ($this->localContent->fileExists($intfile)) {
                        $intfile = $this->localContent->read($intfile);

                        $intfile = trim(trim($intfile), ',');
                    } else {
                        \cli\line('%rIntfile does not exists! Please add comma separated integers to ' . $intfile . ' in folder data.' . '%w');

                        return false;
                    }

                    $intfile = explode(',', $intfile);

                    if (count($intfile) === 0) {
                        \cli\line('%rIntfile has no integers. Please add comma separated integers to ' . $intfile . '%w');

                        return false;
                    }

                    $this->hashRangesEnd = count($intfile);

                    $this->newProgress();

                    foreach ($intfile as $hashKey => $hashCounter) {
                        $this->hashCounter = $hashCounter;

                        $convertedHash = $this->convert($hashCounter);

                        if (!$convertedHash) {
                            \cli\line('%rFailed to convert counter ' . $hashCounter . ' to hash!%w');

                            return false;
                        }

                        $this->downloadHash(strtoupper(trim($convertedHash)));

                        $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                   'Skipped (same eTag) : ' . $this->noChange .
                                   ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                        $this->updateProgress($message);
                    }

                    $this->finishProgress();

                    return true;
                } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            }
        } else if ((isset($this->settings['--check']) &&
                    (bool) $this->settings['--check']) ||
                   (isset($this->settings['--check-download']) &&
                    (bool) $this->settings['--check-download'])
        ) {
            try {
                if ($this->localContent->fileExists('checkfile.txt')) {
                    $this->localContent->delete('checkfile.txt');
                }
                if ($this->localContent->fileExists('resume.txt')) {
                    $this->localContent->delete('resume.txt');
                }
            } catch (UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }

            $this->settings['--resume'] = false;

            $this->check = 1;

            if (isset($this->settings['--check-download']) &&
                (bool) $this->settings['--check-download']
            ) {
                $this->check = 2;

                $this->settings['--resume'] = true;
            }

            $this->newProgress('Checking...');
        } else {
            $this->newProgress();
        }

        //Run Counter
        for ($hashCounter = ($this->settings['--resume'] && $this->resumeFrom > 0) ? $this->resumeFrom : $this->hashRangesStart;
             $hashCounter < $this->hashRangesEnd;
             $hashCounter++
         ) {
            $this->hashCounter = $hashCounter;

            $convertedHash = $this->convert($hashCounter);

            if (!$convertedHash) {
                if ((bool) $this->settings['--force']) {
                    $this->writeToFile('converterror.txt', $hashCounter);
                } else {
                    \cli\line('%rFailed to convert counter ' . $hashCounter . ' to hash!%w');
                }

                continue;
            }

            if ($this->check) {
                try {
                    if ((!$this->localContent->fileExists('downloads/' . strtoupper($convertedHash) . '.txt') &&
                        !$this->localContent->fileExists('downloads/' . strtoupper($convertedHash) . '.zip')) ||
                        !$this->localContent->fileExists('etags/' . strtoupper($convertedHash) . '.txt')
                    ) {
                        $this->writeToFile('checkfile.txt', $convertedHash);
                    }
                } catch (UnableToCheckExistence | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else {
                if ((int) $this->settings['--async'] > 0) {
                    $this->writeToFile('pool.txt', $convertedHash);

                    $this->poolCount++;
                } else {
                    $this->downloadHash($convertedHash, $hashCounter);
                }
            }

            $message = null;

            if ($this->check ||
                ($this->check && (int) $this->settings['--async'] > 0)
            ) {
                $message = 'Checking hash ' . strtoupper($convertedHash) . '... (' . ($hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
            } else if ((int) $this->settings['--async'] > 0) {
                $message = 'Adding hash to pool ' . strtoupper($convertedHash) . '... (' . ($hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
            } else {
                $message = 'Downloading hash ' . strtoupper($convertedHash) . '... (' . ($hashCounter + 1) . '/' . $this->hashRangesEnd . ')';
            }

            $this->updateProgress($message);
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
                    $this->finishProgress();

                    \cli\line('%gCheck found no missing hashes!%w');

                    return false;
                }

                if ($this->check === 1) {
                    $this->finishProgress();

                    \cli\line('%yCheck found ' . count($checkfile) . ' hashes missing! Use the --check-download=true to check & download the missing hashes.%w');

                    return true;
                } else if ($this->check === 2) {
                    $this->finishProgress();

                    if ((int) $this->settings['--async'] > 0) {
                        \cli\line('%bCheck found ' . count($checkfile) . ' hashes missing! Adding hashes to pool...%w');
                    } else {
                        \cli\line('%bCheck found ' . count($checkfile) . ' hashes missing! Downloading missing hashes...%w');
                    }

                    $this->hashRangesEnd = count($checkfile);

                    $this->newProgress();

                    foreach ($checkfile as $hashKey => $hash) {
                        if ((int) $this->settings['--async'] > 0) {
                            $this->writeToFile('pool.txt', $hash);

                            $this->poolCount++;

                            $this->updateProgress('Adding hash to pool ' . strtoupper($hash) . '... (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')');
                        } else {
                            $this->downloadHash(strtoupper(trim($hash)));

                            $message = 'Downloading new hash: ' . $this->new . ' | ' .
                                       'Skipped (same eTag) : ' . $this->noChange .
                                       ' (' . ($hashKey + 1) . '/' . $this->hashRangesEnd . ')';

                            $this->updateProgress($message);
                        }
                    }
                }
            } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }
        }

        if ($this->poolCount > 0) {
            $this->finishProgress();

            \cli\line('%bAdded ' . $this->poolCount . ' hashes to pool! Downloading pool hashes...%w');

            $this->newProgress();

            $this->downloadHashUsingPool();
        }

        $this->finishProgress();

        return true;
    }

    protected function downloadHash(string $hash, int $hashCounter = null)
    {
        $headers = $this->getHeaders($hash);

        try {
            $response = $this->remoteWebContent->request('GET', $this->apiUri . $hash . ($this->settings['--type'] === 'ntlm' ? '?mode=ntlm' : ''), $headers);
        } catch (\Exception $e) {
            if ((bool) $this->settings['--force']) {
                $this->writeToFile('errors.txt', $hash);

                return;
            } else {
                \cli\line('%rFailed to download file with ' . strtoupper($hash) . '. Error : ' . $e->getMessage() . '%w');

                exit;
            }
        }

        $this->processResponse($response, $hash, $hashCounter);
    }

    protected function checkHashLength($hash)
    {
        if (strlen(trim($hash)) !== 5) {
            return false;
        }

        return true;
    }

    protected function downloadHashUsingPool()
    {
        try {
            if (!$this->localContent->fileExists('pool.txt')) {
                \cli\line('%rPoolfile does not exists!.%w');

                return false;
            }

            $poolRequests = function() {
                $poolFile = fopen(__DIR__ . '/../data/pool.txt', "r");

                while(!feof($poolFile)) {
                    $hash = trim(fgets($poolFile));

                    if ($hash !== '') {
                        $headers = $this->getHeaders($hash);

                        yield $hash => new Request('GET', $this->apiUri . $hash . ($this->settings['--type'] === 'ntlm' ? '?mode=ntlm' : ''), $headers['headers'] ?? []);

                        $this->lastReadHash = $hash;
                    }
                }

                fclose($poolFile);
            };

            $this->hashCounter = 0;

            $pool = new Pool($this->remoteWebContent, $poolRequests($this->poolCount), [
                'concurrency'   => (int) $this->settings['--async'],
                'fulfilled'     => function (Response $response, $index) {
                    $this->processResponse($response, $index);

                    $message =
                        'Downloading new hash: ' . $this->new . ' | ' .
                        'Skipped (same eTag) : ' . $this->noChange .
                        ' (' . ($this->hashCounter + 1) . '/' . $this->hashRangesEnd . ')';

                    $this->updateProgress($message);

                    $this->hashCounter = $this->hashCounter + 1;
                },
                'rejected'      => function (RequestException $reason, $index) {
                    $this->processResponse($reason, $index);
                },
            ]);

            $promise = $pool->promise();

            $promise->wait();
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
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
            if ((bool) $this->settings['--force']) {
                $this->writeToFile('errors.txt', $hash);

                return;
            } else {
                \cli\line('%rFailed to download file with ' . strtoupper($hash) . '. Error : ' . $response->getMessage() . '%w');

                exit;
            }
        } else {
            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 304) {
                if ((bool) $this->settings['--force']) {
                    $this->writeToFile('errors.txt', $hash);

                    return;
                } else {
                    \cli\line('%rFailed to download file with ' . strtoupper($hash) . '. Error : ' . $response->getStatusCode() . '%w');

                    exit;
                }
            }

            if ($response->getStatusCode() === 200) {
                try {
                    $this->localContent->write('downloads/' . strtoupper($hash) . '.txt', $response->getBody()->getContents());

                    if ($response->getHeader('eTag') && isset($response->getHeader('eTag')[0])) {
                        $etag = $response->getHeader('eTag')[0];
                    }
                    $this->localContent->write('etags/' . strtoupper($hash) . '.txt', $etag);

                    $this->new = $this->new + 1;

                    $this->writeToFile('new.txt', strtoupper($hash));
                } catch (UnableToWriteFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else if ($response->getStatusCode() === 304) {
                $this->noChange = $this->noChange + 1;

                $this->writeToFile('nochange.txt', strtoupper($hash));
            }

            if ((bool) $this->settings['--resume']) {
                $this->localContent->write('resume.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
            }

            if (isset($this->settings['--sort']) && (bool) $this->settings['--sort'] && $this->sort) {
                $this->sort->run(strtoupper($hash), false);
            }

            if (isset($this->settings['--cache']) && (bool) $this->settings['--cache'] && $this->cache) {
                $this->cache->run(strtoupper($hash), false);
            }

            if (isset($this->settings['--index']) && (bool) $this->settings['--index'] && $this->index) {
                $this->index->run(strtoupper($hash), false);
            }

            if ((bool) $this->settings['--compress']) {
                if ($this->compressHashFile('downloads/' . strtoupper($hash) . '.txt')) {
                    $this->localContent->delete('downloads/' . strtoupper($hash) . '.txt');
                }
            }
        }

        return true;
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
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        return false;
    }
}