

<?php
require_once('../../../wp-load.php');
header('Content-Type: application/json');

$response = wp_remote_get("https://v3.football.api-sports.io/fixtures?league=71&from=2025-07-19", [
    'headers' => [
        'x-apisports-key' => get_option('wpfp_api_key'),
        'x-rapidapi-host' => get_option('wpfp_api_host')
    ]
]);

$body = wp_remote_retrieve_body($response);
echo $body;
