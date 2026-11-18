<?php
/**
 * Cenarios de caracterizacao do SDK, espelhando o uso de uma aplicacao cliente:
 * pedidos com cartao, boleto, Pix e SafetyPay, consulta, captura, cancelamento
 * e criacao de charge, troca de credenciais e proxy.
 *
 * Cada cenario recebe um HarnessContext, enfileira as respostas do servidor
 * falso e devolve o que o SDK retornou. Excecoes sao capturadas pelo executor.
 *
 * Tudo roda contra 127.0.0.1. Nenhuma credencial aqui e real.
 */

use MundiAPILib\Configuration;
use MundiAPILib\Controllers\ChargesController;
use MundiAPILib\Controllers\OrdersController;
use MundiAPILib\Http\HttpCallBack;
use MundiAPILib\Models\CreateAddressRequest;
use MundiAPILib\Models\CreateBoletoPaymentRequest;
use MundiAPILib\Models\CreateCardRequest;
use MundiAPILib\Models\CreateChargeRequest;
use MundiAPILib\Models\CreateCreditCardPaymentRequest;
use MundiAPILib\Models\CreateCustomerRequest;
use MundiAPILib\Models\CreateOrderItemRequest;
use MundiAPILib\Models\CreateOrderRequest;
use MundiAPILib\Models\CreatePaymentRequest;
use MundiAPILib\Models\CreatePhoneRequest;
use MundiAPILib\Models\CreatePhonesRequest;
use MundiAPILib\Models\CreatePixPaymentRequest;
use MundiAPILib\MundiAPIClient;

/**
 * Subclasse de um model do SDK no codigo cliente: estende CreateChargeRequest
 * e sobrescreve jsonSerialize() sem tipo de retorno.
 */
class HarnessChargeRequest extends CreateChargeRequest
{
    /** @var int $orderId */
    public $orderId;

    public function jsonSerialize()
    {
        $json = parent::jsonSerialize();
        $json['order_id'] = $this->orderId;
        return $json;
    }
}

/**
 * Inicializacao do client como o codigo cliente faz: grava as credenciais na
 * Configuration estatica e instancia o client, com ou sem proxy.
 */
function app_create_instance(HarnessContext $t, $user = 'sk_test_harness_loja_a', $pass = 'senha-loja-a', $proxy = null)
{
    Configuration::$BASEURI = $t->baseUri;
    Configuration::$basicAuthUserName = $user;
    Configuration::$basicAuthPassword = $pass;

    return new MundiAPIClient(Configuration::$basicAuthUserName, Configuration::$basicAuthPassword, $proxy);
}

/** Valor em centavos, sem separador */
function app_convert_amount($amount)
{
    return number_format($amount, 2, '', '');
}

/** Cliente com telefone e endereco */
function app_customer($name, $email, $document, $cellphone)
{
    $customer = new CreateCustomerRequest();
    $customer->name = trim(mb_strimwidth($name, 0, 64, ''));
    $customer->email = $email;
    $customer->document = $document;
    $customer->type = 'individual';
    $customer->gender = 'male';

    $phones    = new CreatePhonesRequest();
    $homePhone = new CreatePhoneRequest();
    $homePhone->countryCode = '55';
    $homePhone->areaCode = substr($cellphone, 0, 2);
    $homePhone->number   = substr($cellphone, 2, 9);
    $phones->homePhone   = $homePhone;
    $customer->phones = $phones;

    $createAddress = new CreateAddressRequest();
    $createAddress->street = trim(mb_strimwidth('Avenida Afonso Pena', 0, 64, ''));
    $createAddress->number = '1500';
    $createAddress->neighborhood = trim(mb_strimwidth('Centro', 0, 64, ''));
    $createAddress->complement = trim(mb_strimwidth('Sala 3 / Bloco B', 0, 64, ''));
    $createAddress->city = trim(mb_strimwidth('Belo Horizonte', 0, 64, ''));
    $createAddress->state = 'MG';
    $createAddress->country = 'BR';
    $createAddress->zipCode = '30130005';

    $customer->address = $createAddress;

    return $customer;
}

/** Item do pedido */
function app_item($orderId, $description, $amount)
{
    $passage = new CreateOrderItemRequest();
    $passage->code = $orderId . '-AUTHORIZATION-hml';
    $passage->description = $description;
    $passage->amount = app_convert_amount($amount);
    $passage->quantity = 1;

    return $passage;
}

/** Pedido com um ou mais pagamentos */
function app_order_request($orderId, $amount, array $payments, $name = 'João da Conceição Araújo')
{
    $createOrderRequest = new CreateOrderRequest();
    $createOrderRequest->customer = app_customer($name, 'joao.teste@example.com', '00000000191', '31999990000');
    $createOrderRequest->items[] = app_item($orderId, 'Ida:AB12CD Volta:EF34GH', $amount);
    $createOrderRequest->payments = $payments;
    $createOrderRequest->code = $orderId;
    $createOrderRequest->currency = 'BRL';

    return $createOrderRequest;
}

