<?php

if (!function_exists('app_config')) {

    /**
     * Получение данных из файла конфигурации в зависимости от текущей страны.
     *
     * @param array|string $key
     * @param mixed $default
     * @return mixed
     */
    function app_config($key = null, $default = null)
    {
        $country_code = strtolower(config('app.country', ''));

        if(empty($country_code)) {
            return config($key, $default);
        }

        return config("{$country_code}.{$key}", config($key, $default));
    }
}

if (!function_exists('check_current_time')) {
    /**
     * Проверка, что текущее время содержится в указанном интервале.
     *
     * @param string $time_begin
     * @param string $time_end
     * @return bool
     */
    function check_current_time($time_begin, $time_end)
    {
        $now_time = now()->format('H:i');

        if ($time_begin > $time_end) {
            return ! ($now_time < $time_begin && $now_time > $time_end);
        }

        return $now_time >= $time_begin && $now_time <= $time_end;
    }
}