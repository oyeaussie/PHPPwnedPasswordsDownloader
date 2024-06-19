<h2>Description:</h2>
A tool to download pwned password hash files from their API written in PHP. (https://haveibeenpwned.com/Passwords)

<h2>Features:</h2>

Here're some of the project's best features:
*   PHP Cli tool, run from CLI using php command.
*   Download all hash files.
*   Download concurrent files at once using Guzzle Pool.
*   Download one hash file.
*   Download range of hash files.
*   Download multiple hash files (comma separated).
*   Download multiple hash files from a given file (comma separated). File content can be list of hash files or integers.
*   Download only updated hash using eTag.
*   Resume Downloads if previous download gets stuck.
*   Check for missing hash files and download missing hash files (with or without concurrency).
*   Download NTLM hash file (default is SHA-1)
*   Compress (zip) downloaded files on fly.
*   Progress Bar to show the current progress of running process.
*   Logging information of downloaded, skipped (eTag same) & errors on every run.

<h2>Installation Steps:</h2>

```
$ git clone https://github.com/oyeaussie/PHPPwnedPasswordsDownloader.git
$ cd PHPPwnedPasswordsDownloader
$ composer install
```

<h2>Examples:</h2>

* Download all hashes Synchronously

```
$ php index.php
```
* Download all hashes Asynchronously (concurrent connections)

```
$ php index.php 100
```
Adding value 100 triggers Guzzle Pool and sent the concurrency argument to 100. This will initiate 100 concurrent connections to the API.

* Download one hash

```
$ php index.php one 00000
Downloading new hash: 0 | Skipped (same eTag) : 1 (1/1)  100% [===================================] 0:00 / 0:00
```
* Download range of hashes (start and end)

```
$ php index.php range 00000 00004
Downloading new hash: 5 | Skipped (same eTag) : 0 (5/5)  100% [================================] 0:01 / 0:01
```
* Download multiple hashes (comma separated)

```
$ php index.php multiple 00000,0000F
Downloading new hash: 0 | Skipped (same eTag) : 2 (2/2)  100% [================================] 0:01 / 0:01
```
* Download using hashfile (comma separated)

```
$ cat data/hashfile.txt 
00000,0000F
$ rm data/downloads/00000.txt
$ php index.php hashfile
Downloading new hash: 1 | Skipped (same eTag) : 1 (2/2)  100% [================================] 0:01 / 0:01
```
* Download using intfile (integers comma separated)

```
$ cat data/intfile.txt 
0,1,2,3,4
$ php index.php intfile
Downloading new hash: 5 | Skipped (same eTag) : 0 (5/5)  100% [================================] 0:01 / 0:01
$ ls data/downloads/
00000.txt  00001.txt  00002.txt  00003.txt  00004.txt
```
Note: I am not sure how useful one, multiple, range, hashfile, intfile features are. If the developer of PwnedPasswords decides in the future to implement a feature where they send an email with updated hashes for end users to update, the end user can just dump those hashes in the hashfile.txt and issue one command to update them. I've already implemented the feature, maybe it can be useful to someone in some other case.

* Download NTLM hash file instead of SHA-1 hash file

```
$ php index.php 25 ntlm compress
```
NOTE: ntlm argument is 2nd last argument before compress if compress argument is also passed. Otherwise ntlm argument should be the last one. The above command will download 25 concurrent NTLM hash files and compress them.

* Compress the downloaded files

```
$ ls data/downloads/
00000.txt  0000F.txt
$ php index.php multiple 00000,0000F compress
Downloading new hash: 0 | Skipped (same eTag) : 2 (2/2)  100% [================================] 0:00 / 0:00
$ ls data/downloads/
00000.zip  0000F.zip
$ cat data/intfile.txt
0,1,2,3,4
$ php index.php intfile
Downloading new hash: 4 | Skipped (same eTag) : 1 (5/5)  100% [================================] 0:00 / 0:00
$ ls data/downloads/
00000.zip  00001.txt  00002.txt  00003.txt  00004.txt  0000F.zip
$ php index.php intfile compress
Downloading new hash: 0 | Skipped (same eTag) : 5 (5/5)  100% [================================] 0:00 / 0:00
$ ls data/downloads/
00000.zip  00001.zip  00002.zip  00003.zip  00004.zip  0000F.zip
```
Note: Compress argument works with every command. After you pass all the arguments, add compress argument in the end to compress the downloaded file.

* Check for missing hash files and download missing hash files (with or without concurrency). 

```
$ ls data/downloads
No Files...
$ php index.php check
Checking hash 00063... (100/100)  100% [================================] 0:00 / 0:00
Check found 100 hashes missing! Use the argument "check download" to check & download the missing hashes.
$ php index.php check download
Checking hash 00063... (100/100)  100% [================================] 0:00 / 0:00
Check found 100 hashes missing! Downloading missing hashes...
Downloading new hash: 100 | Skipped (same eTag) : 0 (100/100)  100% [================================] 0:03 / 0:04
$ ls data/downloads
00000.txt  00006.txt  0000C.txt  00012.txt  00018.txt  0001E.txt  00024.txt  0002A.txt  00030.txt  00036.txt  0003C.txt  00042.txt  00048.txt  0004E.txt  00054.txt  0005A.txt  00060.txt
00001.txt  00007.txt  0000D.txt  00013.txt  00019.txt  0001F.txt  00025.txt  0002B.txt  00031.txt  00037.txt  0003D.txt  00043.txt  00049.txt  0004F.txt  00055.txt  0005B.txt  00061.txt
00002.txt  00008.txt  0000E.txt  00014.txt  0001A.txt  00020.txt  00026.txt  0002C.txt  00032.txt  00038.txt  0003E.txt  00044.txt  0004A.txt  00050.txt  00056.txt  0005C.txt  00062.txt
00003.txt  00009.txt  0000F.txt  00015.txt  0001B.txt  00021.txt  00027.txt  0002D.txt  00033.txt  00039.txt  0003F.txt  00045.txt  0004B.txt  00051.txt  00057.txt  0005D.txt  00063.txt
00004.txt  0000A.txt  00010.txt  00016.txt  0001C.txt  00022.txt  00028.txt  0002E.txt  00034.txt  0003A.txt  00040.txt  00046.txt  0004C.txt  00052.txt  00058.txt  0005E.txt
00005.txt  0000B.txt  00011.txt  00017.txt  0001D.txt  00023.txt  00029.txt  0002F.txt  00035.txt  0003B.txt  00041.txt  00047.txt  0004D.txt  00053.txt  00059.txt  0005F.txt
$ ls data/downloads | wc -l
100

Now we delete some hash and check again

$ rm data/downloads/0002*
$ ls data/downloads | wc -l
84
$ ls data/downloads
00000.txt  00005.txt  0000A.txt  0000F.txt  00014.txt  00019.txt  0001E.txt  00033.txt  00038.txt  0003D.txt  00042.txt  00047.txt  0004C.txt  00051.txt  00056.txt  0005B.txt  00060.txt
00001.txt  00006.txt  0000B.txt  00010.txt  00015.txt  0001A.txt  0001F.txt  00034.txt  00039.txt  0003E.txt  00043.txt  00048.txt  0004D.txt  00052.txt  00057.txt  0005C.txt  00061.txt
00002.txt  00007.txt  0000C.txt  00011.txt  00016.txt  0001B.txt  00030.txt  00035.txt  0003A.txt  0003F.txt  00044.txt  00049.txt  0004E.txt  00053.txt  00058.txt  0005D.txt  00062.txt
00003.txt  00008.txt  0000D.txt  00012.txt  00017.txt  0001C.txt  00031.txt  00036.txt  0003B.txt  00040.txt  00045.txt  0004A.txt  0004F.txt  00054.txt  00059.txt  0005E.txt  00063.txt
00004.txt  00009.txt  0000E.txt  00013.txt  00018.txt  0001D.txt  00032.txt  00037.txt  0003C.txt  00041.txt  00046.txt  0004B.txt  00050.txt  00055.txt  0005A.txt  0005F.txt

$ php index.php check
Checking hash 00063... (100/100)  100% [================================] 0:00 / 0:00
Check found 16 hashes missing! Use the argument "check download" to check & download the missing hashes.

$ php index.php check download
Checking hash 00063... (100/100)  100% [================================] 0:00 / 0:00
Check found 16 hashes missing! Downloading missing hashes...
Downloading new hash: 16 | Skipped (same eTag) : 0 (16/16)  100% [================================] 0:00 / 0:00

$ ls data/downloads | wc -l
100

$ ls data/downloads
00000.txt  00006.txt  0000C.txt  00012.txt  00018.txt  0001E.txt  00024.txt  0002A.txt  00030.txt  00036.txt  0003C.txt  00042.txt  00048.txt  0004E.txt  00054.txt  0005A.txt  00060.txt
00001.txt  00007.txt  0000D.txt  00013.txt  00019.txt  0001F.txt  00025.txt  0002B.txt  00031.txt  00037.txt  0003D.txt  00043.txt  00049.txt  0004F.txt  00055.txt  0005B.txt  00061.txt
00002.txt  00008.txt  0000E.txt  00014.txt  0001A.txt  00020.txt  00026.txt  0002C.txt  00032.txt  00038.txt  0003E.txt  00044.txt  0004A.txt  00050.txt  00056.txt  0005C.txt  00062.txt
00003.txt  00009.txt  0000F.txt  00015.txt  0001B.txt  00021.txt  00027.txt  0002D.txt  00033.txt  00039.txt  0003F.txt  00045.txt  0004B.txt  00051.txt  00057.txt  0005D.txt  00063.txt
00004.txt  0000A.txt  00010.txt  00016.txt  0001C.txt  00022.txt  00028.txt  0002E.txt  00034.txt  0003A.txt  00040.txt  00046.txt  0004C.txt  00052.txt  00058.txt  0005E.txt
00005.txt  0000B.txt  00011.txt  00017.txt  0001D.txt  00023.txt  00029.txt  0002F.txt  00035.txt  0003B.txt  00041.txt  00047.txt  0004D.txt  00053.txt  00059.txt  0005F.txt

You can also pass number of concurrent downloads. To demonstrate, I have changed the $hashRangesEnd to 2000 so, we can see them download.

$ ls data/downloads
ls: cannot access 'data/downloads': No such file or directory

$ php index.php check
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found 2000 hashes missing! Use the argument "check download" to check & download the missing hashes.

$ php index.php check download
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found 2000 hashes missing! Downloading missing hashes...
Downloading new hash: 2000 | Skipped (same eTag) : 0 (2000/2000)  100% [================================] 1:02 / 1:01

$ php index.php check download
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found no missing hashes!

$ rm data/downloads/*

$ php index.php check
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found 2000 hashes missing! Use the argument "check download" to check & download the missing hashes.

$ php index.php check download 100
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found 2000 hashes missing! Downloading missing hashes...
Adding hash to pool 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Added 2000 hashes to pool! Downloading pool hashes...
Downloading new hash: 2000 | Skipped (same eTag) : 0 (2000/2000)  100% [================================] 0:16 / 0:19

$ php index.php check download 100
Checking hash 007CF... (2000/2000)  100% [================================] 0:00 / 0:00
Check found no missing hashes!
```
In the above example when we add 100 concurrent request, we download 2000 hash files in 16~19 seconds. Compared to when we did not pass the concurrent request amount, we downloaded 2000 hash files in ~62 seconds.

Note: To demonstrate the above feature, I have changed the $hashRangesEnd from 1024 * 1024 to 100, so we only check and download 100 hash files and to demonstrate concurrent downloads, I changed the $hashRangesEnd to 2000. The original value will be set to 1024 * 1024.

* Logging information of downloaded, skipped (eTag same) & errors on every run

```
$ php index.php
Downloading new hash: 10 | Skipped (same eTag) : 0 (10/10)  100% [================================] 0:00 / 0:00

$ ls data/logs/2024_06_18_18_03_11/
new.txt

$ cat data/logs/2024_06_18_18_03_11/new.txt 
00000,00001,00002,00003,00004,00005,00006,00007,00008,00009,

$ php index.php
Downloading new hash: 0 | Skipped (same eTag) : 10 (10/10)  100% [================================] 0:01 / 0:02

$ ls data/logs/
2024_06_18_18_03_11  2024_06_18_18_03_32

$ ls data/logs/2024_06_18_18_03_32/
nochange.txt

$ cat data/logs/2024_06_18_18_03_32/nochange.txt 
00000,00001,00002,00003,00004,00005,00006,00007,00008,00009,
```
Note: There is one more log file that is generated when we get any errors from guzzle (error.txt). An exception will be thrown if there are any errors, but if you want the download to continue and log the hash to the error.txt file, use "force" argument. To demonstrate the above feature, I have changed the $hashRangesEnd from 1024 * 1024 to 10, so we only check and download 10 hash files. The original value will be set to 1024 * 1024.

* Resume downloads capability

```
$ rm data/downloads/*
$ ls data/downloads/ | wc -l
0

$ php index.php
Downloading new hash: 25 | Skipped (same eTag) : 0 (25/100)  25 % [===========================>                             ] 0:01 / 0:06^C

$ ls data/downloads/ | wc -l
28

$ php index.php
Downloading new hash: 35 | Skipped (same eTag) : 1 (63/100)  36 % [=======================================>                 ] 0:01 / 0:04^C

$ ls data/downloads/ | wc -l
66

$ php index.php
Downloading new hash: 34 | Skipped (same eTag) : 1 (100/100)  100% [========================================================] 0:01 / 0:26

$ ls data/downloads/ | wc -l
100

$ php index.php check
Checking hash 00063... (100/100)  100% [====================================================================================] 0:00 / 0:00
Check found no missing hashes!
```
The above example shows that we resume download from where the connection broke. The final result should be 100 has files downloaded, which we can confirm by check argument and ls data/downloads/ | wc -l command. To demonstrate the above feature, I have changed the $hashRangesEnd from 1024 * 1024 to 100, so we only download 100 hash files. The original value will be set to 1024 * 1024.

<h2>Credits:</h2>
Thanks to the following projects for their great work. Without them, this project would not be possible.<br>
Composer<br>
Guzzle - https://github.com/guzzle/guzzle<br>
Flysystem - https://github.com/thephpleague/flysystem<br>
PHP Cli Tools - https://github.com/wp-cli/php-cli-tools<br>
PwnedPasswordsDownloader - https://github.com/HaveIBeenPwned/PwnedPasswordsDownloader (main source of inspiration)

<h2>Docker:</h2>

You can run the application in docker as well with the following command:
```
docker run -it --volume=/tmp/hibp:/var/www/html/PHPPwnedPasswordsDownloader/data/ oyeaussie/phppwnedpasswordsdownloader
```
Note: The above command will map your systems `/tmp/hibp` folder to `/var/www/html/PHPPwnedPasswordsDownloader/data/` folder. So, when you will run the `php index.php` command, it will download all hash files to your /tmp/hibp directory. Change the folder names of the volume to whatever you want.<br>
```
$ cd /var/www/html/PHPPwnedPasswordsDownloader/
$ git pull
```
Note: Run git pull to update the code to latest version.<br>
<br>
I have also added the DockerFile in the docker folder, so you can build the image the way you want to.

<h2>Issues/Discussions/New features:</h2>
Feel free to open an issue in case of a bug or to discuss anything related to the tool or to add a new feature.

<h2>Buy Me A Coffee/Beer:</h2>
Time is valuable. If you feel this project has been helpful and it has saved your time worth a coffee or a beer...<br><br>
<a href="https://www.buymeacoffee.com/oyeaussie" target="_blank"><img src="https://github.com/oyeaussie/assets/blob/main/buymecoffee.jpg" alt="Buy Me A Coffee"></a>
<a href="https://github.com/sponsors/oyeaussie?frequency=one-time&sponsor=oyeaussie&amount=10" target="_blank"><img src="https://github.com/oyeaussie/assets/blob/main/buymebeer.jpg" alt="Buy Me A Beer"></a>

<h2>Hire me:</h2>
If you would like to develop a PHP application that requires expert level programming. I am available for hire. Message me and we can discuss further.
