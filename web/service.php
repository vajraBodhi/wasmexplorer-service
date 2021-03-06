<?php

/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/. */

// Ignore HTTP OPTIONS request -- it's probably CORS.
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  exit;
}

include 'config.php';

// External scripts paths.
$scripts_path = '../scripts/';
$c_compiler_path = $scripts_path . 'compile.sh';
$c_x86_compiler_path = $scripts_path . 'compile-x86.sh';
$translate_script_path = $scripts_path . 'translate.sh';
$get_wasm_jit_script_path = $scripts_path . 'get_wasm_jit.js';
$run_wasm_script_path = $scripts_path . 'run.js';
$clean_wast_script_path = $scripts_path . 'clean_wast.js';
$timeout_command = 'timeout 10s time';

// Cleaning shell output from sensitive information.
$sanitize_shell_output = function ($s)
  use ($upload_folder_path, $jsshell_path, $llvm_root,
       $binaryen_root, $other_sensitive_paths) {
  $sensitive_strings = array_merge(array(
    $upload_folder_path, $jsshell_path, $llvm_root, $binaryen_root, getcwd()),
    $other_sensitive_paths);
  $out = $s;
  foreach ($sensitive_strings as $i) {
    $out = str_replace($i, "...", $out);
  }
  return $out;
};

$input = $_POST["input"];
$action = $_POST["action"];
$options = isset($_POST["options"]) ? $_POST["options"] : '';

// The temp file name is calculated based on MD5 of the input values.
$input_md5 = md5($input . $options . $action);
$result_file_base = $upload_folder_path . $input_md5;

$cleanup = function () use ($result_file_base) {
  foreach(glob($result_file_base . '*') as $f) {
    unlink($f);
  }
};

if ((strpos($action, "cpp2") === 0) or (strpos($action, "c2") === 0)) {
  // The $action has the format (c|cpp)2(wast|x86|run).
  $fileExt = '.cpp';
  if (strpos($action, "c2") === 0) {
    $fileExt = '.c';
  }
  $fileName = $result_file_base . $fileExt;
  file_put_contents($fileName, $input);

  $available_options = array(
    '-O0', '-O1', '-O2', '-O3', '-O4', '-Os', '-fno-exceptions', '-fno-rtti',
    '-ffast-math', '-fno-inline', '-std=c99', '-std=c89', '-std=c++14',
    '-std=c++1z', '-std=c++11', '-std=c++98');
  $safe_options = '-fno-verbose-asm';
  foreach ($available_options as $o) {
    if (strpos($options, $o) !== false) {
      $safe_options .= ' ' . $o;
    } else if ((strpos($o, '-std=') !== false) and
               (strpos(strtolower($options), $o) !== false)) {
      $safe_options .= ' ' . $o;
    }
  }

  if (strpos($action, "2x86")) {
    $x86FileName = $result_file_base . '.x86';
    $output = shell_exec($c_x86_compiler_path . ' ' .
                         $fileName . ' "' . $safe_options . '"' . ' 2>&1');
    if (!file_exists($x86FileName)) {
      echo $sanitize_shell_output($output);
    } else {
      echo $sanitize_shell_output(file_get_contents($x86FileName));
    }
    $cleanup();
    exit;
  }

  // Compiling C/C++ code to get WAST.
  $output = shell_exec($c_compiler_path . ' ' .
                       $fileName . ' "' . $safe_options . '"' . ' 2>&1');
  $wastFileName = $result_file_base . '.wast';
  if (!file_exists($wastFileName)) {
    echo $sanitize_shell_output($output);
    $cleanup();
    exit;
  }

  if (strpos($action, "2wast")) {
    if (strpos($options, "--clean") !== false) {
      echo $sanitize_shell_output(
        shell_exec($jsshell_path . ' ' .
                   $clean_wast_script_path . ' ' . $wastFileName));
    } else {
      echo file_get_contents($wastFileName);
    }
  } else if (strpos($action, "2run")) {
    echo $sanitize_shell_output(
      shell_exec($timeout_command . ' ' . $jsshell_path . ' ' .
                 $run_wasm_script_path. ' ' . $wastFileName . ' 2>&1'));
  }
  $cleanup();
  exit;
}

if ($action == "wast2assembly") {
  $fileName = $result_file_base . '.wast';
  $jit_options = '';
  if (strpos($options, '--wasm-always-baseline') !== false) {
    $jit_options = ' --wasm-always-baseline';
  }
  file_put_contents($fileName, $input);
  $output = shell_exec($jsshell_path . $jit_options . ' ' .
                       $get_wasm_jit_script_path . ' ' . $fileName);
  echo $sanitize_shell_output($output);
  $cleanup();
  exit;
}

if ($action == "wast2wasm") {
  $fileName = $result_file_base . '.wast';
  file_put_contents($fileName, $input);
  $output = shell_exec($translate_script_path . ' ' . $fileName . ' 2>&1');
  $fileName = $result_file_base . '.wasm';
  if (!file_exists($fileName)) {
    echo $sanitize_shell_output($output);
    $cleanup();
    exit;
  }
  echo "-----WASM binary data\n";
  $wasm = file_get_contents($fileName);
  echo base64_encode($wasm);
  $cleanup();
  exit;
}

$cleanup();
?>
