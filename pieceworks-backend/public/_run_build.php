<?php
$logFile = '/home/hrpsp/pieceworks.myflexihr.com/pieceworks-backend/public/build_log.txt';
$cmd = 'cd /home/hrpsp/pieceworks.myflexihr.com/pieceworks-frontend'
     . ' && export HOME=/home/hrpsp PATH=$PATH:/usr/local/bin:/usr/local/nodejs/bin:/usr/bin'
     . ' && npm run build > ' . $logFile . ' 2>&1'
     . ' && cp -r .next/static .next/standalone/.next/static >> ' . $logFile . ' 2>&1'
     . ' && pm2 restart pieceworks-frontend >> ' . $logFile . ' 2>&1'
     . ' && echo DONE >> ' . $logFile;
file_put_contents($logFile, "STARTED: " . date("H:i:s") . "\n");
shell_exec("nohup bash -c " . escapeshellarg($cmd) . " > /dev/null 2>&1 &");
echo json_encode(["ok" => true]);
?>