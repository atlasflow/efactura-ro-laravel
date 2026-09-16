<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Laravel\Http\Controllers\AuthoriseController;
use AtlasFlow\EFacturaRo\Laravel\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/authorise', AuthoriseController::class)->name('efactura.authorise');
Route::get('/callback', CallbackController::class)->name('efactura.callback');
