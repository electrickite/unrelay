<?php
$root_path = dirname(__FILE__, 2);
require_once $root_path . '/src/lib.php';

set_error_handler('handle_error');
set_time_limit(0);
ignore_user_abort(true);

$config_path = $root_path . '/config/relay.php';
$config = file_exists($config_path) ? require($config_path) : [];
$config = array_merge([
    'http_host' => $_ENV['HTTP_HOST'] ?? $_SERVER['HTTP_HOST'],
    'proto'     => $_ENV['PROTO'] ?? (is_secure() ? 'https' : 'http'),
    'base'      => $_ENV['BASE'] ?? ''
], $config);

$pkey_path = $root_path . '/config/key.pem';
if (isset($config['pkey'])) {
    $pkey = openssl_pkey_get_private($config['pkey']);
} else if (file_exists($pkey_path)) {
    $pkey = openssl_pkey_get_private('file://' . $pkey_path);
} else {
    $pkey = openssl_pkey_new();
    openssl_pkey_export_to_file($pkey, $pkey_path);
}

$path = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $config['base']);
switch ($path) {
    case '/index':
        requires_method('POST');
        $content_type = $_SERVER['CONTENT_TYPE'] ?? null;
        if (empty($_POST) && $content_type == 'application/json') {
            $post_data = json_decode(file_get_contents('php://input'), true);
            if (is_array($post_data)) {
                $_POST = $post_data;
            }
        }
        $tokens = load_tokens($root_path . '/config/tokens.php');
        send_response(handle_index_request($tokens), 'application/json');
        break;

    case '/inbox':
        requires_method('POST');
        if (!$activity = build_activity()) {
            send_error('bad request', 400);
        }

        if ($activity['type'] == 'Follow') {
            handle_follow_request($activity);
        }
        send_response((object) []);
        break;

    case '/actor':
        requires_method('GET');
        send_response(actor_content());
        break;

    case '/.well-known/webfinger':
        requires_method('GET');
        $resource = $_GET['resource'] ?? null;
        if ($resource == 'acct:relay@' . $config['http_host']) {
            send_response(actor_webfinger(), 'application/json');
        } else {
            send_error('user not found', 404);
        }
        break;

    default:
        send_error('not found', 404);
}

die;
