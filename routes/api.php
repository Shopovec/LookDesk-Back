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
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\OwnerChangeRequestController;

Route::post('support/contact', [SupportController::class, 'contact']);

Route::prefix('auth/social')->group(function () {
    Route::post('google', [SocialAuthController::class, 'google']);
    Route::post('microsoft', [SocialAuthController::class, 'microsoft']);
});

Route::prefix('auth')->group(function () {
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verify', [AuthController::class, 'verify']);

});

Route::prefix('owner-change')->middleware(['auth:sanctum', 'update_last_seen'])->group(function () {

    Route::get('/', [OwnerChangeRequestController::class, 'index']);
    Route::post('/', [OwnerChangeRequestController::class, 'store']);
    Route::post('{id}/approve', [OwnerChangeRequestController::class, 'approve']);
    Route::post('{id}/reject', [OwnerChangeRequestController::class, 'reject']);

});

Route::middleware(['auth:sanctum', 'update_last_seen'])->group(function () {
    Route::put('/ai/sessions/{id}/favorite', [AiChatController::class, 'favoriteSearch'])
    ->middleware(['auth:sanctum', 'update_last_seen']);
    Route::get('/ai/sessions/{id}/export/excel', [AiChatController::class, 'exportExcel']);
    Route::get('/ai/sessions/{id}/export/pdf',   [AiChatController::class, 'exportPdf']);
    Route::post('ai/search', [AiChatController::class, 'search']);
    Route::get('ai/sessions', [AiChatController::class, 'sessions']);
    Route::get('ai/sessions/{id}', [AiChatController::class, 'showSession']);
    Route::post('ai/sessions/{id}/message', [AiChatController::class, 'message']);
    Route::post('ai/messages/{id}/feedback', [AiChatController::class, 'feedback']);
});


Route::get('team-invitations/accept', [UserController::class, 'accept']);
Route::get('users/{id}/download/pdf', [UserController::class, 'downloadPDF']);
Route::get('users/{id}/download/xsl', [UserController::class, 'downloadXsl']);
Route::get('documents/{id}/download/pdf', [DocumentController::class, 'downloadPDF']);
Route::get('documents/{id}/download/xsl', [DocumentController::class, 'downloadXsl']);
Route::middleware(['auth:sanctum', 'update_last_seen'])->group(function () {

    Route::prefix('support')->group(function () {
        Route::get('chats', [SupportChatController::class,'index']);
        Route::post('chats', [SupportChatController::class,'create']);
        Route::get('chats/current', [SupportChatController::class,'current']);
        Route::delete('chats/current/clear', [SupportChatController::class,'clearChatCurrent']);
        Route::get('chats/{id}', [SupportChatController::class,'show']);
        Route::delete('chats/{id}/clear', [SupportChatController::class,'clearChat']);
        Route::post('chats/current/message', [SupportChatController::class,'sendMessageCurrent']);
        Route::post('chats/{id}/message', [SupportChatController::class,'sendMessage']);

        Route::post('chats/{id}/close', [SupportChatController::class,'close']);
    });

    Route::get('auth/me',    [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('top_search_documents', [DashboardController::class, 'top_search_documents']);

    // Users
    Route::get('users/deleted',       [UserController::class, 'deleted']);
    
    Route::get('users-superadmin',       [UserController::class, 'index2']);
    Route::get('team-users/{id}',  [UserController::class, 'teamUsers']);
    Route::get('users',       [UserController::class, 'index']);
    Route::get('users/{id}',  [UserController::class, 'show']);
    Route::post('users',      [UserController::class, 'store']);
    Route::post('users',      [UserController::class, 'store']);
    Route::post('users/{id}',  [UserController::class, 'update']);
    Route::put('users/{id}',  [UserController::class, 'update']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);

    Route::post('team-invitations', [UserController::class, 'teamInvite']);
    Route::delete('users/{id}/removeFunctions', [UserController::class, 'removeFunctions']);

    // Roles
    Route::get('roles', [RoleController::class, 'index']);

    // Events
    Route::delete('events/clear', [EventController::class, 'clear']);
    Route::put('events/toggle', [EventController::class, 'toggle']);
    Route::get('events', [EventController::class, 'index']);

    // Categories
    Route::apiResource('categories', CategoryController::class);
    // Functions
    Route::apiResource('functions', FunctionController::class);
    
    // Documents
    Route::apiResource('documents', DocumentController::class);

    Route::put('documents/{id}/favorite', [DocumentController::class, 'favorite']);
    // Document Translations
    Route::get('documents/{id}/translations', [DocumentTranslationController::class, 'index']);
    Route::post('documents/{id}/translations', [DocumentTranslationController::class, 'store']);
    Route::put('translations/{id}', [DocumentTranslationController::class, 'update']);
    Route::delete('translations/{id}', [DocumentTranslationController::class, 'destroy']);
    Route::delete('delete_attachment/{id}', [DocumentController::class, 'delete_attachment']);

    // OCR
    Route::post('ocr/scan', [OcrController::class, 'scan']);
    Route::get('ocr/list',  [OcrController::class, 'list']);
});

Route::middleware(['auth:sanctum', 'update_last_seen'])->prefix('admin')->group(function () {
    Route::apiResource('plans', \App\Http\Controllers\Api\Admin\PlanController::class);
});

Route::post('/stripe/webhook', [
    StripeWebhookController::class,
    'webhook'
]);

Route::middleware(['auth:sanctum', 'update_last_seen'])->group(function () {

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
Route::middleware(['auth:sanctum', 'update_last_seen'])->prefix('admin')->group(function () {
    Route::get(
        '/enterprise-requests',
        [EnterpriseRequestController::class, 'index']
    );
});

Route::get('/plans', [PlanController::class, 'index']);


