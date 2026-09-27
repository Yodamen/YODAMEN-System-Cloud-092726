<?php
namespace App\Http\Controllers\Api\V1\Hr;
use App\Http\Controllers\Controller; use App\Services\Hr\PayrollService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class PayslipController extends Controller { public function index(Request $request,PayrollService $service):JsonResponse{return response()->json($service->payslips($request->user(),$request->only('personnel_id')));} }
