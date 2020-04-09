<?php

namespace SrcLab\SupportBot\Repositories;

use SrcLab\SupportBot\Models\SupportScriptExceptionModel as SupportScriptExceptionModel;

class SupportScriptExceptionRepository extends Repository
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * SupportScriptRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportScriptExceptionModel::class;
    }
}