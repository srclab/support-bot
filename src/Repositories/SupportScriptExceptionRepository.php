<?php

namespace SrcLab\SupportBot\Repositories;

use SrcLab\SupportBot\Models\SupportScriptExceptionModel as SupportScriptExceptionModel;
use Illuminate\Support\Facades\Cache;
use DB;

class SupportScriptExceptionRepository extends Repository
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * @var array
     */
    protected $config;

    /**
     * SupportScriptRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportScriptExceptionModel::class;

        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
    }

    /**
     * Получение списка исключений.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllException()
    {
        return Cache::rememberForever('script_exception', function () {
            return DB::table($this->config['scripts']['exceptions_table_name'])->get();
        });
    }
}