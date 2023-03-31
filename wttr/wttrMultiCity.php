<?php

require '../vendor/autoload.php';

use Alfred\Workflows\Workflow;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

$apiHost = 'https://wttr.in/';
$cacheTime = 600;

function cleanCache(): void
{
    global $cacheTime;
    $list = scandir('cache');
    foreach ($list as $file) {
        if (empty(str_replace('.', '', $file))) {
            continue;
        }
        $filePath = 'cache/' . $file;
        if ($cacheTime < (time() - filemtime($filePath))) {
            @unlink($filePath);
        }
    }
}

function loadData(array $locations): void
{
    global $apiHost, $cacheTime;
    $client = new Client(['base_uri' => $apiHost]);
    $requests = function () use ($client, $locations, $cacheTime) {
        foreach ($locations as $location) {
            if (file_exists($location) && $cacheTime > (time() - filemtime($location))) {
                continue;
            }
            $options = [
                RequestOptions::TIMEOUT => 3,
                RequestOptions::QUERY   => [
                    'format' => 'j1',
                    'lang'   => 'zh-cn',
                ],
            ];
            yield function () use ($client, $location, $options) {
                return $client->getAsync($location, $options)
                    ->then(function (Response $response) use ($location) {
                        $data = json_encode(json_decode(trim(
                            $response->getBody()->getContents()
                        )));
                        file_put_contents('cache/' . $location, $data);
                    });
            };
        }
    };

    $pool = new Pool($client, $requests()); // concurrency default 25
    $promise = $pool->promise();
    $promise->wait();
}

$cities = explode(' ', trim($argv[1]));
foreach ($cities as $key => $city) {
    if (strlen($city) <= 1) {
        unset($cities[$key]);
    }
}
$workflow = new Workflow();

loadData($cities);
cleanCache();
foreach ($cities as $city) {
    $cityData = file_get_contents('cache/' . $city);
    if (empty($cityData)) {
        continue;
    }
    $cityData = json_decode($cityData);

    $currentCondition = $cityData->current_condition[0] ?? new stdClass();
    $forecastCondition = $cityData->weather;
    $title = sprintf("%s - %s\t气温：%s°C\t湿度：%s%%\t紫外线：%s",
        $city,
        $currentCondition->{"lang_zh-cn"}[0]->value ?? '未知',
        $currentCondition->temp_C ?? '未知',
        $currentCondition->humidity ?? '未知',
        $currentCondition->uvIndex ?? '未知'
    );
    $content = sprintf("%s:%s°C~%s°C\t%s:%s°C~%s°C\t%s:%s°C~%s°C",
        $forecastCondition[0]->date ?? '未知',
        $forecastCondition[0]->mintempC ?? '未知',
        $forecastCondition[0]->maxtempC ?? '未知',
        $forecastCondition[1]->date ?? '未知',
        $forecastCondition[1]->mintempC ?? '未知',
        $forecastCondition[1]->maxtempC ?? '未知',
        $forecastCondition[2]->date ?? '未知',
        $forecastCondition[2]->mintempC ?? '未知',
        $forecastCondition[2]->maxtempC ?? '未知',
    );
    $currentHour = date('G');
    $isNight = $currentHour > 18 || $currentHour < 6;
    $icon = sprintf('icon/%s/%s',
        $currentCondition->weatherCode,
        $isNight ? 'night.png' : 'day.png'
    );
    $workflow->item()
        ->uid($city)
        ->arg(sprintf('%s%s?lang=zh-cn', $apiHost, $city))
        ->title($title)
        ->subtitle($content)
        ->icon($icon);
}

if (empty($workflow->items()->all())) {
    $workflow->item()
        ->uid('wrrt_500')
        ->arg('请确保指令和网络链接正常')
        ->title('没有响应，请再试一次。')
        ->subtitle('请确保指令和网络链接正常')
        ->icon('icon.png');
}

$workflow->output();