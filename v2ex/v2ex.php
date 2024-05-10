<?php

require 'vendor/autoload.php';

use Alfred\Workflows\Workflow;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$client = new Client();
$workflow = new Workflow();
$cache = new FilesystemAdapter(directory: __DIR__);
$input = "{query}"; // replace by Alfred

function loadData(string $api, array $query = []): string
{
    global $cache, $client;
    return $cache->get(md5($api), function (ItemInterface $item) use($api, $query, $client): string {
        $response = $client->get($api, [
            RequestOptions::TIMEOUT => (int)$_ENV['HTTP_TIMEOUT'],
            RequestOptions::PROXY   => [
                'http' => $_ENV['HTTP_PROXY'],
                'https' => $_ENV['HTTP_PROXY_SSL'],
            ],
            RequestOptions::QUERY   => $query,
        ]);
        $success = $response->getStatusCode() == 200;
        $item->expiresAfter($success ? (int)$_ENV['CACHE_TIME'] : 15);
        return $success ? $response->getBody() : '';
    });
}

function loadAvatar(array $avatars): void
{
    global $client;
    $requests = function () use ($client, $avatars) {
        foreach ($avatars as $avatar) {
            $file = $_ENV['CACHE_DIR'] . parse_url($avatar, PHP_URL_PATH);
            if (!is_file($file)) {
                $dirName = pathinfo($file, PATHINFO_DIRNAME);
                if (!is_dir($dirName)) {
                    @mkdir($dirName, 0777, true);
                }
                $options = [
                    RequestOptions::SINK    => $file,
                    RequestOptions::TIMEOUT => (int)$_ENV['HTTP_TIMEOUT'],
                    RequestOptions::PROXY   =>  [
                        'http' => $_ENV['HTTP_PROXY'],
                        'https' => $_ENV['HTTP_PROXY_SSL'],
                    ],
                ];
                yield function () use ($client, $avatar, $options) {
                    return $client->getAsync($avatar, $options);
                };
            }
        }
    };

    $pool = new Pool($client, $requests()); // concurrency default 25
    $promise = $pool->promise();
    $promise->wait();
}

if ($input == 'n' || $input == 'new') {
    $response = loadData($_ENV['V2EX_LATEST']);
} else if (strpos($input, '@') == 0) {
    $response = loadData($_ENV['V2EX_USER'], ['username' => substr($input, 1)]);
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
    $avatars = [];
    foreach ($news as $post) {
        $workflow->item()
            ->uid($post->id)
            ->arg($post->url)
            ->title(sprintf('%s - @%s', $post->title, $post->member->username))
            ->subtitle($post->content)
            ->icon($_ENV['CACHE_DIR'] . parse_url($post->member->avatar_normal, PHP_URL_PATH));

        $avatars[] = $post->member->avatar_normal;
    }
    loadAvatar($avatars);
}

$workflow->output();