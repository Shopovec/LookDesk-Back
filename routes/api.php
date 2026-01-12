<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTranslationController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\DashboardController;

Route::prefix('auth')->group(function () {
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('auth/me',    [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index']);

    // Users
    Route::get('users',       [UserController::class, 'index']);
    Route::get('users/{id}',  [UserController::class, 'show']);
    Route::post('users',      [UserController::class, 'store']);
    Route::put('users/{id}',  [UserController::class, 'update']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);

    // Roles
    Route::get('roles', [RoleController::class, 'index']);

    // Categories
    Route::apiResource('categories', CategoryController::class);

    // Documents
    Route::apiResource('documents', DocumentController::class);

    // Document Translations
    Route::get('documents/{id}/translations', [DocumentTranslationController::class, 'index']);
    Route::post('documents/{id}/translations', [DocumentTranslationController::class, 'store']);
    Route::put('translations/{id}', [DocumentTranslationController::class, 'update']);
    Route::delete('translations/{id}', [DocumentTranslationController::class, 'destroy']);

    // OCR
    Route::post('ocr/scan', [OcrController::class, 'scan']);
    Route::get('ocr/list',  [OcrController::class, 'list']);

    // Search
    Route::get('search', [SearchController::class, 'search']);
});
