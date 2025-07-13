<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

// Page d'accueil avec dashboard compilé
Route::get('/', [HomeController::class, 'index']);

// Routes alternatives
Route::get('/dashboard', [HomeController::class, 'index']);
Route::get('/sync', [HomeController::class, 'index']);