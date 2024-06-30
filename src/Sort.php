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

class Sort extends Base
{
    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->settings = array_merge(
            [
                '--sort-order'      => 'SORT_DESC',
                '--resume'          => true
            ],
            $settings
        );

        if (isset($this->settings['--type']) && $this->settings['--type'] === 'ntlm') {
            $this->hashDir = 'ntlm/';
            $this->hashEtagsDir = 'ntlmetags/';
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

                if ($this->localContent->fileExists($path)) {
                    $this->sortHashFile($path);
                } else if ($this->localContent->fileExists(str_replace('.txt', '.zip', $path))) {
                    $this->deCompressHashFile(str_replace('.txt', '.zip', $path));

                    if ($this->localContent->fileExists($path)) {
                        $this->sortHashFile($path);

                        $this->compressHashFile($path);

                        $this->localContent->delete($path);
                    }
                }

                if ($progress) {
                    $this->updateProgress('Sorting hash: ' . strtoupper($this->settings['--hashes']) . ' (1/1)');

                    $this->finishProgress();
                }

                return true;
            } catch (\Exception | UnableToCheckExistence | UnableToDeleteFile | FilesystemException $e) {
                \cli\line('%r' . $e->getMessage() . '%w');

                exit;
            }
        } else {
            try {
                if ($this->localContent->fileExists('resumesort.txt')) {
                    $this->resumeFrom = $this->localContent->read('resumesort.txt');
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

                            $hashFileToSort = str_replace($this->hashDir, '', str_replace('.zip', '', $path));

                            $path = str_replace('.zip', '.txt', $path);
                        } else {
                            $hashFileToSort = str_replace($this->hashDir, '', str_replace('.txt', '', $path));
                        }

                        $this->updateProgress('Sorting hash: ' . $hashFileToSort . ' (' . ($this->hashCounter + 1) . '/' . $this->hashFilesCount . ')');

                        $this->sortHashFile($path);

                        if (str_contains($hashFile->path(), '.zip')) {
                            $this->compressHashFile($path);

                            $this->localContent->delete($path);
                        }
                    }
                } catch (UnableToListContents | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            } else {
                //Run Counter Loop
                for ($hashCounter = ($this->resumeFrom > 0) ? $this->resumeFrom : $this->hashRangesStart;
                     $hashCounter < $this->hashRangesEnd;
                     $hashCounter++
                 ) {
                    $this->hashCounter = $hashCounter;

                    $convertedHash = $this->convert($hashCounter);

                    try {
                        if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.txt')) {
                            $this->updateProgress($convertedHash, null, 'Sorting');

                            $this->sortHashFile($this->hashDir . $convertedHash . '.txt');

                            $this->writeToFile('sorted.txt', $convertedHash);
                        } else if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.zip')) {
                            $this->deCompressHashFile($this->hashDir . $convertedHash . '.zip');

                            if ($this->localContent->fileExists($this->hashDir . $convertedHash . '.txt')) {
                                $this->updateProgress($convertedHash, null, 'Sorting');

                                $this->sortHashFile($this->hashDir . $convertedHash . '.txt');

                                $this->writeToFile('sorted.txt', $convertedHash);

                                $this->compressHashFile($this->hashDir . $convertedHash . '.txt');

                                $this->localContent->delete($this->hashDir . $convertedHash . '.txt');
                            } else {
                                $this->writeToFile('sortsourcenotfound.txt', $convertedHash);

                                continue;
                            }
                        }

                        $this->localContent->write('resumesort.txt', ($this->hashRangesEnd === ($hashCounter + 1)) ? 0 : $hashCounter);
                    } catch (UnableToCheckExistence | UnableToReadFile | UnableToDeleteFile | FilesystemException $e) {
                        \cli\line('%r' . $e->getMessage() . '%w');

                        exit;
                    }
                }
            }

            $this->finishProgress();

            return true;
        }
    }

    protected function sortHashFile($hashFilePath)
    {
        try {
            if ($this->localContent->fileExists($hashFilePath)) {
                $hashFileContent = $this->localContent->read($hashFilePath);

                $hashFileContentArr = explode(PHP_EOL, trim($hashFileContent));

                foreach ($hashFileContentArr as &$hashFileContentLine) {
                    $hashFileContentLineArr = explode(':', trim($hashFileContentLine));

                    $hashFileContentLine = [];
                    $hashFileContentLine['hash'] = $hashFileContentLineArr[0];
                    $hashFileContentLine['counter'] = (int) $hashFileContentLineArr[1];
                }

                $hashFileContentArr = $this->msort($hashFileContentArr, 'counter', SORT_NUMERIC, $this->settings['--sort-order']);

                $this->localContent->delete($hashFilePath);

                foreach ($hashFileContentArr as $hashFileContentLine) {
                    @file_put_contents(__DIR__ . '/../data/' . $hashFilePath, $hashFileContentLine['hash'] . ':' . $hashFileContentLine['counter'] . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }

            return true;
        } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }
    }

    protected function msort($array, $key, $sort_flags = SORT_REGULAR, $order = 'SORT_DESC') {
        if (is_array($array) && count($array) > 0) {
            if (!empty($key)) {
                $mapping = array();

                foreach ($array as $k => $v) {
                    $sort_key = '';

                    if (!is_array($key)) {
                        $sort_key = $v[$key];
                    } else {
                        foreach ($key as $key_key) {
                            $sort_key .= $v[$key_key];
                        }
                    }

                    $mapping[$k] = $sort_key;
                }

                switch ($order) {
                    case 'SORT_ASC':
                        asort($mapping, $sort_flags);
                        break;
                    case 'SORT_DESC':
                        arsort($mapping, $sort_flags);
                        break;
                }

                $sorted = array();

                foreach ($mapping as $k => $v) {
                    $sorted[] = $array[$k];
                }

                return $sorted;
            }
        }
        return $array;
    }

    protected function newProgress($processType = 'Sorting...')
    {
        $this->hashFilesCount = iterator_count(new FilesystemIterator(__DIR__ . '/../data/' . $this->hashDir, FilesystemIterator::SKIP_DOTS));

        if ($this->hashFilesCount === 0) {
            \cli\line('%rDownloads directory empty. Nothing to sort.%w');

            exit;
        }

        parent::newProgress($processType);
    }
}