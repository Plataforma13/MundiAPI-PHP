<?php
/**
 * Harness de caracterizacao do SDK: sobe o servidor falso, roda cada cenario
 * num processo separado e grava um JSON com a request que chegou no servidor,
 * o objeto de resposta mapeado, a excecao e todo aviso/deprecation do PHP.
 *
 *   php run.php --autoload=/caminho/vendor/autoload.php --out=resultado.json
 *               [--only=regex] [--label=texto] [--server-php=/caminho/php]
 *
 * O binario que executa este script e o mesmo que executa os cenarios.
 * --server-php define o PHP dos servidores falsos (padrao: o mesmo binario);
 * use o mesmo valor nas duas execucoes que serao comparadas.
 *
 * Nada aqui sai de 127.0.0.1.
 */

$opts = getopt('', array('autoload:', 'out:', 'only::', 'label::', 'server-php::'));
if (empty($opts['autoload']) || empty($opts['out'])) {
    fwrite(STDERR, "uso: php run.php --autoload=vendor/autoload.php --out=resultado.json [--only=regex] [--label=texto] [--server-php=php]\n");
    exit(2);
}
$autoload = realpath($opts['autoload']);
if ($autoload === false) {
    fwrite(STDERR, "autoload nao encontrado: {$opts['autoload']}\n");
    exit(2);
}
$serverPhp = !empty($opts['server-php']) ? $opts['server-php'] : PHP_BINARY;
$only      = !empty($opts['only']) ? $opts['only'] : null;

function free_port()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function wait_port($port, $seconds = 10)
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return true;
        }
        usleep(50000);
    }
    return false;
}

/**
 * Com $logFile a saida do processo vai para arquivo (servidores, que escrevem
 * uma linha por request); sem ele, fica em pipes para o chamador ler.
 */
function spawn(array $command, array $env, &$pipes, $logFile = null)
{
    $line = implode(' ', array_map('escapeshellarg', $command));
    $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    if ($logFile !== null) {
        $spec[1] = array('file', $logFile, 'a');
        $spec[2] = array('file', $logFile, 'a');
    }
    // "exec" faz o PHP filho substituir o shell, para o terminate atingir o processo certo
    return proc_open('exec ' . $line, $spec, $pipes, __DIR__, $env);
}

$dir = sys_get_temp_dir() . '/mundi-harness-' . getmypid() . '-' . mt_rand();
mkdir($dir, 0700, true);

$fakePort   = free_port();
$dropPort   = free_port();
$hangPort   = free_port();
$closedPort = free_port();

$env = array('MUNDI_FAKE_DIR' => $dir, 'PATH' => getenv('PATH'));

$servers = array();
$servers['fake'] = spawn(
    array($serverPhp, '-n', '-d', 'expose_php=0', '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $fakePort, __DIR__ . '/fake-server.php'),
    $env,
    $fakePipes,
    $dir . '/fake-server.log'
);
$servers['drop'] = spawn(
    array($serverPhp, '-n', __DIR__ . '/socket-server.php', (string) $dropPort, 'drop'),
    $env,
    $dropPipes,
    $dir . '/drop-server.log'
);
$servers['hang'] = spawn(
    array($serverPhp, '-n', __DIR__ . '/socket-server.php', (string) $hangPort, 'hang'),
    $env,
    $hangPipes,
    $dir . '/hang-server.log'
);

$cleanup = function () use (&$servers, $dir) {
    foreach ($servers as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
        }
    }
    foreach (glob($dir . '/*') as $file) {
        @unlink($file);
    }
    @rmdir($dir);
};
register_shutdown_function($cleanup);

foreach (array('fake' => $fakePort, 'drop' => $dropPort, 'hang' => $hangPort) as $which => $port) {
    if (!wait_port($port)) {
        fwrite(STDERR, "servidor $which nao subiu na porta $port\n");
        exit(1);
    }
}

$contextFile = $dir . '/context.json';
file_put_contents($contextFile, json_encode(array(
    'dir'         => $dir,
    'fake_host'   => '127.0.0.1',
    'fake_port'   => $fakePort,
    'closed_port' => $closedPort,
    'drop_port'   => $dropPort,
    'hang_port'   => $hangPort,
)));

