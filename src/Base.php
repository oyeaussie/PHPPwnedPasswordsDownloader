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

    protected $settings = [];

    protected $hashDir = 'sha/';

    protected $hashEtagsDir = 'shaetags/';

    public function __construct($createRoot = false)
    {
        $this->now = date('Y-m-d-H-i-s');

        $this->localContent = new Filesystem(
            new LocalFilesystemAdapter(
                __DIR__ . '/../data/',
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS,
                null,
                $createRoot
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

                $this->zip->addFile(__DIR__ . '/../data/' . $hashFilePath, str_replace($this->hashDir, '', $hashFilePath));

                $this->zip->close();

                return true;
            }
        } catch (\throwable | UnableToCheckExistence $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            return false;
        }
    }

    protected function deCompressHashFile($filePath)
    {
        $this->zip->open(__DIR__ . '/../data/' . $filePath);

        if (!$this->zip->extractTo(__DIR__ . '/../data/' . $this->hashDir)) {
            \cli\line('%rError unzipping file at path :' . $filePath . '%w');

            exit;
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

            if (isset($this->settings['--type']) &&
                ($file === $this->settings['--type'] . 'checkfile.txt' || $file === $this->settings['--type'] . 'pool.txt')
            ) {
                if ($file === $this->settings['--type'] . 'pool.txt') {
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

            \cli\line('%rCould not convert hex to integer! Please provide correct hash.%w');

            exit;
        }

        if ($counter !== null) {
            return strtoupper(substr(bin2hex(pack('N', $counter)), 3));
        }

        return false;
    }
}