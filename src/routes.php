<?php

Route::group(['prefix' => 'webhook', 'as' => 'webhook.'], function () {

    Route::any('/support_bot', ['as' => 'support_bot', 'uses' => 'Vsesdal\SupportBot\SupportBotController@support_bot']);

});
