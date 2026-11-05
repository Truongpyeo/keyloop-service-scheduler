<?php

use App\Enums\DatabaseFailure;
use App\Exceptions\BookingConflictException;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport(BookingConflictException::class);

        $exceptions->render(fn (BookingConflictException $exception) => response()->json([
            'message' => $exception->getMessage(),
            'reason' => $exception->reason->value,
        ], 409));

        $exceptions->render(function (QueryException $exception) {
            $failure = DatabaseFailure::tryFromException($exception);

            if ($failure === null) {
                return null;
            }

            Log::warning('database.unavailable', ['reason' => $failure->value, 'error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'The service is temporarily unavailable. Please retry shortly.',
                'reason' => $failure->value,
            ], 503, ['Retry-After' => '5']);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $exception->getPrevious();

            return response()->json([
                'message' => $previous instanceof ModelNotFoundException
                    ? class_basename($previous->getModel()).' not found.'
                    : 'Not found.',
            ], 404);
        });
    })->create();
