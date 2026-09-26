<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer
|--------------------------------------------------------------------------
|
| Only reachable before installation finishes. See RedirectToInstaller.
|
*/

Route::get('/', [InstallController::class, 'welcome'])->name('welcome');
Route::get('database', [InstallController::class, 'database'])->name('database');
Route::post('database', [InstallController::class, 'saveDatabase'])->name('database.save');
Route::get('account', [InstallController::class, 'account'])->name('account');
Route::post('account', [InstallController::class, 'finish'])->name('finish');
