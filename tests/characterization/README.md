# Harness de caracterização

Prova que uma versão do SDK se comporta igual a outra: mesma request na rede,
mesmo objeto de resposta mapeado e mesma exceção. Foi criado para a adequação
ao PHP 8.x, comparando a `3.1.18` em PHP 7.0 com o código novo em PHP 8.4 e 8.5.

Tudo roda contra servidores locais em `127.0.0.1`. Nada aqui chama a API real
da Mundipagg/Pagar.me e nenhuma credencial é verdadeira.

## Como rodar

Precisa de dois binários de PHP e de um `composer.phar` que rode em cada um
(Composer 2.2 LTS para PHP 7.0):

```sh
PHP_REF=/caminho/php7.0  COMPOSER_REF=/caminho/composer-2.2.phar \
PHP_NEW=/caminho/php8.4  COMPOSER_NEW=/caminho/composer.phar \
tests/characterization/run-comparison.sh
```

O script exporta a tag `3.1.18` com as dependências fixadas nas versões em uso
com ela (`apimatic/unirest-php` 2.3.0 e `apimatic/jsonmapper` v1.3.1), instala o
diretório de trabalho atual por um repositório `path` do Composer, roda os
cenários dos dois lados e compara. Sai com código 0 quando não há diferença
fora das previstas.

Para rodar um lado só, ou comparar outras combinações:

```sh
php tests/characterization/run.php --autoload=/projeto/vendor/autoload.php --out=resultado.json
php tests/characterization/compare.php referencia.json candidato.json [--verbose]
```

## O que é comparado

Em cada cenário:

- **request**: método, URI, cabeçalhos e corpo (byte a byte) que chegaram no servidor falso;
- **resposta**: o objeto devolvido pelo SDK, com classe e tipo de cada valor, e o `json_encode` dele;
- **exceção**: classe, hierarquia, `getMessage()`, `getCode()`, a propriedade `errors`, os getters da
  `APIException` e uma linha de log montada a partir da exceção;
- **avisos do PHP**: todo warning, notice e deprecation com `error_reporting=E_ALL`, contados à parte.

Cada cenário roda em processo próprio, com o estado estático zerado, como numa request web.

## Cenários

`scenarios.php` espelha o uso de uma aplicação cliente:

- criar pedido com cartão, boleto, Pix e SafetyPay com boleto; consultar pedido; consultar, capturar,
  cancelar e criar charge;
- configuração de credenciais, troca de loja no mesmo processo e proxy;
- respostas 2xx fora do esperado: corpo vazio, não JSON, nulos em campos obrigatórios, tipos trocados,
  datas em vários formatos;
- erros 400, 401, 403, 404, 412, 422, 429, 500, 502, 503 e 504, com e sem corpo no formato da Mundipagg;
- falhas de rede: conexão recusada, conexão fechada sem resposta e timeout.

`scenarios-full-surface.php` gera por reflexão um cenário para cada método público de cada controller
(128 endpoints), com o request e a resposta preenchidos a partir dos docblocks dos models.

## Diferenças previstas

Ficam em `accepted-differences.php`, cada uma com o motivo. Hoje são duas:

- a base de fusos do PHP: versões anteriores a 2019 ainda aplicam horário de verão em
  `America/Sao_Paulo`, então um vencimento montado nesse fuso entre novembro e fevereiro sai uma hora
  antes. Vem do PHP, não do SDK;
- o texto do `TypeError` quando `HttpRequest` ou `HttpCallBack` recebem argumento inválido no construtor.

## Arquivos

| Arquivo | Papel |
| --- | --- |
| `run-comparison.sh` | monta os dois ambientes, roda e compara |
| `run.php` | sobe os servidores falsos e roda cada cenário num processo |
| `run-scenario.php` | executa um cenário e captura resultado, exceção e avisos |
| `compare.php` | compara dois resultados |
| `fake-server.php` | API falsa: grava a request e devolve a resposta planejada |
| `socket-server.php` | servidor TCP cru para conexão fechada e timeout |
| `scenarios.php`, `scenarios-full-surface.php` | cenários |
| `fixtures/` | respostas no formato da API |
