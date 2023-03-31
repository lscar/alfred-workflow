<?php

require '../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;

$file = file_get_contents('https://www.worldweatheronline.com/feed/wwoConditionCodes.txt');
//$file = file_get_contents('wwoConditionCodes.txt');
$data = explode(PHP_EOL, $file);
array_shift($data);
array_pop($data);
$icons = [];
foreach ($data as $row) {
    $column = explode("\t", $row);
    $icons[$column[0]] = [
        'day'   => trim($column[2]) . '.png',
        'night' => trim($column[3]) . '.png',
    ];
}

$client = new Client(['base_uri' => 'https://cdn.worldweatheronline.com/images/wsymbols01_png_64/']);
$requests = function () use ($client, $icons) {
    foreach ($icons as $code => $icon) {
        foreach ($icon as $type => $uri) {
            $fileName = sprintf('%s/%s/%s', 'icon', $code, $type . '.png');
            $dirName = pathinfo($fileName, PATHINFO_DIRNAME);
            if (!is_dir($dirName)) {
                @mkdir($dirName, 0777, true);
            }
            $options = [
                RequestOptions::SINK    => $fileName,
                RequestOptions::TIMEOUT => 5,
            ];
            yield function () use ($client, $uri, $options) {
                return $client->getAsync($uri, $options);
            };
        }
    }
};
$pool = new Pool($client, $requests()); // concurrency default 25
$promise = $pool->promise();
$promise->wait();