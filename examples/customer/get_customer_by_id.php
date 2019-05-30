<?php

$secretKey = 'YOUR SECRET KEY'; //the secret key will be provided by MundiPagg.
$basicAuthPassword = ''; //fill it with an empty string

$apiClient = new \MundiAPILib\MundiAPIClient(
    $secretKey,
    $basicAuthPassword
);

$customersController = $apiClient->getCustomers();

$customerId = "cus_ExAmPlExxxxxxxxx";

$result = $customersController->getCustomer($customerId);

return $result;
