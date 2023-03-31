<?php

require '../vendor/autoload.php';

use Alfred\Workflows\Workflow;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

$apiHost = 'https://wttr.in/';
$cacheTime = 600;
$params = explode(' ', trim($argv[1]));
$city = trim($params[0]);
$type = trim($params[1] ?? '');
$fileName = 'cache/' . $city;

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

function loadData(): void
{
    global $apiHost, $cacheTime, $city, $fileName;
    if (file_exists($fileName) && $cacheTime > (time() - filemtime($fileName))) {
        return;
    }
    $client = new Client(['base_uri' => $apiHost]);
    try {
        $response = $client->get($city, [
            RequestOptions::TIMEOUT => 5,
            RequestOptions::QUERY   => [
                'format' => 'j1',
                'lang'   => 'zh-cn',
            ],
        ]);
    } catch (GuzzleException $e) {
        return;
    }
    $data = $response->getStatusCode() == 200 ? $response->getBody()->getContents() : '';
    if (!empty($data)) {
        file_put_contents($fileName, json_encode(json_decode(trim($data))));
    }
}

function processData(): Workflow
{
    global $type, $cacheTime, $fileName;
    $workflow = new Workflow();
    if (file_exists($fileName) && $cacheTime < (time() - filemtime($fileName))) {
        return $workflow;
    }
    $cityData = file_get_contents($fileName);
    if (empty($cityData)) {
        return $workflow;
    }
    $cityData = json_decode($cityData);

    $currentCondition = $cityData->current_condition[0] ?? new stdClass();
    $forecastCondition = $cityData->weather;

    return match ($type) {
        'now'   => processNow($workflow, $currentCondition),
        '2nd'   => processMultiDay($workflow, $forecastCondition, 2),
        '3rd'   => processMultiDay($workflow, $forecastCondition, 3),
        default => processMultiDay($workflow, $forecastCondition),
    };
}

function processNow(Workflow $workflow, object $currentCondition): Workflow
{
    global $apiHost, $city;
    $title = sprintf("%s[%s] 气温：%s°C 湿度：%s%% 紫外线：%s",
        $city,
        $currentCondition->{"lang_zh-cn"}[0]->value ?? '未知',
        $currentCondition->temp_C ?? '未知',
        $currentCondition->humidity ?? '未知',
        $currentCondition->uvIndex ?? '未知'
    );
    $content = sprintf("体感温度：%s°C 降水：%smm 能见度：%skm 风速：%skm/h",
        $currentCondition->FeelsLikeC ?? '未知',
        $currentCondition->precipMM ?? '未知',
        $currentCondition->visibility ?? '未知',
        $currentCondition->windspeedKmph ?? '未知',
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

    return $workflow;
}

function processMultiDay(Workflow $workflow, array $forecastCondition, int $day = 1): Workflow
{
    global $apiHost, $city;
    $day -= 1; // 数组key校准
    $currentCondition = $forecastCondition[$day];
    $date = $currentCondition->date;
    $hours = $currentCondition->hourly;
    foreach ($hours as $hour => $weather) {
        $startHour = $hour * 3;
        $endHour = ($hour + 1) * 3;
        $title = sprintf("%s %02d~%02d时 [%s] 气温：%s°C 湿度：%s%% 紫外线：%s",
            $date,
            $startHour,
            $endHour,
            $weather->{"lang_zh-cn"}[0]->value ?? '未知',
            $weather->tempC ?? '未知',
            $weather->humidity ?? '未知',
            $weather->uvIndex ?? '未知'
        );
        $content = sprintf("体感温度：%s°C 降水概率：%s%% 晴天概率：%s%% 雾霾概率：%s%%",
            $weather->FeelsLikeC ?? '未知',
            $weather->chanceofrain ?? '未知',
            $weather->chanceofsunshine ?? '未知',
            $weather->chanceoffog ?? '未知',
        );
        $isNight = $startHour > 18 || $startHour < 6;
        $icon = sprintf('icon/%s/%s',
            $weather->weatherCode,
            $isNight ? 'night.png' : 'day.png'
        );

        $workflow->item()
            ->uid($date)
            ->arg(sprintf('%s%s?lang=zh-cn', $apiHost, $city))
            ->title($title)
            ->subtitle($content)
            ->icon($icon);
    }

    return $workflow;
}

loadData();
cleanCache();
$workflow = processData();

if (empty($workflow->items()->all())) {
    $workflow->item()
        ->uid('wrrt_500')
        ->arg('https://wttr.in?lang=zh-cn')
        ->title('没有响应，请再试一次。')
        ->subtitle('请确保指令和网络链接正常')
        ->icon('icon.png');
}

$workflow->output();