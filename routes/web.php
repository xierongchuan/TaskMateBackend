<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'OK 200';
});

// Route::get('/set/{key}/{data}', function ($key, $data) {
//     Cache::put($key, $data, 120); // 600 секунд = 10 минут
//     return "Data cached: $data";
// });

// Route::get('/get/{key}', function ($key) {
//     $data = Cache::get($key, 'not found');
//     return "Cached data: $data";
// });
