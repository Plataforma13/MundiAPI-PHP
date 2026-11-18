<?php
/**
 * Compara dois resultados gravados por run.php, cenario a cenario.
 *
 *   php compare.php referencia.json candidato.json [--verbose]
 *
 * Em cada cenario compara: as requests que chegaram no servidor falso
 * (metodo, URI, cabecalhos e corpo byte a byte), o objeto de resposta mapeado
 * e a excecao. Avisos e deprecations do PHP sao contados a parte.
 *
 * Diferencas previstas ficam em accepted-differences.php, com o motivo; o que
 * nao estiver la reprova a comparacao (codigo de saida 1).
 */

if ($argc < 3) {
    fwrite(STDERR, "uso: php compare.php referencia.json candidato.json [--verbose]\n");
    exit(2);
}
$verbose = in_array('--verbose', $argv, true);
$a = json_decode(file_get_contents($argv[1]), true);
$b = json_decode(file_get_contents($argv[2]), true);
if (!is_array($a) || !is_array($b)) {
    fwrite(STDERR, "arquivo de resultado ilegivel\n");
    exit(2);
}
$accepted = require __DIR__ . '/accepted-differences.php';

function describe($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return strlen($json) > 300 ? substr($json, 0, 300) . '...' : $json;
}

function deep_diff($left, $right, $path, array &$out)
{
    if (is_array($left) && is_array($right)) {
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
            $sub = $path . '/' . $key;
            if (!array_key_exists($key, $left)) {
                $out[] = array($sub, '(ausente)', describe($right[$key]));
            } elseif (!array_key_exists($key, $right)) {
                $out[] = array($sub, describe($left[$key]), '(ausente)');
            } else {
                deep_diff($left[$key], $right[$key], $sub, $out);
            }
        }
        return;
    }
    if ($left !== $right) {
        $out[] = array($path, describe($left), describe($right));
    }
}

function accepted_reason(array $accepted, $scenario, $path, $left, $right)
{
    foreach ($accepted as $rule) {
        if (!preg_match($rule['cenario'], $scenario) || !preg_match($rule['caminho'], $path)) {
            continue;
        }
        if (isset($rule['referencia']) && !preg_match($rule['referencia'], $left)) {
            continue;
        }
        if (isset($rule['candidato']) && !preg_match($rule['candidato'], $right)) {
            continue;
        }
        return $rule['motivo'];
    }
    return null;
}

printf("referencia: %s | PHP %s | %s\n", $a['meta']['label'], $a['meta']['php'], describe($a['meta']['pacotes']));
printf("candidato:  %s | PHP %s | %s\n\n", $b['meta']['label'], $b['meta']['php'], describe($b['meta']['pacotes']));

$parts = array('requests' => 'request', 'resultado' => 'resposta', 'excecao' => 'excecao');
$unexpected = 0;
$acceptedCount = array();
$warningsA = 0;
$warningsB = 0;
$warningsBByOrigin = array();
$equal = 0;

$names = array_unique(array_merge(array_keys($a['cenarios']), array_keys($b['cenarios'])));
foreach ($names as $name) {
    if (!isset($a['cenarios'][$name]) || !isset($b['cenarios'][$name])) {
        printf("DIFERENTE %s: cenario existe so em um dos lados\n", $name);
        $unexpected++;
        continue;
    }
    $left = $a['cenarios'][$name];
    $right = $b['cenarios'][$name];

    $lines = array();
    $bad = 0;
    foreach (array_merge(array('fatal'), array_keys($parts)) as $part) {
        $l = isset($left[$part]) ? $left[$part] : null;
        $r = isset($right[$part]) ? $right[$part] : null;
        $diffs = array();
        deep_diff($l, $r, $part, $diffs);
        foreach ($diffs as $diff) {
            $reason = accepted_reason($accepted, $name, $diff[0], $diff[1], $diff[2]);
            if ($reason === null) {
                $bad++;
                $lines[] = sprintf("    %s\n      referencia: %s\n      candidato:  %s", $diff[0], $diff[1], $diff[2]);
            } else {
                $acceptedCount[$reason] = (isset($acceptedCount[$reason]) ? $acceptedCount[$reason] : 0) + 1;
                if ($verbose) {
                    $lines[] = sprintf("    (aceita: %s) %s\n      referencia: %s\n      candidato:  %s", $reason, $diff[0], $diff[1], $diff[2]);
                }
            }
        }
    }
    foreach (array('respostas_nao_consumidas', 'exit') as $part) {
        if ($left[$part] !== $right[$part]) {
            $bad++;
            $lines[] = sprintf("    %s: %s -> %s", $part, describe($left[$part]), describe($right[$part]));
        }
    }

    $wa = isset($left['erros_php']) ? count($left['erros_php']) : 0;
    $wb = isset($right['erros_php']) ? count($right['erros_php']) : 0;
    $warningsA += $wa;
    $warningsB += $wb;
    if (isset($right['erros_php'])) {
        foreach ($right['erros_php'] as $error) {
            $origin = substr($error['arquivo'], 0, strpos($error['arquivo'] . '/', '/'));
            $key = $origin . ' | ' . $error['tipo'] . ' | ' . $error['mensagem'];
            $warningsBByOrigin[$key] = (isset($warningsBByOrigin[$key]) ? $warningsBByOrigin[$key] : 0) + 1;
        }
    }
    if (trim($right['stderr']) !== '') {
        $bad++;
        $lines[] = '    stderr do candidato: ' . substr(trim($right['stderr']), 0, 300);
    }

    if ($bad > 0) {
        $unexpected += $bad;
        printf("DIFERENTE %s\n%s\n", $name, implode("\n", $lines));
    } else {
        $equal++;
        if ($verbose) {
            printf("igual     %s%s\n", $name, $lines ? "\n" . implode("\n", $lines) : '');
        }
    }
}

printf("\ncenarios: %d | iguais: %d | com diferenca nao prevista: %d\n", count($names), $equal, count($names) - $equal);
if ($acceptedCount) {
    echo "diferencas aceitas (ver accepted-differences.php):\n";
    foreach ($acceptedCount as $reason => $count) {
        printf("  %4d  %s\n", $count, $reason);
    }
}
printf("avisos/deprecations do PHP: referencia=%d candidato=%d\n", $warningsA, $warningsB);
ksort($warningsBByOrigin);
foreach ($warningsBByOrigin as $key => $count) {
    printf("  %4d  %s\n", $count, $key);
}

exit($unexpected > 0 ? 1 : 0);
