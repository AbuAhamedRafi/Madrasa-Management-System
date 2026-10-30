<?php

use App\Exceptions\AcademicDomainException;
use App\Exceptions\AdmissionsDomainException;
use App\Exceptions\StudentDomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetRequestLocale;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'login',
            'logout',
            'api/*',
        ]);

        $middleware->append(AssignRequestId::class);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'permission' => RequirePermission::class,
            'locale' => SetRequestLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true
        );

        $exceptions->render(function (AcademicDomainException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => empty($e->details) ? (object) [] : $e->details,
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->httpStatus);
        });

        $exceptions->render(function (StudentDomainException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => empty($e->details) ? (object) [] : $e->details,
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->httpStatus);
        });

        $exceptions->render(function (AdmissionsDomainException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => empty($e->details) ? (object) [] : $e->details,
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->httpStatus);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'অনুগ্রহ করে তথ্য যাচাই করুন।',
                    'details' => $e->errors(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $code = match ($e->getStatusCode()) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                429 => 'TOO_MANY_REQUESTS',
                default => 'HTTP_ERROR',
            };

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $e->getMessage() ?: 'একটি অপ্রত্যাশিত ত্রুটি ঘটেছে।',
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->getStatusCode());
        });
    })->create();
