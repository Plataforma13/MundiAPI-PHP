<?php
/**
 * Servidor falso da API da Mundipagg usado pelo harness de caracterizacao.
 *
 * Roda como router do servidor embutido do PHP:
 *   MUNDI_FAKE_DIR=/dir php -S 127.0.0.1:PORTA fake-server.php
 *
 * Cada request recebida e gravada (metodo, URI, cabecalhos e corpo) em
 * $MUNDI_FAKE_DIR/requests.jsonl. A resposta e a proxima da fila
 * $MUNDI_FAKE_DIR/plan.json, escrita pelo cenario antes de chamar o SDK.
 *
 * Nunca fala com a API real: tudo aqui e local.
 */

$dir = getenv('MUNDI_FAKE_DIR');
if ($dir === false || !is_dir($dir)) {
    http_response_code(599);
    echo 'MUNDI_FAKE_DIR invalido';
    return true;
}

$headers = array();
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
} else {
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }
}
ksort($headers);

$record = array(
    'method'   => $_SERVER['REQUEST_METHOD'],
    'uri'      => $_SERVER['REQUEST_URI'],
    'headers'  => $headers,
    'body_b64' => base64_encode(file_get_contents('php://input')),
);
file_put_contents($dir . '/requests.jsonl', json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

// consome a proxima resposta planejada
$planFile = $dir . '/plan.json';
$plan = is_file($planFile) ? json_decode(file_get_contents($planFile), true) : array();
$next = is_array($plan) && count($plan) > 0 ? array_shift($plan) : null;
file_put_contents($planFile, json_encode(is_array($plan) ? $plan : array()), LOCK_EX);

if ($next === null) {
    http_response_code(599);
    header('Content-Type: text/plain');
    echo 'request nao planejada pelo cenario';
    return true;
}

if (!empty($next['delay_ms'])) {
    usleep((int) $next['delay_ms'] * 1000);
}

http_response_code((int) $next['status']);
foreach ($next['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo base64_decode($next['body_b64']);

return true;
