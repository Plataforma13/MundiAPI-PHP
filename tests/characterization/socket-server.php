<?php
/**
 * Servidor TCP cru para os cenarios de falha de rede do harness.
 *
 *   php socket-server.php PORTA drop   aceita a conexao, le a request e fecha sem responder
 *   php socket-server.php PORTA hang   aceita a conexao e nunca responde (forca o timeout do cliente)
 */

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$mode = isset($argv[2]) ? $argv[2] : 'drop';

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "socket-server: $errstr ($errno)\n");
    exit(1);
}

$held = array();
while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if ($conn === false) {
        continue;
    }
    if ($mode === 'hang') {
        // segura a conexao aberta sem responder
        $held[] = $conn;
        continue;
    }
    stream_set_timeout($conn, 2);
    $buffer = '';
    while (strpos($buffer, "\r\n\r\n") === false) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }
    fclose($conn);
}
