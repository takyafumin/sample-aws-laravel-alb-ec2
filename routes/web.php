<?php

use App\Http\Controllers\WhoAmIController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/whoami', [WhoAmIController::class, 'show'])->name('whoami');
