<?php

use App\Exceptions\MobileApiException;
use App\Http\Middleware\PreventSearchIndexing;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PreventSearchIndexing::class);
        $middleware->trimStrings(except: ['currentPassword', 'newPassword']);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : '/');
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.integrations.show') : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'admin_password', 'code', 'phone', 'newPhone', 'currentPassword', 'newPassword', 'accessToken', 'refreshToken', 'resetToken']);
        $exceptions->dontReport([MobileApiException::class]);

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('v1', 'v1/*')) {
                return (new MobileApiException('VALIDATION_ERROR', 400, 'Invalid request fields.', array_key_first($exception->errors())))->render();
            }

            return null;
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('v1', 'v1/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = match ($status) {
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                429 => 'RATE_LIMITED',
                413 => $request->is('v1/me/avatar') ? 'FILE_TOO_LARGE' : 'REQUEST_TOO_LARGE',
                default => $status >= 500 ? 'SERVER_ERROR' : 'INVALID_REQUEST',
            };

            return (new MobileApiException($code, $status, 'Request could not be completed.'))->render()
                ->withHeaders($exception->getHeaders());
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('v1', 'v1/*')) {
                return (new MobileApiException('SERVER_ERROR', 500, 'Unexpected server error.'))->render();
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'v1', 'v1/*') || $request->expectsJson(),
        );
    })->create();
