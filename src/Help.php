<?php

namespace PHPPwnedPasswordsDownloader;

use cli\Table;
use cli\table\Ascii;

class Help
{
    public function show($method = null)
    {
        \cli\line('%yUsage Example:');
        if ($method && $method !== 'download') {
            if ($method === 'sort') {
                \cli\line("%w./hibp sort");
                \cli\line("%w./hibp sort --sort-order=SORT_ASC");
            } else if ($method === 'cache') {
                \cli\line("%w./hibp cache");
                \cli\line("%w./hibp cache --cache-method=redis --cache-host=192.168.100.1:63679");
            } else if ($method === 'index') {
                \cli\line("%w./hibp index");
                \cli\line("%w./hibp index --remove-indexed=true");
            } else if ($method === 'lookup') {
                \cli\line("%w./hibp lookup");
                \cli\line("%w./hibp lookup --type=hash 00000FE10422B7A5CF93361467E08AA9867A0796");
            }
        } else {
            \cli\line("%w./hibp download --one=00000");
            \cli\line("%w./hibp download --range=00000,00010");
        }
        \cli\line('');
        \cli\line('%yList of arguments:%w');

        $helpTable = new Table();

        $helpTable->setHeaders($this->getHeaders());

        $helpTable->setRows($this->getRows($method));

        $helpTable->setRenderer(new Ascii());

        $helpTable->display();
    }

    protected function getHeaders()
    {
        return ['Arguments', 'Default Value(s)', 'Description'];
    }

    protected function getRows($method = null)
    {
        $help['download'] =
            [
                ['%yDOWNLOAD%w','', ''],
                ['download', '--type=sha,', 'Will start downloading the hash files with default settings'],
                ['', '--get=all,', ''],
                ['', '--force=false,', ''],
                ['', '--async=false,', ''],
                ['', '--resume=true,', ''],
                ['', '--compress=false', ''],
                ['', '--sort=false', ''],
                ['', '--index=false', ''],
                ['download --type=ntlm', 'sha', 'Will start downloading the hash files (NTLM) synchronously'],
                ['download --get={option}', 'all', 'Specify what to get. Options: all (default), one, multiple, range, hashfile, intfile'],
                ['download --hashes={hashes}', '-', 'Works when get argument is set'],
                ['', '', '%bExample: ./hibp download --get=one --hashes=00000%w'],
                ['', '', '%bExample: ./hibp download --get=multiple --hashes=00000,00001%w'],
                ['', '', '%bExample: ./hibp download --get=range --hashes=00000,00010%w'],
                ['', '', '%bExample: ./hibp download --get=hashfile --hashes=anotherhashfile.txt (default is hashfile.txt in data/ directory)%w'],
                ['', '', '%bExample: ./hibp download --get=intfile --hashes=anotherintfile.txt (default is intfile.txt in data/ directory)%w'],
                ['download --force=true', 'false', 'Use force argument to continue on download error and the offending hash will be logged in error.txt file'],
                ['download --check=true', '-', 'Check for missing hashes'],
                ['download --check-download=true', '-', 'Check for missing hashes and download them'],
                ['download --async={concurrent_number}', 'false', 'An integer of concurrent connections to initiate, will start downloading the hash files (SHA) Asynchronously'],
                ['', '', 'Can be used with any arguments that involve downloading data'],
                ['','','%bExample: ./hibp download --async=100 OR download --check-download --async=100%w'],
                ['download --resume=false', '-', 'Ignore resume counter and start from first hash'],
                ['', '-', 'Note: Resume only works when you are downloading all hashes and hashes via --check-download=true'],
                ['download --compress=true', 'false', 'Download hash files and zip them on the fly'],
                ['download --sort=true', 'false', 'Download hash files and sort the contents. If set to true, you can pass sort command arguments'],
                ['download --cache=true', 'false', 'Download hash files and cache them. If set to true, you can pass cache command arguments'],
                ['download --index=true', 'false', 'Download hash files and index them. If set to true, you can pass index command arguments'],
                ['', '', 'Can be used with any arguments that involve downloading data'],
                ['','','%bExample: ./hibp download --async=100 --type=ntlm compress OR download check download --async=100 --compress=true%w'],
                ['download --max_execution_time={number}','18000', 'PHP ini setting for max_execution_time. Default is 5 hours'],
                ['','', '']
            ];

        $help['sort'] =
            [
                ['%ySORT%w','',''],
                ['sort', '--sort-order=SORT_DESC', 'Start sorting of all hashes with default settings'],
                ['sort --sort-order', 'SORT_DESC', 'Sort downloaded hash file\'s content. Options are SORT_ASC, SORT_DESC'],
                ['','', '']
            ];

        $help['cache'] =
            [
                ['%yCACHE%w','',''],
                ['cache', '--cache-prefix=pwned-', 'Start caching of all hashes to redis cache with default settings'],
                ['','--remove-cached=false', ''],
                ['','--cache-count=500', ''],
                ['cache --cache-host', '-', 'Hostname of redis server. If not defined, default hostname is localhost and default port 6379 is used'],
                ['cache --remove-cached', 'false', 'Remove cached entries from the hash file'],
                ['cache --cache-prefix', 'pwned-', 'Prefix cached entries key with defined prefix'],
                ['cache --cache-count', '500', 'An integer to cache downloaded hashes that are greater equal to of the provided number'],
                ['','','Default is set to 500. Set to 0 to cache every hash'],
                ['','', '%rWarning: Setting cache to 0 will generate millions of entries in your caching system, which required a lot of memory%w'],
                ['','', '%rCheck your system for available memory%w'],
                ['','', '']
            ];

        $help['index'] =
            [
                ['%yINDEX%w','', ''],
                ['index', '--index-count=100', 'Start indexing of all hashes with default settings'],
                ['','--remove-indexed=false', ''],
                ['index --remove-indexed', 'false', 'Remove indexed entries from the hash file'],
                ['index --index-count', '100', 'An integer to index downloaded hashes that are greater equal to of the provided number'],
                ['', '', 'Default is set to 100. Set to 0 to index every hash'],
                ['','', '%rWarning: Setting index to 0 will generate millions of files on your system, one file for each hash%w'],
                ['','', '%rCheck your OS capability to handle that many files%w'],
                ['','', '']
            ];

        $help['lookup'] =
            [
                ['%yLOOKUP%w','', ''],
                ['lookup', '--type=password,', 'Lookup password in the downloaded hash files using default settings'],
                ['','--ntlm=false', ''],
                ['','--search-method=stream', 'Options for search method: array, cache, index, stream, string'],
                ['lookup --cache-host', '-', 'Hostname of caching engine. If not defined, default hostname is localhost and default port is caching engine\'s default port (if any)'],
                ['lookup --ntlm=true', '', 'Lookup password/hash in the downloaded hash files using NTLM'],
                ['lookup --type=hash', 'password', 'Lookup hash instead of password'],
                ['', '', '%rNOTE: Password is not recorded anywhere%w'],
                ['', '', '%rTerminal echo is masked to avoid shoulder surfers to see the entered password%w'],
            ];


        if ($method && isset($help[strtolower($method)])) {
            return $help[strtolower($method)];
        }

        return array_merge($help['download'], $help['sort'], $help['cache'], $help['index'], $help['lookup']);
    }
}