<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EnrollmentBoundary
{
    public function handle(Request $request, Closure $next)
    {
        try {

            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Check the supplied fields.', 'errors' => $e->errors()], 422)->header('Cache-Control', 'private, no-store');
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Enrollment record not found.'], 404)->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'Enrollment is temporarily unavailable. Please reload and try again.'], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
