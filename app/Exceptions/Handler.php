<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        UploadValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    function render($request, Throwable $exception)
    {
        // Convert upload validation failures into safe 422 JSON responses.
        // No stack trace, no internal message — just a generic validation error.
        if ($exception instanceof UploadValidationException) {
            return response()->json([
                'error'   => true,
                'message' => 'File upload rejected.',
            ], 422);
        }

        if ($this->isHttpException($exception)) {
            switch ($exception->getCode()) {

                //not found
                case 404:
                    return \Response::view('errors.404',[], 404);
                    break;

                default:
                    return $this->renderHttpException($exception);
                    break;
            }
        } else {
            return parent::render($request, $exception);
        }
    }
    
}
