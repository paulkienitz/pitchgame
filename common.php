<?php
// This page contains web functions used across pages.

function myIP()
{
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
}

function enc(?string $str)
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
}

function despace(string $str)
{
    return trim(preg_replace('/\s+/', ' ', $str));
}

function englishNumber(int $n)
{
    $words = explode(' ', 'zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty');
    return array_key_exists($n, $words) ? $words[$n] : (string) $n;
}

function englishCount(int $n)   // but wait, England didn't have counts, it had earls
{
    if ($n == 1)
        return 'once';
    else if ($n == 2)
        return 'twice';
    else
        return englishNumber($n) . ' times';
}

function connectToSession(PitchGameConnection &$con): bool
{
    if (!isset($_COOKIE['pitchgame']) || !$con->getSessionByToken($_COOKIE['pitchgame'], myiP(), $_SERVER['HTTP_USER_AGENT']))
    {
        $token = $con->makeSession(myIP(), $_SERVER['HTTP_USER_AGENT']);
        if ($token)
        {
            setcookie('pitchgame', $token, time() + 366*86400);  // a year
            setcookie('fresh', '1', time() + 30*86400);
        }
        else
            return false;
    }
    else if (!isset($_COOKIE['fresh']))     // refresh the expiration date
    {
        setcookie('pitchgame', $_COOKIE['pitchgame'], time() + 366*86400);
        setcookie('fresh', '1', time() + 30*86400);
    }
    return true;
}

function describeBrowser(string $userAgent)
{
    // get_browser is not available on my PHP host, so let's recognize the main browsers only
    if (preg_match('/MSIE \d+/', $userAgent))
        $browser = 'IE (old)';
    else if (preg_match('/Trident\/7/', $userAgent))
        $browser = 'IE 11 or early Edge';
    else if (preg_match('/ Firefox\/\S+$/', $userAgent))
        $browser = 'Firefox';
    else if (preg_match('/ Chrome\/.* Safari/', $userAgent))
        $browser = 'Chrome';
    else if (preg_match('/ Safari\/\S$/', $userAgent))
        $browser = 'Safari';
    else if (preg_match('/ Edg\w*\/\S+$/', $userAgent))
        $browser = 'Edge';
    else
        $browser = 'unrecognized';
    if ($browser != 'unrecognized' && preg_match('/Android|Kindle|iPad|iPod|iPhone|Mobile|Tablet/', $userAgent))
        $browser .= ' Mobile';
    return '<span class=browse title="' . enc($userAgent) . '">' . $browser . '</span>';
}

function doPost(string $url, array $args)
{
    $curly = curl_init($url);
    curl_setopt($curly, CURLOPT_POST, true);
    curl_setopt($curly, CURLOPT_POSTFIELDS, /*http_build_query*/($args));
    curl_setopt($curly, CURLOPT_RETURNTRANSFER, true);
    return curl_exec($curly);
}

?>
