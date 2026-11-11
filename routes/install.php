<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer
|--------------------------------------------------------------------------
|
| Only reachable before installation finishes. See RedirectToInstaller.
| Every step after the first page asks for the setup code (InstallController).
|
*/

Route::get('/', [InstallController::class, 'welcome'])->name('welcome');
// The setup code from storage/app/setup-code.txt opens the steps below. There is no limit on tries:
// the code is far too long to guess, and a limit would let a stranger lock the owner out.
Route::post('code', [InstallController::class, 'confirmCode'])->name('code');
Route::get('database', [InstallController::class, 'database'])->name('database');
Route::post('database', [InstallController::class, 'saveDatabase'])->name('database.save');
Route::get('account', [InstallController::class, 'account'])->name('account');
Route::post('account', [InstallController::class, 'finish'])->name('finish');
