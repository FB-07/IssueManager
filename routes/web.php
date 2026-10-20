<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\IssueEditController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\TurnController;
use App\Http\Controllers\YouTrackController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::get('/auth/youtrack/redirect', [AuthController::class, 'redirect'])->middleware('throttle:20,1')->name('auth.youtrack');
    Route::post('/auth/dev', [AuthController::class, 'dev'])->name('auth.dev');
});
Route::get('/auth/youtrack/callback', [AuthController::class, 'callback'])->middleware('throttle:20,1')->name('auth.youtrack.callback');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', AppController::class)->name('home');
    Route::get('/c/{id}', AppController::class)->whereNumber('id')->name('conversation');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::prefix('api')->group(function () {
        Route::patch('/preferences', [PreferenceController::class, 'update']);
        Route::post('/chat', ChatController::class)->middleware('throttle:30,1')->name('chat');

        Route::patch('/attachments/{attachment}', [AttachmentController::class, 'update'])->whereNumber('attachment');
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::delete('/conversations', [ConversationController::class, 'destroyMany']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])->whereNumber('conversation');
        Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/turn', TurnController::class)->whereNumber('conversation');
        Route::patch('/conversations/{conversation}/draft', [DraftController::class, 'update'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/messages/{message}/batch', BatchController::class)->whereNumber(['conversation', 'message'])->middleware('throttle:20,1');
        Route::post('/conversations/{conversation}/issues/update', IssueEditController::class)->whereNumber('conversation');
        Route::get('/conversations/{conversation}/draft/similar', [DraftController::class, 'similar'])->whereNumber('conversation')->middleware('throttle:60,1');
        Route::post('/conversations/{conversation}/draft/create', [DraftController::class, 'create'])->whereNumber('conversation');

        Route::get('/youtrack/image', [YouTrackController::class, 'image'])->middleware('throttle:600,1');

        Route::prefix('youtrack')->middleware('throttle:60,1')->group(function () {
            Route::get('/catalog', [YouTrackController::class, 'catalog']);
            Route::get('/projects', [YouTrackController::class, 'projects']);
            Route::get('/projects/{project}/meta', [YouTrackController::class, 'meta']);
            Route::get('/issues', [YouTrackController::class, 'issues']);
            Route::post('/issues', [YouTrackController::class, 'create']);
            Route::get('/issues/{id}', [YouTrackController::class, 'issue']);
            Route::patch('/issues/{id}', [YouTrackController::class, 'update']);
        });
    });
});
