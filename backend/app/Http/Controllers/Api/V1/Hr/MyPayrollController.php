<?php
namespace App\Http\Controllers\Api\V1\Hr;
use App\Http\Controllers\Controller; use App\Services\Hr\PayrollService; use DomainException; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class MyPayrollController extends Controller { public function index(Request $request,PayrollService $service):JsonResponse{try{return response()->json($service->myPayroll($request->user()));}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}} }
