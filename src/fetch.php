<?php

$rules = [];

function init_db($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS statuses (
               url TEXT NOT NULL,
               host TEXT NOT NULL,
               indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (url, host))");
    $db->exec("CREATE INDEX IF NOT EXISTS
               indexed_idx ON statuses (indexed_at)");
}

function send_request($url, $method="GET", $data=[], $headers=[]) {
    $headers = array_merge(['User-Agent: UnRelay'], $headers);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function host_scheme($host) {
    $hostname = strstr($host, ':', true);
    return ($hostname != 'localhost') ? 'https' : 'http';
}

function read_robots_txt($host, &$rules) {
    if (array_key_exists($host, $rules)) return;
    $rules[$host]['disallow'] = [];

    $url = host_scheme($host) . '://' . $host . '/robots.txt';
    $content = send_request($url);
    if ($content === false) return;

    $match = false;
    $separator = "\r\n";
    $line = strtok($content, $separator);
    while ($line !== false) {
        $line = trim($line);
        if ($delim = strpos($line, '#')) substr($line, 0, $delim);
        $delim = strpos($line, ':');
        if ($delim !== false) {
            $directive = strtolower(substr($line, 0, $delim));
            $value = ltrim(substr($line, $delim + 1));
            if ($directive == 'user-agent') {
                $match = (str_starts_with(strtolower($value), 'unrelay') || $value == '*');
            } else if ($match && !empty($value)) {
                $rules[$host][$directive][] = $value;
            }
        }
        $line = strtok($separator);
    }
}

function path_allowed($host, $path) {
    static $rules = [];
    read_robots_txt($host, $rules);

    $allowed = true;
    foreach ($rules[$host]['disallow'] as $prefix) {
        if (str_starts_with($path, rtrim($prefix, '*'))) {
            $allowed = false;
            break;
        }
    }
    if (!$allowed && array_key_exists('allow', $rules[$host])) {
        foreach ($rules[$host]['allow'] as $prefix) {
            if (str_starts_with($path, rtrim($prefix, '*'))) {
                $allowed = true;
                break;
            }
        }
    }
    return $allowed;
}

function fetch_tags($instance, $tag) {
    $path = "/tags/{$tag}.json";
    $url = host_scheme($instance) . "://{$instance}/tags/{$tag}.json";
    if (path_allowed($instance, $path)) {
        echo "Fetching {$tag} from ${instance}";
        $tags = json_decode(send_request($url));
    } else {
        echo "Fetching {$url} from ${instance} not allowed by robots.txt";
        return false;
    }

    if ($tags === false) {
        echo "Error fetching tags from {$url}";
        return false;
    }
    print_r($tags);
    return $tags;
}