// carrega so os nomes dos cenarios, sem carregar o SDK neste processo
$listing = spawn(
    // avisos do PHP nao podem se misturar ao JSON da listagem; eles sao medidos por cenario, mais abaixo
    array(PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', 'require $argv[1]; require $argv[2]; echo json_encode(array_keys(require $argv[3]));',
        $autoload, __DIR__ . '/lib.php', __DIR__ . '/scenarios.php'),
    $env,
    $listPipes
);
$names = json_decode(stream_get_contents($listPipes[1]), true);
$listErr = stream_get_contents($listPipes[2]);
proc_close($listing);
if (!is_array($names)) {
    fwrite(STDERR, "nao foi possivel listar os cenarios:\n$listErr\n");
    exit(1);
}

$replacements = array(
    '127.0.0.1:' . $fakePort   => '127.0.0.1:{FAKE}',
    '127.0.0.1:' . $closedPort => '127.0.0.1:{FECHADA}',
    '127.0.0.1:' . $dropPort   => '127.0.0.1:{DROP}',
    '127.0.0.1:' . $hangPort   => '127.0.0.1:{HANG}',
    '127.0.0.1 port ' . $fakePort   => '127.0.0.1 port {FAKE}',
    '127.0.0.1 port ' . $closedPort => '127.0.0.1 port {FECHADA}',
    '127.0.0.1 port ' . $dropPort   => '127.0.0.1 port {DROP}',
    '127.0.0.1 port ' . $hangPort   => '127.0.0.1 port {HANG}',
    '"port":"' . $fakePort . '"' => '"port":"{FAKE}"',
);

$results = array();
foreach ($names as $name) {
    if ($only !== null && !preg_match('~' . $only . '~', $name)) {
        continue;
    }
    file_put_contents($dir . '/plan.json', '[]');
    file_put_contents($dir . '/requests.jsonl', '');

    $child = spawn(
        array(PHP_BINARY, '-n', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            '-d', 'date.timezone=America/Sao_Paulo', __DIR__ . '/run-scenario.php', $autoload, $contextFile, $name),
        $env,
        $pipes
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $exit = proc_close($child);

    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        $result = array('cenario' => $name, 'fatal' => 'saida ilegivel', 'stdout' => $stdout);
    }
    $result['exit'] = $exit;
    $result['stderr'] = $stderr;

    $requests = array();
    foreach (file($dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $request = json_decode($line, true);
        $body = base64_decode($request['body_b64']);
        unset($request['body_b64']);
        $request['body'] = $body;
        $decoded = json_decode($body, true);
        $request['body_json'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        $requests[] = $request;
    }
    $result['requests'] = $requests;
    $result['respostas_nao_consumidas'] = count(json_decode(file_get_contents($dir . '/plan.json'), true));

    $encoded = strtr(json_encode($result), $replacements);
    $results[$name] = json_decode($encoded, true);

    $status = isset($result['fatal']) ? 'FATAL' : ($result['excecao'] ? 'excecao ' . $result['excecao']['classe'] : 'ok');
    $nErrors = isset($result['erros_php']) ? count($result['erros_php']) : 0;
    fwrite(STDERR, sprintf("%-48s %-52s requests=%d avisos=%d%s\n", $name, $status, count($requests), $nErrors, trim($stderr) !== '' ? ' STDERR' : ''));
}

$packages = array();
$installed = dirname($autoload) . '/composer/installed.json';
if (is_file($installed)) {
    $data = json_decode(file_get_contents($installed), true);
    $list = isset($data['packages']) ? $data['packages'] : $data;
    foreach ($list as $package) {
        $packages[$package['name']] = $package['version'];
    }
    ksort($packages);
}

$curl = curl_version();
$output = array(
    'meta' => array(
        'label'      => isset($opts['label']) ? $opts['label'] : '',
        'php'        => PHP_VERSION,
        'server_php' => trim(shell_exec(escapeshellarg($serverPhp) . ' -n -r ' . escapeshellarg('echo PHP_VERSION;'))),
        'curl'       => $curl['version'],
        'pacotes'    => $packages,
        'cenarios'   => count($results),
    ),
    'cenarios' => $results,
);

file_put_contents($opts['out'], json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDERR, "gravado em {$opts['out']}\n");
