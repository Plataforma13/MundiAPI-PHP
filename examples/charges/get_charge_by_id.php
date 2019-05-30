<?php

$secretKey = 'YOUR SECRET KEY'; //the secret key will be provided by MundiPagg.
$basicAuthPassword = ''; //fill it with an empty string

$apiClient = new \MundiAPILib\MundiAPIClient(
    $secretKey,
    $basicAuthPassword
);

$chargesController = $apiClient->getCharges();

$chargeId = "ch_ExAmPlExxxxxxxxx";

$result = $chargesController->getCharge($chargeId);

return $result;
