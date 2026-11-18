<?php
/**
 * Executa UM cenario em processo proprio (estado estatico zerado, como numa
 * request web) e imprime o resultado em JSON na saida padrao.
 *
 *   php run-scenario.php AUTOLOAD CONTEXTO.json CENARIO
 *
 * Chamado por run.php; nao foi feito para uso direto.
 */

error_reporting(E_ALL);

$autoload    = $argv[1];
$contextFile = $argv[2];
$name        = $argv[3];

$GLOBALS['harness_errors'] = array();
$GLOBALS['harness_roots']  = array('{HARNESS}' => __DIR__);

function harness_relative_path($file)
{
    $best = $file;
    $bestLen = 0;
    foreach ($GLOBALS['harness_roots'] as $token => $root) {
        if ($root !== '' && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && strlen($root) > $bestLen) {
            $best = $token . substr($file, strlen($root));
            $bestLen = strlen($root);
        }
    }
    return $best;
}

function harness_error_name($type)
{
    $names = array(
        E_WARNING => 'E_WARNING', E_NOTICE => 'E_NOTICE', E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED', E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE', 2048 => 'E_STRICT', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_USER_ERROR => 'E_USER_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_CORE_WARNING => 'E_CORE_WARNING',
    );
    return isset($names[$type]) ? $names[$type] : 'E_' . $type;
}

set_error_handler(function ($type, $message, $file, $line) {
    $GLOBALS['harness_errors'][] = array(
        'tipo'     => harness_error_name($type),
        'mensagem' => $message,
        'arquivo'  => $file,
        'linha'    => $line,
    );
    return true;
}, E_ALL);

$GLOBALS['harness_done'] = false;
register_shutdown_function(function () use ($name) {
    if ($GLOBALS['harness_done']) {
        return;
    }
    $last = error_get_last();
    echo json_encode(array(
        'cenario' => $name,
        'fatal'   => $last ? array(
            'tipo'     => harness_error_name($last['type']),
            'mensagem' => $last['message'],
            'arquivo'  => harness_relative_path($last['file']),
            'linha'    => $last['line'],
        ) : 'processo encerrado sem resultado',
        'erros_php' => $GLOBALS['harness_errors'],
    ));
});

require $autoload;
require __DIR__ . '/lib.php';

$GLOBALS['harness_roots']['{VENDOR}'] = dirname(realpath($autoload));
$sdkFile = (new ReflectionClass('MundiAPILib\\Configuration'))->getFileName();
$GLOBALS['harness_roots']['{SDK}'] = dirname(dirname($sdkFile));

$scenarios = require __DIR__ . '/scenarios.php';
if (!isset($scenarios[$name])) {
    fwrite(STDERR, "cenario desconhecido: $name\n");
    exit(2);
}

$context = new HarnessContext(json_decode(file_get_contents($contextFile), true));

$out = array('cenario' => $name, 'resultado' => null, 'excecao' => null);
try {
    $out['resultado'] = harness_dump(call_user_func($scenarios[$name], $context));
} catch (Throwable $e) {
    $out['excecao'] = harness_dump_exception($e);
}

foreach ($GLOBALS['harness_errors'] as $i => $error) {
    $GLOBALS['harness_errors'][$i]['arquivo'] = harness_relative_path($error['arquivo']);
}
$out['erros_php'] = $GLOBALS['harness_errors'];

$json = json_encode($out);
if ($json === false) {
    $json = json_encode(array('cenario' => $name, 'fatal' => 'json_encode: ' . json_last_error_msg(), 'erros_php' => $GLOBALS['harness_errors']));
}
$GLOBALS['harness_done'] = true;
echo $json;
