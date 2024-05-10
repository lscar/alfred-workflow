<?php

require 'vendor/autoload.php';

use Alfred\Workflows\Workflow;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

// __DIR__不支持，替代为'.'
$dotenv = Dotenv\Dotenv::createImmutable('.');
$dotenv->safeLoad();

$workflow = new Workflow();
// __DIR__不支持，替代为'.'
$cache = new FilesystemAdapter(directory: '.');
$client = new Client(['base_uri' => $_ENV['WTTR_API']]);
// with input as argv，参数均在$argv[1]
$params = explode(' ', trim($argv[1]));
$city = $params[0];
$type = $params[1] ?? '';

function processNow(string $city, Workflow $workflow, object $currentCondition): Workflow
{
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
    $isNight = $currentHour > (int)$_ENV['DAY_END'] || $currentHour < (int)$_ENV['DAY_START'];
    $icon = sprintf('icon/%s/%s', $currentCondition->weatherCode, $isNight ? 'night.png' : 'day.png');

    $workflow->item()
        ->uid($city)
        ->arg(sprintf('%s%s?lang=%s', $_ENV['WTTR_API'], $city, $_ENV['WTTR_LANG']))
        ->title($title)
        ->subtitle($content)
        ->icon($icon);

    return $workflow;
}

function processMultiDay(string $city, Workflow $workflow, array $forecastCondition, int $day): Workflow
{
    global $isNight;
    $currentCondition = $forecastCondition[$day];
    $date = $currentCondition->date;
    $hours = $currentCondition->hourly;
    foreach ($hours as $hour => $weather) {
        $title = sprintf("%s %02d~%02d时 [%s] 气温：%s°C 湿度：%s%% 紫外线：%s",
            $date,
            $hour * 3,
            ($hour + 1) * 3 - 1,
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
        $currentHour = $hour * 3;
        $isNight = $currentHour > (int)$_ENV['DAY_END'] || $currentHour < (int)$_ENV['DAY_START'];
        $icon = sprintf('icon/%s/%s', $weather->weatherCode, $isNight ? 'night.png' : 'day.png');

        $workflow->item()
            ->uid($date)
            ->arg(sprintf('%s%s?lang=%s', $_ENV['WTTR_API'], $city, $_ENV['WTTR_LANG']))
            ->title($title)
            ->subtitle($content)
            ->icon($icon);
    }

    return $workflow;
}

$cityData = $cache->get($city, function (ItemInterface $item) use ($city, $client): string {
    $response = $client->get($city, [
        RequestOptions::TIMEOUT => (int)$_ENV['HTTP_TIMEOUT'],
        RequestOptions::PROXY   => [
            'http'  => $_ENV['HTTP_PROXY'],
            'https' => $_ENV['HTTP_PROXY_SSL'],
        ],
        RequestOptions::QUERY   => [
            'format' => 'j1',
            'lang'   => $_ENV['WTTR_LANG'],
        ],
    ]);
    $success = $response->getStatusCode() == 200;
    $item->expiresAfter($success ? (int)$_ENV['CACHE_TIME'] : 5);
    return $success ? $response->getBody() : '';
});

if (empty($cityData)) {
    $workflow->item()
        ->uid('wttr_500')
        ->arg('https://wttr.in?lang=zh-cn')
        ->title('没有响应，请再试一次。')
        ->subtitle('请确保指令和网络链接正常')
        ->icon('icon.png');
} else {
    $cityData = json_decode($cityData);
    $currentCondition = $cityData->current_condition[0] ?? new stdClass();
    $forecastCondition = $cityData->weather ?? [];
    $workflow = match ($type) {
        'now' => processNow($city, $workflow, $currentCondition),
        '1st', '1', '' => processMultiDay($city, $workflow, $forecastCondition, 0),
        '2nd', '2' => processMultiDay($city, $workflow, $forecastCondition, 1),
        '3rd', '3' => processMultiDay($city, $workflow, $forecastCondition, 2),
        default => $workflow,
    };
}

$workflow->output();