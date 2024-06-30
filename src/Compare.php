<?php

namespace PHPPwnedPasswordsDownloader;

use Carbon\Carbon;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPPwnedPasswordsDownloader\Base;

class Compare extends Base
{
    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this->settings = array_merge(
            [
                '--rebuild-index' => false
            ],
            $settings
        );
    }

    public function run()
    {
        $todayObj = Carbon::parse(time())->startOfDay();

        $today = $todayObj->toDateString();

        $todayTimestamp = $todayObj->getTimestamp();

        try {
            $logs = $this->localContent->listContents('logs/');
        } catch (UnableToListContents | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }

        $updates = [];

        $sha = false;
        $ntlm = false;

        $updateDate = $today;
        $updateDateTimestamp = $todayObj->getTimestamp();

        foreach ($logs as $log) {
            if (str_starts_with($log->path(), 'logs/' . $today) || (bool) $this->settings['--rebuild-index']) {
                $date = str_replace('logs/', '', $log->path());

                $date = explode('-', $date);

                if (count($date) < 3) {
                    continue;
                }

                $date = $date[0] . '-' . $date[1] . '-' . $date[2];

                try {
                    $dateObj = Carbon::parse($date)->startOfDay();
                } catch (\throwable $e) {
                    continue;
                }

                $updateDate = $date;
                $updateDateTimestamp = $dateObj->getTimestamp();

                if ($date === $today) {
                    $updateDate = $today;
                    $updateDateTimestamp = $todayTimestamp;
                }

                try {
                    if ($this->localContent->fileExists($log->path() . '/shanew.txt')) {
                        $newfile = $this->localContent->read($log->path() . '/shanew.txt');

                        $newfile = explode(',', trim(trim($newfile), ','));

                        if (count($newfile) > 0) {
                            $updates[$updateDateTimestamp]['sha'] = $this->getRanges($this->convertToInt($newfile));
                        }

                        $sha = true;
                    } else if ($this->localContent->fileExists($log->path() . '/ntlmnew.txt')) {
                        $newfile = $this->localContent->read($log->path() . '/ntlmnew.txt');

                        $newfile = explode(',', trim(trim($newfile), ','));

                        if (count($newfile) > 0) {
                            $updates[$updateDateTimestamp]['ntlm'] = $this->getRanges($this->convertToInt($newfile));
                        }

                        $ntlm = true;
                    }
                } catch (UnableToCheckExistence | UnableToReadFile | FilesystemException $e) {
                    \cli\line('%r' . $e->getMessage() . '%w');

                    exit;
                }
            }

            if ((bool) $this->settings['--rebuild-index'] || (isset($date) && $date === $today) || !isset($date)) {
                if (isset($updates[$updateDateTimestamp])) {
                    try {
                        $this->localContent->write('updates/' . $updateDate . '.json', json_encode($updates[$updateDateTimestamp]));
                    } catch (UnableToWriteFile | FilesystemException $e) {
                        \cli\line('%r' . $e->getMessage() . '%w');

                        exit;
                    }
                }
            }

            if (!(bool) $this->settings['--rebuild-index'] && $sha && $ntlm) {
                break;
            }
        }

        if (isset($updates[$updateDateTimestamp]) && count($updates[$updateDateTimestamp]) > 0) {
            $this->generateUpdateIndex($updates);

            \cli\line('%gNew updates available.%w');

            return true;
        } else {
            \cli\line('%rNothing to compare.%w');

            return false;
        }
    }

    protected function generateUpdateIndex($updates)
    {
        try {
            if ($this->localContent->fileExists('updates/' . 'updates.json')) {
                $update = json_decode($this->localContent->read('updates/' . 'updates.json'), true);

                $update = array_replace($update, $updates);

                $this->localContent->write('updates/updates.json', json_encode($update));

                $updates = $update;
            } else {
                $this->localContent->write('updates/updates.json', json_encode($updates));
            }

            ksort($updates);

            $this->setCache($updates);
        } catch (UnableToCheckExistence | UnableToReadFile | UnableToWriteFile | FilesystemException $e) {
            \cli\line('%r' . $e->getMessage() . '%w');

            exit;
        }
    }

    public function setCache($value)
    {
        $value = var_export($value, true);

        $value = str_replace('stdClass::__set_state', '(object)', $value);

        $this->localContent->write('updates/updates.php', '<?php $pwned_updates = ' . $value . ';');
    }

    protected function getRanges(array $numbers)
    {
        $ranges = [];

        $len = count($numbers);

        for ($i = 0; $i < $len; $i++) {
            $rangeStart = $rangeEnd = $numbers[$i];

            while (isset($numbers[$i+1]) && $numbers[$i+1] - $numbers[$i] == 1) {
                $i++;

                $rangeEnd = $numbers[$i];
            }

            $ranges[] = $rangeStart == $rangeEnd ? "$rangeStart" : "$rangeStart-$rangeEnd";
        }

        return $ranges;
    }

    protected function convertToInt(array $hashes)
    {
        $integers = [];

        foreach ($hashes as $hash) {
            array_push($integers, $this->convert(null, $hash));
        }

        sort($integers);

        return $integers;
    }
}