/** Pagamento com cartao de credito */
function app_payment_credit_card()
{
    $paymentRequest = new CreatePaymentRequest();
    $paymentRequest->paymentMethod = 'credit_card';

    $crediCardPayment = new CreateCreditCardPaymentRequest();
    $crediCardPayment->recurrence   = false;
    $crediCardPayment->capture      = false;
    $crediCardPayment->installments = 3;
    // propriedade fora da declaracao do model (a declarada e statementDescriptor):
    // vira propriedade dinamica e o valor nao entra no JSON enviado
    $crediCardPayment->statement_descriptor = 'LOJA TESTE HARNESS LTDA';

    $createCardRequest = new CreateCardRequest();
    $createCardRequest->holderName = 'JOAO C ARAUJO';
    $createCardRequest->number     = '4000000000000010';
    $createCardRequest->cvv        = '123';
    $createCardRequest->brand      = 'visa';
    $createCardRequest->expMonth   = '12';
    $createCardRequest->expYear    = '30';

    $crediCardPayment->card = $createCardRequest;
    $paymentRequest->creditCard = $crediCardPayment;

    return $paymentRequest;
}

/** Pagamento com boleto */
function app_payment_boleto($amount, DateTime $dueAt, $billetId, $bank = '001')
{
    $paymentRequest = new CreatePaymentRequest();
    $paymentRequest->paymentMethod = 'boleto';
    $paymentRequest->amount = app_convert_amount($amount);

    $mundipaggBillet = new CreateBoletoPaymentRequest();
    $mundipaggBillet->instructions = 'Não receber após o vencimento. Pagável em qualquer banco.';
    $mundipaggBillet->dueAt = $dueAt;
    $mundipaggBillet->documentNumber = '9100002';
    $mundipaggBillet->type = 'DM';
    $mundipaggBillet->bank = $bank;
    if ($bank == '001') {
        $mundipaggBillet->nossoNumero = '0002802585' . str_pad($billetId, 10, 0, STR_PAD_LEFT);
    }

    $paymentRequest->boleto = $mundipaggBillet;
    $paymentRequest->metadata = array('billet_code' => $billetId);

    return $paymentRequest;
}

/** Pagamento com Pix */
function app_payment_pix($amount)
{
    $paymentRequest = new CreatePaymentRequest();
    $paymentRequest->paymentMethod = strtolower('PIX');
    $paymentRequest->amount = app_convert_amount($amount);

    $createPixPaymentRequest = new CreatePixPaymentRequest();
    $createPixPaymentRequest->expiresIn = 30 * 60;
    $paymentRequest->pix = $createPixPaymentRequest;

    return $paymentRequest;
}

/** Pagamento com SafetyPay */
function app_payment_safetypay($amount)
{
    $paymentRequest = new CreatePaymentRequest();
    $paymentRequest->paymentMethod = 'safetypay';
    $paymentRequest->amount = app_convert_amount($amount);

    return $paymentRequest;
}

/**
 * Empacota a resposta com o que o codigo cliente faz com ela: le propriedades,
 * faz cast para array e serializa em JSON.
 */
function app_seen($response)
{
    return array(
        'objeto' => $response,
        'json'   => json_encode($response),
    );
}

$scenarios = array();

// ---------------------------------------------------------------------------
// Fluxos de sucesso
// ---------------------------------------------------------------------------

$scenarios['criar_pedido_cartao'] = function (HarnessContext $t) {
    // pedido com cartao de credito
    app_create_instance($t);
    $t->respondFixture(200, 'order_credit_card.json');
    $request = app_order_request(9100001, 1549.90, array(app_payment_credit_card()));

    return app_seen((new OrdersController())->createOrder($request));
};

$scenarios['criar_pedido_boleto'] = function (HarnessContext $t) {
    // pedido com boleto
    app_create_instance($t);
    $t->respondFixture(200, 'order_boleto.json');
    $dueAt = new DateTime('2027-03-20 23:59:59', new DateTimeZone('America/Sao_Paulo'));
    $request = app_order_request(9100002, 899.00, array(app_payment_boleto(899.00, $dueAt, 777001)), 'Maria Antônia de Sá');
    $response = (new OrdersController())->createOrder($request);

    // o SDK converte o DateTime recebido para UTC ao serializar; quem chama pode reutilizar o objeto
    return app_seen($response) + array('dueAt_depois_da_chamada' => $dueAt);
};

