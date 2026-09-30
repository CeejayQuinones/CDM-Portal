<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Http\Controllers\Controller;
use App\Services\Enrollment\EnrollmentEligibilityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EnrollmentStatusController extends Controller
{
    public function __invoke(Request $request, EnrollmentEligibilityService $eligibility)
    {
        try {
            return response()->json(['success' => true, 'data' => $eligibility->status($request->user())])
                ->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode())
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'Enrollment status is temporarily unavailable. Please try again.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
