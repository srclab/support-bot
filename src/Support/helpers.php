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