$scenarios['criar_pedido_boleto_vencimento_no_antigo_horario_de_verao'] = function (HarnessContext $t) {
    // Mesmo fluxo do boleto, com vencimento em novembro no fuso America/Sao_Paulo. O horario de verao
    // brasileiro acabou em 2019: um PHP com base de fusos anterior a isso ainda aplica UTC-2 e envia
    // o due_at uma hora antes. A diferenca vem da base de fusos do PHP, nao do SDK.
    app_create_instance($t);
    $t->respondFixture(200, 'order_boleto.json');
    $dueAt = new DateTime('2026-11-20 23:59:59', new DateTimeZone('America/Sao_Paulo'));
    $request = app_order_request(9100002, 899.00, array(app_payment_boleto(899.00, $dueAt, 777001)), 'Maria Antônia de Sá');
    (new OrdersController())->createOrder($request);

    return array('dueAt_depois_da_chamada' => $dueAt, 'base_de_fusos_do_php' => timezone_version_get());
};

$scenarios['criar_pedido_pix'] = function (HarnessContext $t) {
    // pedido com Pix
    app_create_instance($t);
    $t->respondFixture(200, 'order_pix.json');
    $request = app_order_request(9100003, 450.50, array(app_payment_pix(450.50)), 'José Müller');

    return app_seen((new OrdersController())->createOrder($request));
};

$scenarios['criar_pedido_safetypay_e_boleto'] = function (HarnessContext $t) {
    // pedido com mais de um pagamento
    app_create_instance($t);
    $t->respondFixture(200, 'order_safetypay.json');
    $dueAt = new DateTime('2027-04-17 23:59:59', new DateTimeZone('America/Sao_Paulo'));
    $request = app_order_request(9100005, 1200.00, array(
        app_payment_safetypay(200.00),
        app_payment_boleto(1000.00, $dueAt, 777005, '237'),
    ), 'Pedro Antônio');

    return app_seen((new OrdersController())->createOrder($request));
};

$scenarios['criar_pedido_com_idempotency_key'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(200, 'order_pix.json');
    $request = app_order_request(9100003, 450.50, array(app_payment_pix(450.50)));

    return app_seen((new OrdersController())->createOrder($request, 'idem-9100003-1'));
};

$scenarios['consultar_pedido'] = function (HarnessContext $t) {
    // consulta de pedido para atualizar o status dos pagamentos
    app_create_instance($t);
    $t->respondFixture(200, 'order_mixed.json');
    $response = (new OrdersController())->getOrder('or_M1xedXnk6T0eU88q');

    // leituras que o codigo cliente faz em cada charge e na ultima transacao
    $lido = array();
    foreach ($response->charges as $charge) {
        $tran = $charge->lastTransaction;
        $lido[] = array(
            'paymentMethod'   => $charge->paymentMethod,
            'status'          => isset($tran->status) ? $tran->status : null,
            'gatewayResponse' => isset($tran->gatewayResponse) ? $tran->gatewayResponse : null,
            'url'             => isset($tran->url) ? $tran->url : null,
            'pdf'             => isset($tran->pdf) ? $tran->pdf : null,
            'line'            => isset($tran->line) ? $tran->line : null,
            'barcode'         => isset($tran->barcode) ? $tran->barcode : null,
            'qrCode'          => isset($tran->qrCode) ? $tran->qrCode : null,
            'qrCodeUrl'       => isset($tran->qrCodeUrl) ? $tran->qrCodeUrl : null,
            'acquirerTid'     => isset($tran->acquirerTid) ? $tran->acquirerTid : null,
            'operationType'   => isset($tran->operationType) ? $tran->operationType : null,
        );
    }

    return app_seen($response) + array('lido_pelo_app' => $lido);
};

$scenarios['consultar_pedido_id_com_caracteres_especiais'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(200, 'order_pix.json');

    return app_seen((new OrdersController())->getOrder('or abc/á?x=1&y=2#z'));
};

$scenarios['consultar_charge_boleto_pago'] = function (HarnessContext $t) {
    // consulta de charge para conferir o pagamento de um boleto
    app_create_instance($t);
    $t->respondFixture(200, 'charge_boleto_paid.json');

    return app_seen((new ChargesController)->getCharge('ch_SafetyBoleto0001'));
};

$scenarios['capturar_charge_cartao'] = function (HarnessContext $t) {
    // CreditCardPayment::capturePayment(): getCharge seguido de captureCharge
    app_create_instance($t);
    $t->respondFixture(200, 'charge_credit_card_pending.json');
    $t->respondFixture(200, 'charge_credit_card_captured.json');
    $consulta = (new ChargesController())->getCharge('ch_K2rJ5nlHwTE4qRDP');
    $captura  = (new ChargesController())->captureCharge('ch_K2rJ5nlHwTE4qRDP');

    return array('consulta' => app_seen($consulta), 'captura' => app_seen($captura));
};

