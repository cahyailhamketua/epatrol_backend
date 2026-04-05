<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/kebijakan-privasi', function () {
    return view('privacy-policy');
});