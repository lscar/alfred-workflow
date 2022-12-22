<?php

require 'vendor/autoload.php';

use Alfred\Workflows\Workflow;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$requestOptions = [
    'timeout' => 5,
    'proxy'   => [
        'http'  => '127.0.0.1:7890',
        'https' => '127.0.0.1:7890',
    ],
];
$requestUrls = [
    'latest' => 'https://v2ex.com/api/topics/latest.json',
    'user'   => 'https://v2ex.com/api/topics/show.json', //query [username]
];
$avatarRoot = 'picture';
$logRoot = 'log';

$client = new Client();
$workflow = new Workflow();
$logFileName = sprintf('%s/%s.log', $logRoot, date('Ymd'));
$logHandler = new StreamHandler($logFileName, Level::Error);
$logger = new Logger('ExceptionLog', [$logHandler]);
$input = "{query}"; // replace by Alfred

function loadData(string $api, array $query = []): string
{
    global $requestOptions, $client, $logger;
    try {
        $response = $client->get($api, [
            RequestOptions::TIMEOUT => $requestOptions['timeout'],
            RequestOptions::PROXY   => $requestOptions['proxy'],
            RequestOptions::QUERY   => $query,
        ]);
    } catch (GuzzleException $e) {
        $logger->error($e->getMessage(), $e->getTrace());
        return '';
    }

    return $response->getStatusCode() == 200 ? $response->getBody() : '';
}

function loadAvatar(array $avatars): void
{
    global $requestOptions, $avatarRoot, $client;
    $requests = function () use ($client, $avatars, $requestOptions, $avatarRoot) {
        foreach ($avatars as $avatarUrl) {
            $filePath = $avatarRoot . parse_url($avatarUrl, PHP_URL_PATH);
            if (!is_file($filePath)) {
                $dirName = pathinfo($filePath, PATHINFO_DIRNAME);
                if (!is_dir($dirName)) {
                    @mkdir($dirName, 0777, true);
                }
                $avatarRequestOptions = [
                    RequestOptions::SINK    => $filePath,
                    RequestOptions::TIMEOUT => $requestOptions['timeout'],
                    RequestOptions::PROXY   => $requestOptions['proxy'],
                ];
                yield function () use ($client, $avatarUrl, $avatarRequestOptions) {
                    return $client->getAsync($avatarUrl, $avatarRequestOptions);
                };
            }
        }
    };

    $pool = new Pool($client, $requests()); // concurrency default 25
    $promise = $pool->promise();
    $promise->wait();
}

if ($input == 'n' || $input == 'new') {
    $response = loadData($requestUrls['latest']);
} else if (strpos($input, '@') == 0) {
    $response = loadData($requestUrls['user'], ['username' => substr($input, 1)]);
} else {
    $response = '';
}
$news = json_decode($response);

if (empty($news)) {
    $workflow->item()
        ->uid('v2ex_500')
        ->arg('请确保指令和网络链接正常')
        ->title('没有响应，请再试一次。')
        ->subtitle('请确保指令和网络链接正常')
        ->icon('icon.png');
} else {
    $avatarUrls = [];
    foreach ($news as $post) {
        try {
            $workflow->item()
                ->uid($post->id)
                ->arg($post->url)
                ->title(sprintf('%s - @%s', $post->title, $post->member->username))
                ->subtitle($post->content)
                ->icon($avatarRoot . parse_url($post->member->avatar_normal, PHP_URL_PATH));
        } catch (Exception $e) {
            $logger->error($e->getMessage(), $e->getTrace());
        }
        $avatarUrls[] = $post->member->avatar_normal;
    }
    loadAvatar($avatarUrls);
}

try {
    $workflow->output();
} catch (Exception $e) {
    echo '';
}