$scenarios['cancelar_charge_cartao'] = function (HarnessContext $t) {
    // cancelamento de cartao: getOrder seguido de cancelCharge
    app_create_instance($t);
    $t->respondFixture(200, 'order_credit_card.json');
    $t->respondFixture(200, 'charge_credit_card_canceled.json');
    $pedido   = (new OrdersController())->getOrder('or_56GXnk6T0eU88qMm');
    $cancelar = (new ChargesController())->cancelCharge('ch_K2rJ5nlHwTE4qRDP');

    return array('pedido' => app_seen($pedido), 'cancelamento' => app_seen($cancelar));
};

$scenarios['cancelar_charge_pix'] = function (HarnessContext $t) {
    // cancelamento de Pix
    app_create_instance($t);
    $t->respondFixture(200, 'charge_pix_canceled.json');

    return app_seen((new ChargesController())->cancelCharge('ch_Pix0000000000001'));
};

$scenarios['criar_charge_boleto_em_pedido_existente'] = function (HarnessContext $t) {
    // novo boleto num pedido que ja existe: createCharge() com order_id
    app_create_instance($t);
    $t->respondFixture(200, 'charge_boleto_created.json');
    $dueAt = new DateTime('2027-04-17 23:59:59', new DateTimeZone('America/Sao_Paulo'));

    $chargeRequest = new HarnessChargeRequest();
    $chargeRequest->amount  = 100000;
    $chargeRequest->orderId = 'or_Saf3tyXnk6T0eU88';
    $chargeRequest->code    = 9100005;
    $chargeRequest->payment = app_payment_boleto(1000.00, $dueAt, 777005, '237');

    return app_seen((new ChargesController())->createCharge($chargeRequest));
};

$scenarios['client_e_singletons'] = function (HarnessContext $t) {
    $client = app_create_instance($t);
    $t->respondFixture(200, 'order_pix.json');
    $t->respondFixture(200, 'charge_pix_canceled.json');
    $pedido = $client->getOrders()->getOrder('or_P1xXnk6T0eU88qMm');
    $charge = $client->getCharges()->getCharge('ch_Pix0000000000001');

    return array(
        'pedido' => app_seen($pedido),
        'charge' => app_seen($charge),
        'getOrders_e_singleton'  => $client->getOrders() === OrdersController::getInstance(),
        'getCharges_e_singleton' => $client->getCharges() === ChargesController::getInstance(),
        'classes' => array(get_class($client->getOrders()), get_class($client->getCharges())),
    );
};

$scenarios['troca_de_credenciais_entre_lojas'] = function (HarnessContext $t) {
    // credenciais de lojas diferentes trocadas no mesmo processo
    $t->respondFixture(200, 'order_pix.json');
    $t->respondFixture(200, 'order_pix.json');
    $t->respondFixture(200, 'order_pix.json');

    app_create_instance($t, 'sk_test_harness_loja_a', 'senha-loja-a');
    $a = (new OrdersController())->getOrder('or_loja_a');
    app_create_instance($t, 'sk_test_harness_loja_b', 'senha-loja-b');
    $b = (new OrdersController())->getOrder('or_loja_b');
    // senha vazia: o construtor do client mantem a senha anterior da Configuration
    Configuration::$basicAuthUserName = 'sk_test_harness_loja_c';
    new MundiAPIClient(Configuration::$basicAuthUserName, '', null);
    $c = (new OrdersController())->getOrder('or_loja_c');

    return array($a->id, $b->id, $c->id, Configuration::$basicAuthUserName, Configuration::$basicAuthPassword);
};

$scenarios['proxy_configurado'] = function (HarnessContext $t) {
    // client criado com proxy: o servidor falso faz o papel do proxy
    $proxy = array(
        'host' => 'http://' . $t->fakeHost,
        'user' => 'usuario-proxy',
        'pass' => 'senha-proxy',
        'port' => (string) $t->fakePort,
    );
    $t->respondFixture(200, 'order_pix.json');
    $t->respondFixture(200, 'charge_pix_canceled.json');

    app_create_instance($t, 'sk_test_harness_loja_a', 'senha-loja-a', $proxy);
    Configuration::$BASEURI = 'http://api.mundipagg.test/core/v1';
    $pedido = (new OrdersController())->getOrder('or_P1xXnk6T0eU88qMm');

    // uma segunda instancia sem proxy nao desfaz o proxy ja configurado no processo
    new MundiAPIClient(Configuration::$basicAuthUserName, Configuration::$basicAuthPassword, null);
    $charge = (new ChargesController())->cancelCharge('ch_Pix0000000000001');

    return array('pedido' => $pedido->id, 'charge' => $charge->id);
};

