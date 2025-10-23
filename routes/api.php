<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StorageController;

Route::post('upload', [StorageController::class, 'upload']);
Route::get('list', [StorageController::class, 'list']);
Route::post('view', [StorageController::class, 'view']);
Route::post('delete', [StorageController::class, 'delete']);


