<?php

use Atannex\Matrix\Http\Controllers\BeaconController;
use Atannex\Matrix\Http\Controllers\EngagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Matrix API
|--------------------------------------------------------------------------
|
| {token} is the item's signed tracking token (Matrix::token($item), or
| $item->matrixToken()), so only items the site rendered can be engaged with.
|
*/

Route::post('beacon/view', [BeaconController::class, 'view'])->name('beacon.view');
Route::post('beacon/read', [BeaconController::class, 'read'])->name('beacon.read');
Route::post('beacon/click', [BeaconController::class, 'click'])->name('beacon.click');

Route::controller(EngagementController::class)->prefix('{token}')->where(['token' => '[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+'])->group(function () {
    Route::get('/', 'show')->name('show');

    Route::post('reactions', 'react')->name('react');
    Route::delete('reactions', 'unreact')->name('unreact');

    Route::post('ratings', 'rate')->name('rate');
    Route::delete('ratings', 'unrate')->name('unrate');

    Route::get('reviews', 'reviews')->name('reviews');
    Route::post('reviews', 'review')->name('review');
    Route::delete('reviews', 'unreview')->name('unreview');

    Route::get('comments', 'comments')->name('comments');
    Route::post('comments', 'comment')->name('comment');
    Route::patch('comments/{comment}', 'editComment')->whereNumber('comment')->name('comments.edit');
    Route::delete('comments/{comment}', 'deleteComment')->whereNumber('comment')->name('comments.delete');
    Route::post('comments/{comment}/like', 'likeComment')->whereNumber('comment')->name('comments.like');

    Route::post('shares', 'share')->name('share');

    Route::post('bookmark', 'bookmark')->name('bookmark');
    Route::delete('bookmark', 'unbookmark')->name('unbookmark');

    Route::post('follow', 'follow')->name('follow');
    Route::delete('follow', 'unfollow')->name('unfollow');

    Route::post('reports', 'report')->name('report');
});
