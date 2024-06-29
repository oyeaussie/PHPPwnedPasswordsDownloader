<?php

namespace PHPPwnedPasswordsDownloader;

use Carbon\Carbon;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use PHPPwnedPasswordsDownloader\Base;

class Update extends Base
{
    public function __construct()
    {
        parent::__construct(true);
    }

    public function getUpdates()
    {
        if (!isset($_GET['since'])) {
            return ['response' => 'Incorrect since value set.'];
        }

        $updates = null;

        if (file_exists(__DIR__ . '/../data/updates/updates.php')) {
            include __DIR__ . '/../data/updates/updates.php';

            $updates = ${"pwned_updates"};
        }

        if (!$updates) {
            try {
                $updates = json_decode($this->localContent->read('updates/updates.json'), true);
            } catch (UnableToReadFile | FilesystemException $e) {
                return ['response' => 'No Updates!'];
            }
        }

        if ($updates) {
            try {
                $since = Carbon::parse($_GET['since']);
            } catch (\throwable $e) {
                return ['response' => 'Incorrect since value set.'];
            }

            $sinceTimestamp = $since->startOfday()->getTimestamp();

            $updatesSince = [];

            if (isset($_GET['type'])) {
                $type = $_GET['type'];

                if ($type !== 'sha' && $type !== 'ntlm') {
                    return ['response' => 'Incorrect type set.'];
                }
            }

            foreach ($updates as $day => $dayValue) {
                if ($day >= $sinceTimestamp) {
                    if (isset($type)) {
                        if (isset($dayValue[$type])) {
                            $updatesSince[$day][$type] = $dayValue[$type];
                        }
                    } else {
                        $updatesSince[$day] = $dayValue;
                    }
                }
            }

            return ['response' => $updatesSince];
        }

        return ['response' => 'No Updates!'];
    }
}