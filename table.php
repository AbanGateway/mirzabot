<?php

require_once __DIR__ . '/db/bootstrap.php';

global $domainhosts;

telegram('setwebhook', [
    'url' => "https://$domainhosts/index.php"
]);
