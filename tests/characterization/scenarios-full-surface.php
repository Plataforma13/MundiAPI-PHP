<?php
/**
 * Cenarios gerados por reflexao para TODA a superficie do SDK, alem do que
 * scenarios.php cobre: cada metodo publico de cada controller e chamado com argumentos
 * montados a partir do docblock, e o servidor falso devolve uma resposta
 * preenchida a partir do model que o metodo mapeia.
 *
 * Serve para provar que a serializacao de todos os models de request e o
 * mapeamento de todos os models de resposta nao mudaram entre versoes.
 * Os valores sao deterministicos: dependem so dos nomes de classe e de campo.
 */

use MundiAPILib\Configuration;
use MundiAPILib\MundiAPIClient;

/**
 * Le "@var tipo $nome" e "@maps chave" das propriedades publicas de um model.
 */
function surface_properties($class)
{
    static $cache = array();
    if (isset($cache[$class])) {
        return $cache[$class];
    }
    $out = array();
    $rc = new ReflectionClass($class);
    foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        if ($prop->isStatic()) {
            continue;
        }
        $doc = (string) $prop->getDocComment();
        $type = preg_match('/@var\s+(\S+)/', $doc, $m) ? $m[1] : 'mixed';
        $out[$prop->getName()] = $type;
    }
    return $cache[$class] = $out;
}

/**
 * Subclasses concretas dos models polimorficos (discriminator do JsonMapper).
 */
function surface_subclasses($class)
{
    $map = array(
        'MundiAPILib\\Models\\GetTransactionResponse' => array(
            'MundiAPILib\\Models\\GetCreditCardTransactionResponse' => 'credit_card',
            'MundiAPILib\\Models\\GetBoletoTransactionResponse' => 'boleto',
            'MundiAPILib\\Models\\GetPixTransactionResponse' => 'pix',
            'MundiAPILib\\Models\\GetSafetyPayTransactionResponse' => 'safetypay',
            'MundiAPILib\\Models\\GetDebitCardTransactionResponse' => 'debit_card',
            'MundiAPILib\\Models\\GetVoucherTransactionResponse' => 'voucher',
            'MundiAPILib\\Models\\GetBankTransferTransactionResponse' => 'bank_transfer',
            'MundiAPILib\\Models\\GetCashTransactionResponse' => 'cash',
            'MundiAPILib\\Models\\GetPrivateLabelTransactionResponse' => 'private_label',
        ),
    );
    return isset($map[$class]) ? $map[$class] : array();
}

/**
 * Monta um valor de exemplo para um tipo de docblock.
 */
function surface_value($type, $seed, $depth)
{
    $type = preg_replace('/\|null$|^null\|/', '', $type);
    $n = abs(crc32($seed)) % 9000 + 1000;

    if (substr($type, -2) === '[]') {
        $inner = substr($type, 0, -2);
        if ($depth <= 0 && surface_class($inner) !== null) {
            return array();
        }
        return array(surface_value($inner, $seed . '#0', $depth), surface_value($inner, $seed . '#1', $depth));
    }

    switch (strtolower(ltrim($type, '\\'))) {
        case 'string':
            return 'v-' . $seed;
        case 'int':
        case 'integer':
            return $n;
        case 'float':
        case 'double':
            return $n + 0.5;
        case 'bool':
        case 'boolean':
            return $n % 2 === 0;
        case 'datetime':
            return new DateTime('2027-03-' . sprintf('%02d', $n % 27 + 1) . 'T10:11:12Z');
        case 'array':
            return array('chave' => 'v-' . $seed, 'numero' => $n);
        case 'mixed':
        case 'object':
            return null;
    }

    $class = surface_class($type);
    if ($class === null || $depth <= 0) {
        return null;
    }
    return surface_populate($class, $seed, $depth - 1);
}

function surface_class($type)
{
    $name = ltrim($type, '\\');
    foreach (array($name, 'MundiAPILib\\Models\\' . $name, 'MundiAPILib\\' . $name) as $candidate) {
        if (strpos($candidate, 'MundiAPILib\\Models\\') === 0 && class_exists($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Instancia um model e preenche todas as propriedades publicas.
 */
function surface_populate($class, $seed, $depth)
{
    $subclasses = surface_subclasses($class);
    $discriminator = null;
    if ($subclasses) {
        $keys = array_keys($subclasses);
        $class = $keys[abs(crc32($seed)) % count($keys)];
        $discriminator = $subclasses[$class];
    }
    $object = new $class();
    foreach (surface_properties($class) as $name => $type) {
        $object->$name = surface_value($type, $name, $depth);
    }
    if ($discriminator !== null) {
        $object->transactionType = $discriminator;
    }
    return $object;
}

/**
 * Lista "Controller::metodo" de todos os endpoints do SDK.
 */
function surface_endpoints()
{
    $out = array();
    $client = new ReflectionClass('MundiAPILib\\MundiAPIClient');
    foreach ($client->getMethods(ReflectionMethod::IS_PUBLIC) as $getter) {
        if (strpos($getter->getName(), 'get') !== 0) {
            continue;
        }
        if (!preg_match('/@return\s+Controllers\\\\(\w+)/', (string) $getter->getDocComment(), $m)) {
            continue;
        }
        $controller = 'MundiAPILib\\Controllers\\' . $m[1];
        $rc = new ReflectionClass($controller);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $controller) {
                continue;
            }
            $out[$m[1] . '::' . $method->getName()] = array($getter->getName(), $method);
        }
    }
    ksort($out);
    return $out;
}

/**
 * Model de resposta que o metodo mapeia, lido do proprio codigo do controller.
 */
function surface_response_model(ReflectionMethod $method)
{
    $lines = file($method->getFileName());
    $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    if (preg_match("/->(mapClass|mapClassArray)\(\s*\\\$response->body,\s*'([^']+)'/", $body, $m)) {
        return array($m[1], str_replace('\\\\', '\\', $m[2]));
    }
    return array(null, null);
}

function surface_arguments(ReflectionMethod $method)
{
    $doc = (string) $method->getDocComment();
    $args = array();
    foreach ($method->getParameters() as $param) {
        $type = preg_match('/@param\s+(\S+)\s+\$' . preg_quote($param->getName(), '/') . '\b/', $doc, $m) ? $m[1] : 'mixed';
        $args[] = surface_value(preg_replace('/^Models\\\\/', '', $type), $param->getName(), 3);
    }
    return $args;
}

$surface = array();
foreach (surface_endpoints() as $label => $info) {
    $getter = $info[0];
    $method = $info[1];
    $surface['superficie_' . str_replace('::', '_', $label)] = function (HarnessContext $t) use ($getter, $method) {
        Configuration::$BASEURI = $t->baseUri;
        $client = new MundiAPIClient('sk_test_harness_superficie', 'senha-superficie');

        list($mapper, $model) = surface_response_model($method);
        if ($model !== null && class_exists($model)) {
            $payload = surface_populate($model, $method->getName(), 3);
            $json = json_encode($mapper === 'mapClassArray' ? array($payload, $payload) : $payload);
        } else {
            $json = '{}';
        }
        $t->respond(200, $json);

        $controller = $client->$getter();
        $result = call_user_func_array(array($controller, $method->getName()), surface_arguments($method));

        return array('modelo_de_resposta' => $model, 'objeto' => $result, 'json' => json_encode($result));
    };
}

return $surface;
