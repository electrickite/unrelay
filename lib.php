<?php

function is_secure() {
	$https = $_SERVER['HTTPS']
	    ?? $_SERVER['REQUEST_SCHEME']
	    ?? $_SERVER['HTTP_X_FORWARDED_PROTO']
	    ?? null;

	return $https && (
        strcasecmp('on', $https) == 0
        || strcasecmp('https', $https) == 0
    );
}

function requires_method($method) {
    if (strtoupper($_SERVER['REQUEST_METHOD']) != $method) {
        send_error('method not allowed', 405);
    }
}

function uuid4($data=null) {
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function sha256_digest($str) {
    $hash = hash('sha256', $str, true);
    return base64_encode($hash);
}

function sign($data) {
    global $pkey;
    $signature = null;
    if (!openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
        // Error
    }
    return base64_encode($signature);
}

function build_activity() {
	$data = json_decode(file_get_contents('php://input'), true);
	$data = is_array($data) ? array_change_key_case($data, CASE_LOWER) : [];
	if (isset($data['type'])
		&& isset($data['actor'])
		&& isset($data['id'])
		&& isset($data['object'])
	) {
		return $data;
	} else {
		return false;
	}
}

function host_url($path) {
	global $config;
	return "{$config['proto']}://{$config['http_host']}{$config['base']}{$path}";
}

function actor_content() {
    global $pkey;
    $public_key = openssl_pkey_get_details($pkey)['key'];

    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'endpoints' => [
            'sharedInbox' => host_url('/inbox')
        ],
        'followers' => host_url('/followers'),
        'following' => host_url('/following'),
        'inbox' => host_url('/inbox'),
        'name' => 'UnRelay',
        'type' => 'Application',
        'id' => host_url('/actor'),
        'publicKey' => [
            'id' => host_url('/actor#main-key'),
            'owner' => host_url('/actor'),
            'publicKeyPem' => str_replace("\n", '\n', trim($public_key))
        ],
        'summary' => 'UnRelay bot',
        'preferredUsername' => 'relay',
        'url' => host_url('/actor')
    ];
}

function actor_webfinger() {
    global $config;
    return [
        'subject' => "acct:relay@{$config['http_host']}",
        'aliases' => [host_url('/actor')],
        'links' => [[
            'href' => host_url('/actor'),
            'rel' => 'self',
            'type' => 'application/activity+json'
        ],[
            'href' => host_url('/actor'),
            'rel' => 'self',
            'type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
        ]]
    ];
}

function handle_follow_request($activity) {
    $host = parse_url($activity['actor'], PHP_URL_HOST);
    $port = parse_url($activity['actor'], PHP_URL_PORT);
    $target = $port ? $host . ':' . $port : $host;
    $tls = (parse_url($activity['actor'], PHP_URL_SCHEME) != 'http');

    send_inbox($target, [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'type' => 'Accept',
        'to' => [$activity['actor']],
        'actor' => host_url('/actor'),
        'object' => [
            'type' => 'Follow',
            'id' => $activity['id'],
            'object' => $activity['object'],
            'actor' => $activity['actor']
        ],
        'id' => host_url('/activities/' . uuid4())
    ], $tls);
}

function load_tokens() {
    $tokens_path = dirname(__FILE__) . '/tokens.php';
    if (file_exists($tokens_path)) {
        return require($tokens_path);
    } else {
        return [];
    }
}

function handle_index_request() {
    $tokens = load_tokens();
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $token = array_key_exists('authorization', $headers) ? trim($headers['authorization']) : '';
    $token = ltrim(substr($token, strpos($token, 'Bearer ') + 7));

    if (empty($token))
        send_error('missing authorization', 401, ['WWW-Authenticate: Bearer']);
    else if (!array_key_exists($token, $tokens)) {
        send_error('invalid api key', 403);
    } else if (!isset($_POST['statusUrl']) || !filter_var($_POST['statusUrl'], FILTER_VALIDATE_URL)) {
        send_error('invalid statusUrl', 400);
    }

    $target = $tokens[$token];
    $target_host = strstr($target, ':', true);
    $tls = $target_host != 'localhost';
    $url = trim($_POST['statusUrl']);
    $status_host = parse_url($url, PHP_URL_HOST);

    if ($target_host == $status_host) {
        return ['message' => 'local status ignored'];
    }
    return json_decode(send_inbox($target, [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'actor'    => host_url('/actor'),
        'id'       => host_url('/activities/' . uuid4()),
        'object'   => $url,
        'to'       => [host_url('/followers')],
        'type'     => 'Announce'
    ], $tls));
}

function send_inbox($target, $data, $tls=true, $verbose=false) {
    global $config;
    $payload = json_encode($data);
    $digest = sha256_digest($payload);
    $length = strlen($payload);
    $date = date('r');
    $scheme = $tls ? 'https' : 'http';
    $key_url = host_url('/actor#main-key');

    $sig_string = "(request-target): post /inbox\nhost: {$target}\ndate: {$date}\ndigest: SHA-256={$digest}\ncontent-length: {$length}";
    $signature = sign($sig_string);

    $ch = curl_init("{$scheme}://{$target}/inbox");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_HEADER, $verbose);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: UnRelay (at {$config['http_host']})",
        "Date: {$date}",
        'Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        "Content-Length: {$length}",
        "Digest: SHA-256={$digest}",
        "Signature: keyId=\"{$key_url}\",algorithm=\"rsa-sha256\",headers=\"(request-target) host date digest content-length\",signature=\"{$signature}\""
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
    	$response = curl_error($ch);
    }
    curl_close($ch);
    return $response;
}

function send_response($content, $content_type="application/ld+json; profile=\"https://www.w3.org/ns/activitystreams\"", $headers=[], $status=200) {
    if(!ob_start('ob_gzhandler')) ob_start();

    echo json_encode($content);

    http_response_code($status);
    foreach ($headers as $header) {
        header($header);
    }
    header('Content-Type: ' . $content_type . '; charset=utf-8');
    header('Content-Length: ' . ob_get_length());
    header('Connection: close');

    ob_end_flush();
    ob_flush();
    flush();
    if (session_id()) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

function send_error($message, $status, $headers=[]) {
    send_response(['error' => $message], 'application/json', $headers, $status);
    die;
}

function send_test_request($method, $url, $json=true, $data=null, $headers=[]) {
    $req_headers = ['User-Agent: UnRelay Test'];
    if ($json) {
        $payload = json_encode($data);
        $req_headers[] = 'Content-Type: application/json';
    } else {
        $payload = http_build_query($data);
    }
    $req_headers = array_merge($req_headers, $headers);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
    if ($method == 'POST') {
    	curl_setopt($ch, CURLOPT_POST, true);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	}

    echo "{$method} {$url}\n";
    $response = curl_exec($ch);
    if ($response === false) {
    	echo curl_error($ch) . "\n";
    } else {
    	echo $response . "\n";
    }
    curl_close($ch);
}

function handle_error($errno, $errstr, $errfile, $errline) {
    error_log('ERROR: '.$errno.' '.$errstr.' in '.$errfile.':'.$errline);
    send_error('internal server error', 500);
    die;
}