$scenarios['http_callback'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(200, 'order_pix.json');
    $visto = array();
    $callback = new HttpCallBack(
        function ($request) use (&$visto) {
            $visto[] = array('antes', get_class($request), $request->getHttpMethod(), $request->getQueryUrl(), $request->getHeaders());
        },
        function ($context) use (&$visto) {
            $visto[] = array('depois', get_class($context), $context->getResponse()->getStatusCode(), strlen($context->getResponse()->getRawBody()));
        }
    );
    $controller = new OrdersController();
    $controller->setHttpCallBack($callback);
    $pedido = $controller->getOrder('or_P1xXnk6T0eU88qMm');

    $semArgumentos = new HttpCallBack();

    return array(
        'pedido' => $pedido->id,
        'visto'  => $visto,
        'getHttpCallBack' => $controller->getHttpCallBack() === $callback,
        'sem_argumentos'  => array($semArgumentos->getOnBeforeRequest(), $semArgumentos->getOnAfterRequest()),
    );
};

// ---------------------------------------------------------------------------
// Respostas 2xx fora do esperado
// ---------------------------------------------------------------------------

$scenarios['resposta_200_objeto_vazio'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, '{}');

    return app_seen((new OrdersController())->getOrder('or_vazio'));
};

$scenarios['resposta_200_null'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, 'null');

    return app_seen((new OrdersController())->getOrder('or_null'));
};

$scenarios['resposta_200_corpo_nao_json'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, '<html><body>ok</body></html>', array('Content-Type' => 'text/html'));

    return app_seen((new OrdersController())->getOrder('or_html'));
};

$scenarios['resposta_200_lista'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, '[{"id":"or_1"}]');

    return app_seen((new OrdersController())->getOrder('or_lista'));
};

$scenarios['resposta_204_sem_corpo'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(204, '');

    return app_seen((new ChargesController())->cancelCharge('ch_204'));
};

$scenarios['resposta_201_criado'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(201, 'order_pix.json');
    $request = app_order_request(9100003, 450.50, array(app_payment_pix(450.50)));

    return app_seen((new OrdersController())->createOrder($request));
};

$scenarios['resposta_302_redirecionada'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(302, '', array('Location' => $t->baseUri . '/orders/or_destino'));
    $t->respondFixture(200, 'order_pix.json');

    return app_seen((new OrdersController())->getOrder('or_origem'));
};

$scenarios['resposta_nulos_em_campos_obrigatorios'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, json_encode(array(
        'id' => null, 'code' => null, 'currency' => null, 'items' => null, 'customer' => null,
        'status' => null, 'created_at' => null, 'updated_at' => null, 'charges' => null,
        'invoice_url' => null, 'shipping' => null, 'metadata' => null, 'checkouts' => null,
        'ip' => null, 'session_id' => null, 'location' => null, 'device' => null, 'closed' => null,
    )));

    return app_seen((new OrdersController())->getOrder('or_nulos'));
};

$scenarios['resposta_charge_nulos_em_campos_obrigatorios'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(200, json_encode(array(
        'id' => 'ch_nulos', 'code' => null, 'gateway_id' => null, 'amount' => null, 'status' => null,
        'currency' => null, 'payment_method' => null, 'due_at' => null, 'created_at' => null,
        'updated_at' => null, 'metadata' => null, 'canceled_amount' => null, 'paid_amount' => null,
        'last_transaction' => array(
            'transaction_type' => 'boleto', 'id' => null, 'gateway_id' => null, 'amount' => null,
            'status' => null, 'success' => null, 'created_at' => null, 'updated_at' => null,
            'attempt_count' => null, 'max_attempts' => null, 'splits' => null, 'split' => null,
            'gateway_response' => null, 'antifraud_response' => null, 'url' => null, 'barcode' => null,
            'nosso_numero' => null, 'bank' => null, 'document_number' => null, 'instructions' => null,
            'billing_address' => null, 'qr_code' => null, 'line' => null, 'pdf_password' => null,
            'pdf' => null, 'paid_amount' => null, 'type' => null,
        ),
    )));

    return app_seen((new ChargesController())->getCharge('ch_nulos'));
};

$scenarios['resposta_tipos_numericos_trocados'] = function (HarnessContext $t) {
    app_create_instance($t);
    $charge = $t->fixtureObject('charge_credit_card_captured.json');
    $charge->id = 12345;
    $charge->amount = '154990';
    $charge->paid_amount = 1549.9;
    $charge->canceled_amount = true;
    $charge->code = 9100001;
    $charge->last_transaction->success = 'false';
    $charge->last_transaction->installments = '3';
    $charge->last_transaction->amount = 154990.0;
    $charge->last_transaction->acquirer_return_code = 0;
    $charge->last_transaction->card->exp_month = '07';
    $charge->last_transaction->card->last_four_digits = 10;
    $t->respond(200, json_encode($charge));

    return app_seen((new ChargesController())->getCharge('ch_tipos'));
};

