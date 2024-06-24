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

class Index extends Base
{
    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->settings = array_merge(
            [
                '--resume'          => true,
                '--remove-indexed'  => false,
                '--index-count'     => 100
            ],
            $settings
        );
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
                $path = 'downloads/' . strtoupper($this->settings['--hashes']) . '.txt';

                try {
                    if ($this->localContent->fileExists($path)) {
                        $hashFileContent = $this->localContent->readStream($path);
                    } else if ($this->localContent->fileExists('downloads/' . strtoupper($this->settings['--hashes']) . '.zip')) {
                        $this->deCompressHashFile('downloads/' . strtoupper($this->settings['--hashes']) . '.zip');

                        if ($this->localContent->fileExists($path)) {
                            $hashFileContent = $this->localContent->readStream($path);

                            $this->localContent->delete('downloads/' . strtoupper($this->settings['--hashes']) . '.txt');
                        }
                    }
                } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }

                $this->extractAndIndexHash($path, $hashFileContent);

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
                if ($this->localContent->fileExists('resumeindex.txt')) {
                    $this->resumeFrom = $this->localContent->read('resumeindex.txt');
                }
            } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }

            if ($this->hashFilesCount !== $this->hashRangesEnd) {//Less files in the downloads area
                try {
                    $hashFiles = $this->localContent->listContents('downloads/');

                    foreach ($hashFiles as $key => $hashFile) {
                        $path = $hashFile->path();

                        $this->hashCounter = $key;

                        if (str_contains($path, '.zip')) {
                            $this->deCompressHashFile($path);

                            $hashFileToIndex = str_replace('downloads/', '', str_replace('.zip', '', $path));

                            $path = str_replace('.zip', '.txt', $path);
                        } else {
                            $hashFileToIndex = str_replace('downloads/', '', str_replace('.txt', '', $path));
                        }

                        $this->updateProgress($hashFileToIndex);

                        $this->extractAndIndexHash($path, $this->localContent->readStream($path));

                        if (str_contains($hashFile->path(), '.zip')) {//we are deleting .txt file because we change path above.
                            if (isset($this->settings['--remove-indexed']) && (bool) $this->settings['--remove-indexed']) {
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
                        if ($this->localContent->fileExists('downloads/' . $convertedHash . '.txt')) {
                            $this->updateProgress($convertedHash);

                            $this->extractAndIndexHash(
                                'downloads/' . $convertedHash . '.txt',
                                $this->localContent->readStream('downloads/' . $convertedHash . '.txt')
                            );

                            $this->writeToFile('indexed.txt', $convertedHash);
                        } else if ($this->localContent->fileExists('downloads/' . $convertedHash . '.zip')) {
                            $this->deCompressHashFile('downloads/' . $convertedHash . '.zip');

                            if ($this->localContent->fileExists('downloads/' . $convertedHash . '.txt')) {
                                $this->updateProgress($convertedHash);

                                $this->extractAndIndexHash(
                                    'downloads/' . $convertedHash . '.txt',
                                    $this->localContent->readStream('downloads/' . $convertedHash . '.txt')
                                );

                                $this->writeToFile('indexed.txt', $convertedHash);

                                $this->localContent->delete('downloads/' . $convertedHash . '.txt');
                            } else {
                                $this->writeToFile('indexsourcenotfound.txt', $convertedHash);

                                continue;
                            }

                            if (isset($this->settings['--remove-indexed']) && (bool) $this->settings['--remove-indexed']) {
                                $this->compressHashFile('downloads/' . $convertedHash . '.txt');
                            }
                        }

                        $this->localContent->write('resumeindex.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
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

    protected function extractAndIndexHash($hashFilePath, $hashFileContent)
    {
        $hashFile = str_replace('downloads/', '', str_replace('.txt', '', $hashFilePath));

        if (isset($this->settings['--remove-indexed']) && (bool) $this->settings['--remove-indexed']) {
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

                if ($this->settings['--index-count'] !== 0 && (int) $hash[1] <= $this->settings['--index-count']) {//Only index often used passwords
                    continue;
                }

                if (isset($remainingHashes[$hash[0]])) {
                    unset($remainingHashes[$hash[0]]);
                }

                $indexPath = $hashFile . '/' . $hash[0] . '.txt';

                $this->localContent->write('index/' . $indexPath, $hash[1]);
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

    protected function convert(int $counter)
    {
        return strtoupper(substr(bin2hex(pack('N', $counter)), 3));
    }

    protected function newProgress($processType = 'Indexing...')
    {
        $this->hashFilesCount = iterator_count(new FilesystemIterator(__DIR__ . '/../data/downloads/', FilesystemIterator::SKIP_DOTS));

        if ($this->hashFilesCount === 0) {
            \cli\line('%rDownloads directory empty. Nothing to sort.%w');

            exit;
        }

        parent::newProgress($processType);
    }
}