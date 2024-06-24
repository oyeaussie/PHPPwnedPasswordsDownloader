<?php

namespace PHPPwnedPasswordsDownloader;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use cli\progress\Bar;

abstract class Base
{
    public $localContent;

    protected $now;

    protected $hashRangesStart = 0;

    protected $hashRangesEnd = 1024 * 1024;

    protected $resumeFrom = 0;

    protected $zip;

    protected $progress;

    protected $hashCounter;

    protected $settings;

    public function __construct()
    {
        $this->now = date('Y_m_d_H_i_s');

        $this->localContent = new Filesystem(
            new LocalFilesystemAdapter(
                __DIR__ . '/../data/',
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS
            ),
            []
        );

        $this->zip = new \ZipArchive();
    }

    public function getSettings()
    {
        return $this->settings;
    }

    protected function compressHashFile($hashFilePath)
    {
        try {
            if ($this->localContent->fileExists($hashFilePath)) {
                $this->zip->open(__DIR__ . '/../data/' . str_replace('.txt', '.zip', $hashFilePath), $this->zip::CREATE);

                $this->zip->addFile(__DIR__ . '/../data/' . $hashFilePath, str_replace('downloads/', '', $hashFilePath));

                $this->zip->close();

                return true;
            }
        } catch (\throwable | UnableToCheckExistence $e) {
            echo $e->getMessage();

            return false;
        }
    }

    protected function deCompressHashFile($filePath)
    {
        $this->zip->open(__DIR__ . '/../data/' . $filePath);

        if (!$this->zip->extractTo(__DIR__ . '/../data/downloads/')) {
            echo 'Error unzipping file. Please download hash file again.';

            return false;
        }
    }

    protected function newProgress($processType = 'Downloading...')
    {
        $this->progress =
            new Bar($processType,
                    ($this->settings['--resume'] && $this->resumeFrom > 0) ?
                    ($this->hashRangesEnd - $this->resumeFrom) :
                    $this->hashRangesEnd
            );

        $this->progress->display();
    }

    public function updateProgress($message)
    {
        $this->progress->tick(1, $message);
    }

    protected function finishProgress()
    {
        $this->progress->finish();
    }

    protected function writeToFile($file, $hash)
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
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }
    }
}