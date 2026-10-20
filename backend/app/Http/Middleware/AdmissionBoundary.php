<?php

namespace App\Http\Middleware;

use App\Services\Admission\AdmissionAccess;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdmissionBoundary
{
    public function handle(Request $request, Closure $next, string $capability)
    {
        try {
            AdmissionAccess::require($request->user(), $capability);

            return $next($request)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Check the supplied fields.', 'errors' => $e->errors()], 422)->header('Cache-Control', 'private, no-store');
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Admission record not found.'], 404)->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'Admission is temporarily unavailable. Please reload and try again.'], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
