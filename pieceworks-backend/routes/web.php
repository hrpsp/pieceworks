<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['message' => 'PieceWorks API — use /api/* endpoints']);
});