$scenarios['resposta_string_onde_espera_objeto'] = function (HarnessContext $t) {
    app_create_instance($t);
    $charge = $t->fixtureObject('charge_credit_card_captured.json');
    $charge->customer = 'cus_4lW3bDXi0KT9Qzmo';
    $charge->order = 'or_56GXnk6T0eU88qMm';
    $t->respond(200, json_encode($charge));

    return app_seen((new ChargesController())->getCharge('ch_string_obj'));
};

$scenarios['resposta_lista_onde_espera_objeto'] = function (HarnessContext $t) {
    app_create_instance($t);
    $charge = $t->fixtureObject('charge_credit_card_captured.json');
    $charge->customer = array();
    $t->respond(200, json_encode($charge));

    return app_seen((new ChargesController())->getCharge('ch_lista_obj'));
};

$scenarios['resposta_objeto_onde_espera_lista'] = function (HarnessContext $t) {
    app_create_instance($t);
    $order = $t->fixtureObject('order_pix.json');
    $order->items = array('primeiro' => $order->items[0]);
    $t->respond(200, json_encode($order));

    return app_seen((new OrdersController())->getOrder('or_obj_lista'));
};

$scenarios['resposta_string_onde_espera_lista'] = function (HarnessContext $t) {
    app_create_instance($t);
    $order = $t->fixtureObject('order_pix.json');
    $order->charges = 'nenhuma';
    $t->respond(200, json_encode($order));

    return app_seen((new OrdersController())->getOrder('or_str_lista'));
};

$scenarios['resposta_transacao_como_string'] = function (HarnessContext $t) {
    app_create_instance($t);
    $charge = $t->fixtureObject('charge_credit_card_captured.json');
    $charge->last_transaction = 'tran_Capture00000001';
    $t->respond(200, json_encode($charge));

    return app_seen((new ChargesController())->getCharge('ch_tran_string'));
};

foreach (array(
    'sete_digitos'    => '2026-11-18T16:50:00.1234567Z',
    'sem_fuso'        => '2026-11-18T16:50:00',
    'offset_positivo' => '2026-11-18T19:50:00+03:00',
    'offset_negativo' => '2026-11-18T13:50:00-03:00',
    'so_data'         => '2026-11-18',
    'texto_invalido'  => 'ontem',
    'vazia'           => '',
    'numero'          => 1795020600,
) as $nome => $valor) {
    $scenarios['resposta_data_' . $nome] = function (HarnessContext $t) use ($valor) {
        app_create_instance($t);
        $charge = $t->fixtureObject('charge_credit_card_captured.json');
        $charge->paid_at = $valor;
        $t->respond(200, json_encode($charge));

        return app_seen((new ChargesController())->getCharge('ch_data'));
    };
}

// ---------------------------------------------------------------------------
// Erros HTTP devolvidos pela API
// ---------------------------------------------------------------------------

$scenarios['erro_400_criar_pedido'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(400, 'error_400.json');
    $request = app_order_request(9100001, 1549.90, array(app_payment_credit_card()));

    return (new OrdersController())->createOrder($request);
};

$scenarios['erro_401_credencial_invalida'] = function (HarnessContext $t) {
    app_create_instance($t, 'sk_test_harness_invalida', 'x');
    $t->respondFixture(401, 'error_401.json');

    return (new OrdersController())->getOrder('or_56GXnk6T0eU88qMm');
};

$scenarios['erro_404_pedido_inexistente'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(404, 'error_404.json');

    return (new OrdersController())->getOrder('or_nao_existe');
};

$scenarios['erro_404_charge_inexistente'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(404, 'error_404.json');

    return (new ChargesController())->getCharge('ch_nao_existe');
};

$scenarios['erro_412_captura_negada'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(412, 'error_412.json');

    return (new ChargesController())->captureCharge('ch_K2rJ5nlHwTE4qRDP');
};

$scenarios['erro_412_cancelamento_negado'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(412, 'error_412.json');

    return (new ChargesController())->cancelCharge('ch_K2rJ5nlHwTE4qRDP');
};

$scenarios['erro_422_criar_charge'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(422, 'error_422.json');
    $dueAt = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
    $chargeRequest = new HarnessChargeRequest();
    $chargeRequest->amount  = 100000;
    $chargeRequest->orderId = 'or_Saf3tyXnk6T0eU88';
    $chargeRequest->code    = 9100005;
    $chargeRequest->payment = app_payment_boleto(1000.00, $dueAt, 777005, '237');

    return (new ChargesController())->createCharge($chargeRequest);
};

