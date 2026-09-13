<?php

use Illuminate\Support\Facades\Route;
use Thayron\CjDropshipping\Laravel\Middleware\VerifyCjWebhookSignature;
use Thayron\LunarCjDropshipping\Http\Controllers\WebhookController;

Route::post((string) config('lunar-cjdropshipping.webhooks.path'), WebhookController::class)
    ->middleware(VerifyCjWebhookSignature::class)
    ->name('lunar-cjdropshipping.webhook');
