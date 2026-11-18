<?php
/**
 * Apoio do harness de caracterizacao: contexto dos cenarios e normalizacao
 * de valores para comparacao entre versoes do SDK e do PHP.
 *
 * Escrito para rodar igual em PHP 7.0 e 8.4 (sem sintaxe posterior a 7.0).
 */

class HarnessContext
{
    /** @var string Diretorio compartilhado com o servidor falso */
    public $dir;
    /** @var string Base da API falsa, no formato da BASEURI real (.../core/v1) */
    public $baseUri;
    /** @var string Base apontando para uma porta fechada */
    public $closedUri;
    /** @var string Base apontando para o servidor que fecha a conexao sem responder */
    public $dropUri;
    /** @var string Base apontando para o servidor que nunca responde */
    public $hangUri;
    /** @var string Host do servidor falso (usado tambem como proxy HTTP) */
    public $fakeHost;
    /** @var int Porta do servidor falso */
    public $fakePort;
    /** @var string Diretorio das fixtures */
    public $fixtures;

    public function __construct(array $cfg)
    {
        $this->dir       = $cfg['dir'];
        $this->fakeHost  = $cfg['fake_host'];
        $this->fakePort  = $cfg['fake_port'];
        $this->baseUri   = 'http://' . $cfg['fake_host'] . ':' . $cfg['fake_port'] . '/core/v1';
        $this->closedUri = 'http://' . $cfg['fake_host'] . ':' . $cfg['closed_port'] . '/core/v1';
        $this->dropUri   = 'http://' . $cfg['fake_host'] . ':' . $cfg['drop_port'] . '/core/v1';
        $this->hangUri   = 'http://' . $cfg['fake_host'] . ':' . $cfg['hang_port'] . '/core/v1';
        $this->fixtures  = __DIR__ . '/fixtures';
    }

    /**
     * Enfileira a proxima resposta do servidor falso.
     */
    public function respond($status, $body, $headers = null, $delayMs = 0)
    {
        if ($headers === null) {
            $headers = array('Content-Type' => 'application/json; charset=utf-8');
        }
        $file = $this->dir . '/plan.json';
        $plan = is_file($file) ? json_decode(file_get_contents($file), true) : array();
        if (!is_array($plan)) {
            $plan = array();
        }
        $plan[] = array(
            'status'   => (int) $status,
            'headers'  => $headers,
            'body_b64' => base64_encode((string) $body),
            'delay_ms' => (int) $delayMs,
        );
        file_put_contents($file, json_encode($plan), LOCK_EX);
    }

    /**
     * Enfileira uma resposta a partir de um arquivo de fixtures/.
     */
    public function respondFixture($status, $name, $headers = null)
    {
        $this->respond($status, $this->fixture($name), $headers);
    }

    public function fixture($name)
    {
        return file_get_contents($this->fixtures . '/' . $name);
    }

    /**
     * Fixture JSON decodificada como objeto, para montar variacoes sem perder
     * a diferenca entre {} e [].
     */
    public function fixtureObject($name)
    {
        return json_decode($this->fixture($name));
    }
}

/**
 * Converte qualquer valor numa estrutura estavel, com o tipo de cada escalar
 * preservado, para que int/float/string nao se confundam na comparacao.
 */
function harness_dump($value, $depth = 0)
{
    if ($depth > 40) {
        return array('__profundidade' => 'limite');
    }
    if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
        return $value;
    }
    if (is_float($value)) {
        return array('__float' => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'));
    }
    if (is_array($value)) {
        $out = array();
        $isList = array_keys($value) === range(0, count($value) - 1) || count($value) === 0;
        foreach ($value as $k => $v) {
            $out[$isList ? $k : 'k:' . $k] = harness_dump($v, $depth + 1);
        }
        return array('__array' => $isList ? 'lista' : 'mapa', 'itens' => $out);
    }
    if ($value instanceof DateTime || $value instanceof DateTimeInterface) {
        return array(
            '__class' => get_class($value),
            'valor'   => $value->format('Y-m-d\TH:i:s.uP'),
            'fuso'    => $value->getTimezone()->getName(),
        );
    }
    if (is_object($value)) {
        $props = array();
        foreach (get_object_vars($value) as $k => $v) {
            $props[$k] = harness_dump($v, $depth + 1);
        }
        return array('__class' => get_class($value), 'props' => $props);
    }
    return array('__tipo' => gettype($value));
}

/**
 * Descreve uma excecao do jeito que o codigo cliente a enxerga: classe, mensagem, codigo,
 * a propriedade publica "errors" e os getters da APIException.
 */
function harness_dump_exception($e)
{
    $treated = isset($e->errors) ? $e->errors : null;

    $out = array(
        'classe'      => get_class($e),
        'hierarquia'  => array_values(class_parents($e)),
        'getMessage'  => harness_normalize_text($e->getMessage()),
        'getCode'     => $e->getCode(),
        'props'       => array(),
        // linha de log como um codigo cliente costuma montar a partir da excecao
        'linha_log_app' => harness_normalize_text(
            'Code: ' . $e->getCode() . ' - Message: ' . $e->getMessage()
            . ($treated !== null ? ' Exception: ' . json_encode($treated) : '')
        ),
        'anterior'    => $e->getPrevious() ? get_class($e->getPrevious()) : null,
    );

    foreach (get_object_vars($e) as $k => $v) {
        $out['props'][$k] = harness_dump($v);
    }

    if ($e instanceof \MundiAPILib\APIException) {
        $ctx = $e->getContext();
        $out['api'] = array(
            'getResponseCode' => $e->getResponseCode(),
            'getResponseBody' => $e->getResponseBody(),
            'getReason'       => $e->getReason(),
            'request'         => array(
                'getHttpMethod' => $ctx->getRequest()->getHttpMethod(),
                'getQueryUrl'   => $ctx->getRequest()->getQueryUrl(),
                'getHeaders'    => harness_dump($ctx->getRequest()->getHeaders()),
                'getParameters' => harness_dump($ctx->getRequest()->getParameters()),
            ),
            'response'        => array(
                'getStatusCode' => harness_dump($ctx->getResponse()->getStatusCode()),
                'getHeaders'    => harness_dump(harness_filter_response_headers($ctx->getResponse()->getHeaders())),
                'getRawBody'    => $ctx->getResponse()->getRawBody(),
            ),
        );
    }

    return $out;
}

/**
 * Remove dos cabecalhos de resposta o que varia a cada execucao.
 */
function harness_filter_response_headers($headers)
{
    if (!is_array($headers)) {
        return $headers;
    }
    foreach (array_keys($headers) as $name) {
        if (is_string($name) && strtolower($name) === 'date') {
            $headers[$name] = '{DATA}';
        }
    }
    return $headers;
}

/**
 * Tira de mensagens de erro do cURL os tempos medidos, que mudam a cada execucao.
 */
function harness_normalize_text($text)
{
    if (!is_string($text)) {
        return $text;
    }
    return preg_replace('/\d+ (ms|milliseconds)/', 'N $1', $text);
}
