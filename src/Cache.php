<?php

namespace PHPPwnedPasswordsDownloader;

use FilesystemIterator;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPPwnedPasswordsDownloader\Base;
use Redis;

class Cache extends Base
{
    public $redis;

    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->settings = array_merge(
            [
                '--resume'          => true,
                '--remove-cached'   => false,
                '--cache-prefix'    => 'pwned-',
                '--cache-count'     => 500
            ],
            $settings
        );

        $this->redis = new Redis();
        $redisHost = '127.0.0.1';
        $redisPort = 6379;

        if (isset($this->settings['--cache-host'])) {
            $host = explode(':', $this->settings['--cache-host']);

            if (count($host) !== 2) {
                \cli\line('%rPlease provide correct hostname and port number. Format - hostIP:portNumber%w');

                exit;
            }

            $redisHost = $host[0];
            $redisPort = $host[1];
        }

        if (!$this->redis->connect($redisHost, $redisPort)) {
            \cli\line('%rUnable to connect to Redis on provided host/port%w');

            exit;
        }
    }

    public function run($hash = null, $progress = true)
    {
        if ($hash) {
            $this->settings['--hashes'] = $hash;
        }

        if ($progress) {
            $this->newProgress();
        }

        if (isset($this->settings['--hashes'])) {//Just one hash file.
            try {
                $path = $this->hashDir . strtoupper($this->settings['--hashes']) . '.txt';

                try {
                    if ($this->localContent->fileExists($path)) {
                        $hashFileContent = $this->localContent->readStream($path);
                    } else if ($this->localContent->fileExists($this->hashDir . strtoupper($this->settings['--hashes']) . '.zip')) {
                        $this->deCompressHashFile($this->hashDir . strtoupper($this->settings['--hashes']) . '.zip');

                        if ($this->localContent->fileExists($path)) {
                            $hashFileContent = $this->localContent->readStream($path);

                            $this->localContent->delete($this->hashDir . strtoupper($this->settings['--hashes']) . '.txt');
                        }
                    }
                } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }

                $this->extractAndCacheHash($path, $hashFileContent);

                if ($progress) {
                    $this->updateProgress('Indexing hash '. strtoupper($this->settings['--hashes']) . ' (1/1)');

                    $this->progress->finish();
                }
            } catch (\Exception | UnableToCheckExistence | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }
        } else {
            try {
                if ($this->localContent->fileExists('resumecache.txt')) {
                    $this->resumeFrom = $this->localContent->read('resumecache.txt');
                }
            } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }

            if ($this->hashFilesCount !== $this->hashRangesEnd) {//Less files in the downloads area
                try {
                    $hashFiles = $this->localContent->listContents($this->hashDir);

                    foreach ($hashFiles as $key => $hashFile) {
                        $path = $hashFile->path();

                        $this->hashCounter = $key;

                        if (str_contains($path, '.zip')) {
                            $this->deCompressHashFile($path);

                            $hashFileToIndex = str_replace($this->hashDir, '', str_replace('.zip', '', $path));

                            $path = str_replace('.zip', '.txt', $path);
                        } else {
                            $hashFileToIndex = str_replace($this->hashDir, '', str_replace('.txt', '', $path));
                        }

                        $this->updateProgress($hashFileToIndex);

                        $this->extractAndCacheHash($path, $this->localContent->readStream($path));

                        if (str_contains($hashFile->path(), '.zip')) {//we are deleting .txt file because we change path above.
                            if (isset($this->settings['--remove-cached']) && (bool) $this->settings['--remove-cached']) {
                                $this->compressHashFile($path);
                            }

                            $this->localContent->delete($path);
                        }
                    }
                } catch (UnableToCheckExistence | UnableToListContents | UnableToReadFile | UnableToDeleteFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else {
                //Run Counter
                for ($hashCounter = ($this->resumeFrom > 0) ? $this->resumeFrom : $this->hashRangesStart;
                     $hashCounter < $this->hashRangesEnd;
                     $hashCounter++
                 ) {
                    $this->hashCounter = $hashCounter;

                    $convertedHash = $this->convert($hashCounter);

                    try {
                        if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.txt')) {
                            $this->updateProgress($convertedHash);

                            $this->extractAndCacheHash(
                                $this->hashDir . $convertedHash . '.txt',
                                $this->localContent->readStream($this->hashDir . $convertedHash . '.txt')
                            );

                            $this->writeToFile('cached.txt', $convertedHash);
                        } else if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.zip')) {
                            $this->deCompressHashFile($this->hashDir . $convertedHash . '.zip');

                            if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.txt')) {
                                $this->updateProgress($convertedHash);

                                $this->extractAndCacheHash(
                                    $this->hashDir . $convertedHash . '.txt',
                                    $this->localContent->readStream($this->hashDir . $convertedHash . '.txt')
                                );

                                $this->writeToFile('cached.txt', $convertedHash);

                                $this->localContent->delete($this->hashDir . $convertedHash . '.txt');
                            } else {
                                $this->writeToFile('cachesourcenotfound.txt', $convertedHash);

                                continue;
                            }

                            if (isset($this->settings['--remove-cached']) && (bool) $this->settings['--remove-cached']) {
                                $this->compressHashFile($this->hashDir . $convertedHash . '.txt');
                            }
                        }

                        $this->localContent->write('resumecache.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
                    } catch (UnableToCheckExistence | UnableToReadFile | UnableToDeleteFile | FilesystemException $e) {
                        \cli\line('%r' . $e->getMessage() . '%w');

                        exit;
                    }
                }
            }

            $this->progress->finish();

            return true;
        }
    }

    protected function extractAndCacheHash($hashFilePath, $hashFileContent)
    {
        $hashFile = str_replace($this->hashDir, '', str_replace('.txt', '', $hashFilePath));

        if (isset($this->settings['--remove-cached']) && (bool) $this->settings['--remove-cached']) {
            $remainingHashes = [];
        }

        try {
            while(!feof($hashFileContent)) {
                $hashLine = trim(fgets($hashFileContent));

                $hash = explode(':', $hashLine);

                if (count($hash) !== 2) {
                    continue;
                }

                if (isset($remainingHashes)) {
                    $remainingHashes[$hash[0]] = $hash[1];
                }

                if ($this->settings['--cache-count'] !== 0 && (int) $hash[1] <= $this->settings['--cache-count']) {//Only cache often used passwords
                    continue;
                }

                if (isset($remainingHashes[$hash[0]])) {
                    unset($remainingHashes[$hash[0]]);
                }

                //Store To Cache
                $this->redis->set($this->settings['--cache-prefix'] . $hash[0], $hash[1]);

                if ($this->redis->get($this->settings['--cache-prefix'] . $hash[0]) === false) {
                    \cli\line('%rNot able to cache to APCU%w');

                    exit;
                }
            }

            if (isset($remainingHashes) && count($remainingHashes) > 0) {
                try {
                    $this->localContent->delete($hashFilePath);

                    foreach ($remainingHashes as $hash => $count) {
                        @file_put_contents(__DIR__ . '/../data/' . $hashFilePath, $hash . ':' . $count . PHP_EOL, FILE_APPEND | LOCK_EX);
                    }
                } catch (\Exception | UnableToDeleteFile | FilesystemException) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            }
        } catch (UnableToWriteFile | FilesystemException | \Exception $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }
    }

    protected function newProgress($processType = 'Caching...')
    {
        $this->hashFilesCount = iterator_count(new FilesystemIterator(__DIR__ . '/../data/' . $this->hashDir, FilesystemIterator::SKIP_DOTS));

        if ($this->hashFilesCount === 0) {
            \cli\line('%rDownloads directory empty. Nothing to cache.%w');

            exit;
        }

        parent::newProgress($processType);
    }
}