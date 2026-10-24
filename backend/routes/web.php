<?php

use App\Http\Controllers\BrowserAppController;
use Illuminate\Support\Facades\Route;

Route::get('/app/{section?}', BrowserAppController::class)->where('section', 'overview|requirements|tasks|qa|ba|management');
