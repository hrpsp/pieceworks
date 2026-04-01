<?php
header('Content-Type: text/plain');

$pid = 3793705;

// Read the CWD symlink of the process
$cwd = @readlink("/proc/$pid/cwd");
echo "Process CWD: $cwd\n";

// Read the cmdline  
$cmd = str_replace("\0", " ", file_get_contents("/proc/$pid/cmdline"));
echo "CMD: $cmd\n";

// Based on CWD, find the actual static file path
if ($cwd) {
    $chunkPath = "$cwd/.next/static/chunks/app/(auth)/login/page-04c6fdfc4aaa3071.js";
    echo "\nExpected chunk path: $chunkPath\n";
    if (file_exists($chunkPath)) {
        $content = file_get_contents($chunkPath);
        $hasOld = strpos($content, 'localhost:8000') !== false;
        $hasNew = strpos($content, 'pieceworks.myflexihr.com/api') !== false;
        echo "File exists! size=" . strlen($content) . " hasOld=$hasOld hasNew=$hasNew\n";
    } else {
        echo "File NOT found at expected path\n";
        // List what's actually in that chunks dir
        $loginDir = "$cwd/.next/static/chunks/app/(auth)/login";
        if (is_dir($loginDir)) {
            echo "Files in login dir:\n";
            foreach (scandir($loginDir) as $f) {
                if ($f !== '.' && $f !== '..') {
                    $size = filesize("$loginDir/$f");
                    echo "  $f ($size bytes)\n";
                }
            }
        } else {
            echo "Login dir does not exist: $loginDir\n";
        }
    }
}
?>