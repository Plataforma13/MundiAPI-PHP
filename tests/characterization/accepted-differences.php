<?php
/**
 * Diferencas previstas entre a referencia (3.1.18) e o candidato na comparacao
 * do harness. Cada regra casa pelo nome do cenario e pelo caminho do valor
 * (expressoes regulares). O que nao estiver aqui reprova a comparacao.
 */
return array(
    // Diferenca do proprio PHP, nao do SDK.
    array(
        'cenario' => '~^criar_pedido_boleto_vencimento_no_antigo_horario_de_verao$~',
        'caminho' => '~^(requests/0/body$|requests/0/body_json/payments/0/boleto/due_at$|resultado/itens/k:dueAt_depois_da_chamada/valor$|resultado/itens/k:base_de_fusos_do_php$)~',
        'motivo'  => 'base de fusos do PHP: versoes anteriores a 2019 ainda aplicam horario de verao em America/Sao_Paulo (due_at sai 1h antes)',
    ),
    // Unica mudanca do SDK: o texto do TypeError quando HttpRequest ou HttpCallBack recebem argumento
    // invalido no construtor. A classe do erro e a mesma.
    array(
        'cenario' => '~^contrato_http_(request|callback)_argumento_invalido$~',
        'caminho' => '~^excecao/(getMessage|linha_log_app)$~',
        'motivo'  => 'texto do TypeError de argumento invalido em HttpRequest/HttpCallBack (validacao saiu da assinatura para o corpo do construtor)',
    ),
);
