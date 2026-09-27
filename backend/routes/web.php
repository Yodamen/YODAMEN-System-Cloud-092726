<?php

use Illuminate\Support\Facades\Route;

$serveYodamenSpa = function () {
    $index = public_path('index.html');

    if (file_exists($index)) {
        return response()->file($index);
    }

    return view('welcome');
};

Route::get('/', $serveYodamenSpa);

Route::get('/{any}', $serveYodamenSpa)
    ->where('any', '^(?!api(?:/|$)|sanctum(?:/|$)).*');
