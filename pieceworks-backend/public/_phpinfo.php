<?php
header('Content-Type: application/json');
$disabled = ini_get('disable_functions');
$shell = function_exists('shell_exec') ? 'yes' : 'no';
$exec = function_exists('exec') ? 'yes' : 'no';
$proc = function_exists('proc_open') ? 'yes' : 'no';
$test = @shell_exec('echo hello');
echo json_encode([
  'disabled_functions' => $disabled,
  'shell_exec' => $shell,
  'exec' => $exec,
  'proc_open' => $proc,
  'shell_test' => $test,
  'php_version' => PHP_VERSION
]);
?>