$scenarios['erro_500_json'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(500, 'error_500.json');
    $request = app_order_request(9100003, 450.50, array(app_payment_pix(450.50)));

    return (new OrdersController())->createOrder($request);
};

$scenarios['erro_500_html'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(500, '<html><body><h1>500 Internal Server Error</h1></body></html>', array('Content-Type' => 'text/html'));

    return (new OrdersController())->getOrder('or_500_html');
};

$scenarios['erro_500_sem_corpo'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(500, '');

    return (new OrdersController())->getOrder('or_500_vazio');
};

$scenarios['erro_400_corpo_lista'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(400, '["erro"]');

    return (new OrdersController())->getOrder('or_400_lista');
};

$scenarios['erro_400_corpo_string'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(400, '"erro em texto"');

    return (new OrdersController())->getOrder('or_400_string');
};

$scenarios['erro_400_message_nulo_e_campos_extras'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(400, json_encode(array(
        'message' => null,
        'errors'  => array('campo' => array('mensagem')),
        'request' => array('code' => 1),
        'trace_id' => 'abc-123',
        'code' => 99,
    )));

    return (new OrdersController())->getOrder('or_400_extras');
};

$scenarios['erro_400_errors_como_lista'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(400, json_encode(array(
        'message' => 'The request is invalid.',
        'errors'  => array('primeiro erro', 'segundo erro'),
        'request' => 'texto',
    )));

    return (new OrdersController())->getOrder('or_400_errors_lista');
};

$scenarios['erro_403_proibido'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(403, '{"message":"Forbidden"}');

    return (new OrdersController())->getOrder('or_403');
};

$scenarios['erro_429_limite'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(429, '{"message":"Too Many Requests"}', array('Content-Type' => 'application/json'));

    return (new ChargesController())->getCharge('ch_429');
};

$scenarios['erro_502_html'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respondFixture(502, 'error_502.html', array('Content-Type' => 'text/html'));
    $request = app_order_request(9100001, 1549.90, array(app_payment_credit_card()));

    return (new OrdersController())->createOrder($request);
};

$scenarios['erro_503_indisponivel'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(503, 'Service Unavailable', array('Content-Type' => 'text/plain'));

    return (new ChargesController())->captureCharge('ch_503');
};

$scenarios['erro_504_gateway_timeout'] = function (HarnessContext $t) {
    app_create_instance($t);
    $t->respond(504, '');

    return (new ChargesController())->getCharge('ch_504');
};

// ---------------------------------------------------------------------------
// Falhas de rede
// ---------------------------------------------------------------------------

$scenarios['rede_conexao_recusada'] = function (HarnessContext $t) {
    app_create_instance($t);
    Configuration::$BASEURI = $t->closedUri;
    $request = app_order_request(9100001, 1549.90, array(app_payment_credit_card()));

    return (new OrdersController())->createOrder($request);
};

$scenarios['rede_conexao_fechada_sem_resposta'] = function (HarnessContext $t) {
    app_create_instance($t);
    Configuration::$BASEURI = $t->dropUri;

    return (new OrdersController())->getOrder('or_drop');
};

$scenarios['rede_timeout'] = function (HarnessContext $t) {
    // o SDK nao define timeout por padrao; aqui ele e definido direto no Unirest, unico meio que o SDK oferece
    app_create_instance($t);
    Configuration::$BASEURI = $t->hangUri;
    \Unirest\Request::timeout(1);

    return (new ChargesController())->getCharge('ch_timeout');
};

$scenarios['rede_url_base_invalida'] = function (HarnessContext $t) {
    app_create_instance($t);
    Configuration::$BASEURI = 'api.mundipagg.test/core/v1';

    return (new OrdersController())->getOrder('or_url_invalida');
};

// ---------------------------------------------------------------------------
// Partes do SDK fora do fluxo de pedidos e charges, mas que foram tocadas na adequacao
// ---------------------------------------------------------------------------

