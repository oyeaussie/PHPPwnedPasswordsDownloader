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

    protected $recordErrors = true;

    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->remoteWebContent = new Client(
            [
                'debug'           => false,
                'http_errors'     => true,
                'timeout'         => 10,
                'verify'          => false
            ]
        );

        $this->settings = array_merge(
            [
                '--get'                     => 'all',
                '--max_execution_time'      => 18000,
                '--force'                   => false,
                '--resume'                  => true,
                '--compress'                => false,
                '--type'                    => 'sha',
                '--async'                   => false,
                '--update-source'           => 'api',
                '--download-data'           => 'true',
                '--process-errors-count'    => 5
            ],
            $settings
        );

        if ($this->settings['--process-errors-count'] > 10) {
            $this->settings['--process-errors-count'] = 10;
        }

        if (isset($this->settings['--type']) && $this->settings['--type'] === 'ntlm') {
            $this->hashDir = 'ntlm/';
            $this->hashEtagsDir = 'ntlmetags/';
        }
        if (isset($this->settings['--sort']) && (bool) $this->settings['--sort']) {
            $this->sort = new Sort($this->settings);
        }
        if (isset($this->settings['--cache']) && (bool) $this->settings['--cache']) {
            $this->cache = new Cache($this->settings);
        }
        if (isset($this->settings['--index']) && (bool) $this->settings['--index']) {
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
        if (isset($this->settings['--update-source']) &&
            $this->settings['--update-source'] !== 'api'
        ) {
            if (!isset($this->settings['--update-since'])) {
                try {
                    $this->settings['--update-since'] = $this->localContent->read($this->settings['--type'] . 'lastupdate.txt');
                } catch (UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $this->settings['--type'] . 'lastupdate.txt file is missing%w');
                    \cli\line('%rEither add ' . $this->settings['--type'] . 'lastupdate.txt file with unix time of last update or provide it via argument --update-since={unix_time}%w');

                    exit;
                }
            }

            $this->updateFromDifferentSource();

            return;
        }

        try {
            if ((bool) $this->settings['--resume'] &&
                $this->resumeFrom === 0 &&
                $this->localContent->fileExists($this->settings['--type'] . 'resume.txt')
            ) {
                $this->resumeFrom = $this->localContent->read($this->settings['--type'] . 'resume.txt');
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        if ($this->settings['--async'] && (int) $this->settings['--async'] > 0) {
            try {
                if ($this->localContent->fileExists($this->settings['--type'] . 'pool.txt')) {
                    $this->localContent->delete($this->settings['--type'] . 'pool.txt');
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

            if ($this->settings['--get'] === 'one') {
                $this->settings['--resume'] = false;
                $this->settings['--async'] = false;

                if (!$this->checkHashLength($this->settings['--hashes'])) {
                    \cli\line('%rPlease provide correct hash. Hash should be 5 characters long. Error on hash: ' . $this->settings['--hashes'] . '%w');

                    return false;
                }

                $this->hashRangesEnd = 1;

                $this->newProgress();

                $download = $this->downloadHash(strtoupper(trim($this->settings['--hashes'])));

                $message =
                    'Downloading new hash: ' . $this->new . ' | ' .
                    'Skipped (same eTag) : ' . $this->noChange .
                    ' (' . ($this->hashCounter + 1) . '/' . $this->hashRangesEnd . ')';

                $this->updateProgress($message);

                return $download;
            } else if ($this->settings['--get'] === 'multiple') {
                $this->settings['--resume'] = false;
                $this->settings['--async'] = false;

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
                $this->settings['--resume'] = false;
                $this->settings['--async'] = false;

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
            } else if ($this->settings['--get'] === 'hashfile' || $this->settings['--get'] === 'intfile') {
                $file = $this->settings['--get'] . '.txt';

                if (isset($this->settings['--hashes'])) {
                    $file = $this->settings['--hashes'];
                }

                try {
                    if ($this->localContent->fileExists($file)) {
                        $file = $this->localContent->read($file);

                        $file = trim(trim($file), ',');
                    } else {
                        if ($this->settings['--get'] === 'hashfile') {
                            \cli\line('%rHashfile does not exists! Please add comma separated hash to ' . $file . ' in data folder.%w');
                        } else if ($this->settings['--get'] === 'intfile') {
                            \cli\line('%rIntfile does not exists! Please add comma separated integers to ' . $intfile . ' in folder data.' . '%w');
                        }

                        return false;
                    }

                    $file = explode(',', $file);

                    if (count($file) === 0) {
                        if ($this->settings['--get'] === 'hashfile') {
                            \cli\line('%rHashfile has no hashes. Please add comma separated hash to' . $file . '%w');
                        } else if ($this->settings['--get'] === 'intfile') {
                            \cli\line('%rIntfile has no integers. Please add comma separated integers to ' . $intfile . '%w');
                        }

                        return false;
                    }

                    if ($this->settings['--get'] === 'hashfile') {
                        foreach ($file as $hashKey => &$hash) {
                            if (!$this->checkHashLength($hash)) {
                                \cli\line('%rPlease provide correct hash. Hash should be 5 characters long. Error on hash: ' . $hash . '%w');

                                return false;
                            }

                            $hash = $this->convert(null, $hash);//convert hash to integer.
                        }
                    }

                    sort($file);

                    if ($file[array_key_first($file)] < 0 || $file[array_key_last($file)] > (1024 * 1024)) {
                        if ($this->settings['--get'] === 'hashfile') {
                            \cli\line('%rHashfile has incorrect hashes. Hashes should be between 00000-FFFFF%w');
                        } else if ($this->settings['--get'] === 'intfile') {
                            \cli\line('%rIntfile has incorrect integers. Integers should be between 0-' . (1024 * 1024) . '%w');
                        }

                        return false;
                    }

                    $this->hashRangesStart = (int) $file[array_key_first($file)];
                    $this->hashRangesEnd = (int) $file[array_key_last($file)] + 1;

                    $this->newProgress();
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
                if ($this->localContent->fileExists($this->settings['--type'] . 'checkfile.txt')) {
                    $this->localContent->delete($this->settings['--type'] . 'checkfile.txt');
                }
                if ($this->localContent->fileExists($this->settings['--type'] . 'resume.txt')) {
                    $this->localContent->delete($this->settings['--type'] . 'resume.txt');
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
        for ($hashCounter = ((bool) $this->settings['--resume'] && $this->resumeFrom > 0) ? $this->resumeFrom : $this->hashRangesStart;
             $hashCounter < $this->hashRangesEnd;
             $hashCounter++
         ) {
            $this->hashCounter = $hashCounter;

            $convertedHash = $this->convert($hashCounter);

            if (!$convertedHash) {
                if ((bool) $this->settings['--force']) {
                    $this->writeToFile($this->settings['--type'] . 'converterror.txt', $hashCounter);
                } else {
                    \cli\line('%rFailed to convert counter ' . $hashCounter . ' to hash!%w');
                }

                continue;
            }

            if ($this->check) {
                try {
                    if (isset($this->settings['--download-data']) && $this->settings['--download-data'] === 'true') {
                        if ((!$this->localContent->fileExists($this->hashDir . strtoupper($convertedHash) . '.txt') &&
                             !$this->localContent->fileExists($this->hashDir . strtoupper($convertedHash) . '.zip')) ||
                            !$this->localContent->fileExists($this->hashEtagsDir . strtoupper($convertedHash) . '.txt')
                        ) {
                            $this->writeToFile($this->settings['--type'] . 'checkfile.txt', $convertedHash);
                        }
                    } else if (!$this->localContent->fileExists($this->hashEtagsDir . strtoupper($convertedHash) . '.txt')) {
                        $this->writeToFile($this->settings['--type'] . 'checkfile.txt', $convertedHash);
                    }
                } catch (UnableToCheckExistence | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else {
                if ((int) $this->settings['--async'] > 0) {
                    $this->writeToFile($this->settings['--type'] . 'pool.txt', $convertedHash);

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
                $checkfile = $this->localContent->fileExists($this->settings['--type'] . 'checkfile.txt');

                if ($checkfile) {
                    $checkfile = $this->localContent->read($this->settings['--type'] . 'checkfile.txt');

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
                            $this->writeToFile($this->settings['--type'] . 'pool.txt', $hash);

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

        if ($this->settings['--get'] === 'all' || (isset($this->settings['--check-download']) && (bool) $this->settings['--check-download'])) {
            try {
                $this->localContent->write($this->settings['--type'] . 'lastupdate.txt', time());
            } catch (UnableToWriteFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }
        }

        if ((int) $this->settings['--process-errors-count'] !== 0) {
            $this->processErrors();
        }

        return true;
    }

    protected function downloadHash(string $hash, int $hashCounter = null)
    {
        $headers = $this->getHeaders($hash);

        try {
            $response = $this->remoteWebContent->request('GET', $this->apiUri . $hash . ($this->settings['--type'] === 'ntlm' ? '?mode=ntlm' : ''), $headers);
        } catch (\Exception $e) {
            if ((bool) $this->settings['--force'] && $this->recordErrors) {
                \cli\line('%rError while downloading hash ' . strtoupper($hash) . '. Force is applied, so adding hash to errors.txt file and continue%w');

                $this->writeToFile($this->settings['--type'] . 'errors.txt', $hash);

                return;
            } else if ($this->recordErrors) {
                \cli\line('%rFailed to download file with ' . strtoupper($hash));
                \cli\line('%rError : ' . $e->getMessage() . '%w');

                exit;
            } else {
                return false;
            }
        }

        return $this->processResponse($response, $hash, $hashCounter);
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
            if (!$this->localContent->fileExists($this->settings['--type'] . 'pool.txt')) {
                \cli\line('%rPoolfile does not exists!.%w');

                return false;
            }

            $poolRequests = function() {
                $poolFile = fopen(__DIR__ . '/../data/' . $this->settings['--type'] . 'pool.txt', "r");

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

            if (($this->settings['--resume'] && $this->resumeFrom > 0)) {
                $this->hashRangesEnd = $this->hashRangesEnd - $this->resumeFrom;
            }

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
                $this->writeToFile($this->settings['--type'] . 'errors.txt', $hash);

                return;
            } else {
                \cli\line('%rFailed to download file with ' . strtoupper($hash) . '. Error : ' . $response->getMessage() . '%w');

                exit;
            }
        } else {
            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 304) {
                if ((bool) $this->settings['--force']) {
                    $this->writeToFile($this->settings['--type'] . 'errors.txt', $hash);

                    return;
                } else {
                    \cli\line('%rFailed to download file with ' . strtoupper($hash) . '. Error : ' . $response->getStatusCode() . '%w');

                    exit;
                }
            }

            if ($response->getStatusCode() === 200) {
                try {
                    if (isset($this->settings['--download-data']) && $this->settings['--download-data'] === 'true') {
                        $this->localContent->write($this->hashDir . strtoupper($hash) . '.txt', $response->getBody()->getContents());
                    }

                    if ($response->getHeader('eTag') && isset($response->getHeader('eTag')[0])) {
                        $etag = $response->getHeader('eTag')[0];
                    }
                    $this->localContent->write($this->hashEtagsDir . strtoupper($hash) . '.txt', $etag);

                    $this->new = $this->new + 1;

                    $this->writeToFile($this->settings['--type'] . 'new.txt', strtoupper($hash));
                } catch (UnableToWriteFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else if ($response->getStatusCode() === 304) {
                $this->noChange = $this->noChange + 1;

                $this->writeToFile($this->settings['--type'] . 'nochange.txt', strtoupper($hash));
            }

            if ((bool) $this->settings['--resume']) {
                $this->localContent->write($this->settings['--type'] . 'resume.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
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
                if ($this->compressHashFile($this->hashDir . strtoupper($hash) . '.txt')) {
                    $this->localContent->delete($this->hashDir . strtoupper($hash) . '.txt');
                }
            }
        }

        return true;
    }

    protected function processErrors()
    {
        \cli\line('%bProcessing errors.txt file%w');

        $errors = [];

        try {
            if ($this->localContent->fileExists('logs/' . $this->now . '/' . $this->settings['--type'] . 'errors.txt')) {
                $errorsFile = $this->localContent->read('logs/' . $this->now . '/' . $this->settings['--type'] . 'errors.txt');

                $errors = trim(trim($errorsFile), ',');

                $errors = explode(',', $errors);
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        if (count($errors) === 0) {
            return true;
        }

        $processErrorsCount = 0;

        while ($processErrorsCount !== (int) $this->settings['--process-errors-count']) {
            foreach ($errors as $errorKey => $errorHash) {
                $this->settings['--get'] = 'one';
                $this->settings['--hashes'] = $errorHash;
                $this->settings['--update-source'] = 'api';
                $this->settings['--update-since'] = null;
                $this->new = 0;
                $this->noChange = 0;
                $this->recordErrors = false;

                \cli\line('%bTrying hash ' . $errorHash . ' again...%w');

                if ($this->run()) {
                    unset($errors[$errorKey]);
                }
            }

            $processErrorsCount++;
        }

        if (count($errors) > 0) {
            \cli\line('%rCould not resolve all errors!  %w');
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
            if ($this->localContent->fileExists($this->hashDir . strtoupper($hash) . '.txt') ||
                $this->localContent->fileExists($this->hashDir . strtoupper($hash) . '.zip')
            ) {
                if ($this->localContent->fileExists($this->hashEtagsDir . strtoupper($hash) . '.txt')) {
                    return $this->localContent->read($this->hashEtagsDir . strtoupper($hash) . '.txt');
                }
            }
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        return false;
    }

    protected function updateFromDifferentSource()
    {
        try {
            $response =
                $this->remoteWebContent->request(
                    'GET',
                    $this->settings['--update-source'] . '?since=' . $this->settings['--update-since'] . '&type=' . $this->settings['--type']
                );
        } catch (\Exception $e) {
            \cli\line('%rFailed to download update file from ' . $this->settings['--update-source']);
            \cli\line('%rError : ' . $e->getMessage() . '%w');

            exit;
        }

        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($responseData['code'] == '0' &&
                isset($responseData['data']) &&
                count($responseData['data']) > 0
            ) {
                $integers = [];

                foreach ($responseData['data'] as $time => $hashTypes) {
                    if (isset($hashTypes[$this->settings['--type']]) && count($hashTypes[$this->settings['--type']]) > 0) {
                        foreach ($hashTypes[$this->settings['--type']] as $hashes) {
                            if (str_contains($hashes, '-')) {
                                $hashRange = explode('-', $hashes);

                                if (count($hashRange) === 2) {
                                    for($i = $hashRange[0]; $i <= $hashRange[1]; $i++) {
                                        if (!isset($integers[(int) $i])) {
                                            $integers[(int) $i] = (int) $i;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                try {
                    $this->writeToFile('intfile.txt', implode(',', $integers));

                    \cli\line('%bWriting integers file to logs/' . $this->now . '/intfile.txt%w');
                } catch (UnableToWriteFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            }
        } else {
            \cli\line('%rFailed to download update file from ' . $this->settings['--update-source']);
            \cli\line('%rStatus Code : ' . $response->getStatusCode() . '%w');

            exit;
        }

        $this->settings['--get'] = 'intfile';
        $this->settings['--hashes'] = 'logs/' . $this->now . '/intfile.txt';
        $this->settings['--update-source'] = 'api';
        $this->settings['--update-since'] = null;
        $this->new = 0;
        $this->noChange = 0;
        $this->resumeFrom = $integers[array_key_first($integers)];

        \cli\line('%bExecuting download with integers file from logs/' . $this->now . '/intfile.txt%w');

        return $this->run();
    }
}