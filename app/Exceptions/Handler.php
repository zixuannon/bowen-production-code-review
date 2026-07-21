<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
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
        if ($exception instanceof UploadValidationException) {
            // Write a desensitized warning log: no file content, cookies,
            // tokens, or sensitive request parameters.
            Log::warning('Upload rejected', [
                'reason'  => $exception->getMessage(),
                'user_id' => Auth::id(),
                'route'   => $request->route() ? $request->route()->uri() : $request->path(),
                'ip'      => $request->ip(),
                'field'   => $exception->getField(),
            ]);

            $safeMessage = 'File upload rejected.';
            $field       = $exception->getField();

            // API / AJAX requests: return 422 JSON
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'error'   => true,
                    'message' => $safeMessage,
                ], 422);
            }

            // Browser form requests: redirect back with validation error
            return redirect()->back()
                ->withErrors([$field => $safeMessage])
                ->withInput();
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
