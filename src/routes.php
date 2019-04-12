<?php

Route::group(['prefix' => 'webhook', 'as' => 'webhook.'], function () {

    Route::any('/support_bot', ['as' => 'support_bot', 'uses' => 'SrcLab\SupportBot\SupportBotController@support_bot']);

});
