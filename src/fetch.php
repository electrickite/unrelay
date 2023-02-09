<?php

function parseRobotsTxt($host, $robots) {
    if (array_key_exists($host, $robots)) return;
    $url = 'https://' . $host . '/robots.txt';
    echo "Fetching {$url}...\n";
    $content = file_get_contents($url, false, null, 0, 512000);
    if ($content === false) return;

    $separator = "\r\n";
    $line = strtok($content, $separator);
    while ($line !== false) {
        $line = trim($line);
        if ($delim = strpos($line, '#')) substr($line, 0, $delim);
        $delim = strpos($line, ':');
        if ($delim !== false) {
            $directive = strtolower(substr($line, 0, $delim));
            $value = ltrim(substr($line, $delim + 1));
            echo "Directive: '{$directive}'  --  Value: '{$value}'\n";
        }
        $line = strtok($separator);
    }
}

parseRobotsTxt($argv[1], []);
