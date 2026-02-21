<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FunctionController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTranslationController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\EnterpriseRequestController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\SupportChatController;
use App\Http\Controllers\Api\SocialAuthController;



Route::prefix('auth/social')->group(function () {
    Route::post('google', [SocialAuthController::class, 'google']);
    Route::post('microsoft', [SocialAuthController::class, 'microsoft']);
});

Route::prefix('auth')->group(function () {
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verify', [AuthController::class, 'verify']);

});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('ai/search', [AiChatController::class, 'search']);
    Route::get('ai/sessions', [AiChatController::class, 'sessions']);
    Route::get('ai/sessions/{id}', [AiChatController::class, 'showSession']);
    Route::post('ai/sessions/{id}/message', [AiChatController::class, 'message']);
    Route::post('ai/messages/{id}/feedback', [AiChatController::class, 'feedback']);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('support')->group(function () {
        Route::get('chats', [SupportChatController::class,'index']);
        Route::post('chats', [SupportChatController::class,'create']);
        Route::get('chats/{id}', [SupportChatController::class,'show']);
        Route::post('chats/{id}/message', [SupportChatController::class,'sendMessage']);
        Route::post('chats/{id}/close', [SupportChatController::class,'close']);
    });

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

    // Events
    Route::put('events/toggle', [EventController::class, 'toggle']);
    Route::get('events', [EventController::class, 'index']);

    // Categories
    Route::apiResource('categories', CategoryController::class);
    // Functions
    Route::apiResource('functions', FunctionController::class);
    
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
});

    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        Route::apiResource('plans', \App\Http\Controllers\Api\Admin\PlanController::class);
    });

    Route::post('/stripe/webhook', [
        StripeWebhookController::class,
        'webhook'
    ]);

    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('billing')->group(function () {

            Route::get('/subscription', [BillingController::class, 'current']);

            Route::post('/subscribe', [BillingController::class, 'subscribe']);

            Route::put('/change-plan', [BillingController::class, 'changePlan']);

            Route::post('/cancel', [BillingController::class, 'cancel']);

        });

    });

    /* public */
    Route::post(
        '/enterprise-requests',
        [EnterpriseRequestController::class, 'store']
    );

    /* admin */
    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        Route::get(
            '/enterprise-requests',
            [EnterpriseRequestController::class, 'index']
        );
    });

    Route::get('/plans', [PlanController::class, 'index']);