$scenarios['utilitarios_datetimehelper'] = function (HarnessContext $t) {
    $h = 'MundiAPILib\\Utils\\DateTimeHelper';
    $a = new DateTime('2027-03-20T10:11:12Z');
    $b = new DateTime('2027-04-17T23:59:59-03:00');
    $soData = function ($datas) {
        // fromSimpleDate completa a hora com o relogio; so a data e deterministica
        return array_map(function ($d) {
            return $d->format('Y-m-d');
        }, $datas);
    };

    return array(
        'toSimpleDateArray'        => $h::toSimpleDateArray(array(clone $a, clone $b)),
        'fromSimpleDateArray'      => $soData($h::fromSimpleDateArray(array('2027-03-20', '2027-04-17'))),
        'toRfc1123DateTimeArray'   => $h::toRfc1123DateTimeArray(array(clone $a, clone $b)),
        'fromRfc1123DateTimeArray' => $h::fromRfc1123DateTimeArray(array('Sat, 20 Mar 2027 10:11:12 +0000')),
        'toRfc3339DateTimeArray'   => $h::toRfc3339DateTimeArray(array(clone $a, clone $b)),
        'fromRfc3339DateTimeArray' => $h::fromRfc3339DateTimeArray(array('2027-03-20T10:11:12Z', '2027-04-17T23:59:59.123456Z')),
        'toUnixTimestampArray'     => $h::toUnixTimestampArray(array(clone $a, clone $b)),
        'fromUnixTimestampArray'   => $h::fromUnixTimestampArray(array(1805537472, '1807930799')),
        'nulos'                    => array(
            $h::toSimpleDateArray(null), $h::fromSimpleDateArray(null), $h::toRfc1123DateTimeArray(null),
            $h::fromRfc1123DateTimeArray(null), $h::toRfc3339DateTimeArray(null), $h::fromRfc3339DateTimeArray(null),
            $h::toUnixTimestampArray(null), $h::fromUnixTimestampArray(null),
        ),
    );
};

$scenarios['todas_as_classes_carregam_e_serializam'] = function (HarnessContext $t) {
    // carrega os arquivos de src/ um a um; todo aviso de compilacao ou de heranca aparece aqui
    $src = dirname((new ReflectionClass('MundiAPILib\\Configuration'))->getFileName());
    $carregadas = 0;
    $serializadas = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    $arquivos = array();
    foreach ($iterator as $file) {
        if (substr($file->getFilename(), -4) === '.php') {
            $arquivos[] = $file->getPathname();
        }
    }
    sort($arquivos);
    foreach ($arquivos as $path) {
        $class = 'MundiAPILib\\' . str_replace('/', '\\', substr($path, strlen($src) + 1, -4));
        if (!class_exists($class)) {
            throw new Exception('classe nao carregou: ' . $class);
        }
        $carregadas++;
        if (strpos($class, 'MundiAPILib\\Models\\') === 0) {
            $model = new $class();
            if (json_encode($model) === false) {
                throw new Exception('model nao serializou: ' . $class);
            }
            // os models sempre aceitaram propriedades fora da declaracao
            $model->campo_que_nao_existe = 1;
            $serializadas++;
        }
    }

    return array('classes_carregadas' => $carregadas, 'models_serializados' => $serializadas);
};

$scenarios['docblocks_lidos_pelo_jsonmapper'] = function (HarnessContext $t) {
    // o JsonMapper le @discriminator, @maps, @var e @factory por reflexao
    $out = array();
    foreach (array('GetTransactionResponse', 'GetPixTransactionResponse', 'GetBoletoTransactionResponse', 'CreateOrderRequest') as $name) {
        $rc = new ReflectionClass('MundiAPILib\\Models\\' . $name);
        $out[$name] = array(
            'classe'        => $rc->getDocComment(),
            'jsonSerialize' => $rc->getMethod('jsonSerialize')->getDocComment(),
            'construtor'    => $rc->getMethod('__construct')->getNumberOfParameters(),
        );
    }
    $rp = new ReflectionProperty('MundiAPILib\\Models\\GetChargeResponse', 'lastTransaction');
    $out['GetChargeResponse::$lastTransaction'] = $rp->getDocComment();

    return $out;
};

$scenarios['contrato_http_request_e_callback'] = function (HarnessContext $t) {
    $vazio = new \MundiAPILib\Http\HttpRequest();
    $cheio = new \MundiAPILib\Http\HttpRequest('Post', array('a' => 'b'), 'http://exemplo.test/x', array('p' => 1));
    $cheio->setHeaders(array('c' => 'd'));
    $callback = new HttpCallBack('strtoupper', null);
    $callback->setOnAfterRequest('strtolower');

    return array(
        'vazio' => array($vazio->getHttpMethod(), $vazio->getHeaders(), $vazio->getQueryUrl(), $vazio->getParameters()),
        'cheio' => array($cheio->getHttpMethod(), $cheio->getHeaders(), $cheio->getQueryUrl(), $cheio->getParameters()),
        'callback' => array($callback->getOnBeforeRequest(), $callback->getOnAfterRequest()),
    );
};

$scenarios['contrato_http_request_argumento_invalido'] = function (HarnessContext $t) {
    return new \MundiAPILib\Http\HttpRequest('Get', 'cabecalhos-em-texto');
};

$scenarios['contrato_http_callback_argumento_invalido'] = function (HarnessContext $t) {
    return new HttpCallBack('funcao_que_nao_existe');
};

return $scenarios + require __DIR__ . '/scenarios-full-surface.php';
