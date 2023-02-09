<?php
require_once 'lib.php';

if (!isset($argc) || $argc < 3) {
	error_log(print_r(getallheaders(), true));
    error_log(file_get_contents('php://input'));
    echo '{}';
	die;
}

$config = [
	'http_host' => $argv[3] ?? 'localhost:8081',
	'proto'     => 'http',
	'base'      => ''
];
$pkey = openssl_pkey_new();

switch ($argv[2]) {
    case 'actor':
    	send_test_request('GET', "http://{$argv[1]}/actor");
        break;
    case 'webfinger':
        $acct = $argv[3] ?? "relay@{$argv[1]}";
    	send_test_request('GET', "http://{$argv[1]}/.well-known/webfinger?resource=acct:{$acct}");
        break;
    case 'follow':
        echo "POST http://{$argv[1]}/inbox\n";
        $response = send_inbox($argv[1], [
	        '@context' => 'https://www.w3.org/ns/activitystreams',
	        'type' => 'Follow',
	        'actor' => "http://{$config['http_host']}/actor",
	        'object' => "http://{$argv[1]}/actor",
	        'id' => "http://{$config['http_host']}/activities/" . uuid4()
    	], false, true);
    	echo $response . "\n";
        break;
    case 'path':
        $path = $argv[3] ?? '';
        send_test_request('GET', "http://{$argv[1]}/{$path}");
        break;
    case 'index':
    case 'index-json':
    case 'index-noauth':
        $url = $argv[3] ?? 'https://example.com/users/foo/statuses/109370844647385274';
        $headers = $argv[2] == 'index-noauth' ? [] : ['Authorization: Bearer qwerty'];
        send_test_request('POST', "http://{$argv[1]}/index", ($argv[2] == 'index-json'), [
            'statusUrl' => $url,
        ], $headers);
        break;
    default:
        echo "Test not found";
}
