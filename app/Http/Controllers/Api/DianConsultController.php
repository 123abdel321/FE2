<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GetAcquirerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DianConsultController extends Controller
{
    public function getAcquirer(Request $request)
    {
        $request->validate([
            'identification_type' => 'required|string|in:11,12,13,21,22,31,41,42,47,48,50,91',
            'identification_number' => 'required|string|max:20',
        ]);

        $company = auth()->user()->company;

        if (
            !$company ||
            !$company->certificate ||
            !$company->certificate->path
        ) {
            return response()->json([
                'success' => false,
                'message' => 'La empresa no tiene un certificado digital cargado.',
            ], 422);
        }

        $cacheKey = sprintf(
            'dian:acquirer:%s:%s:%s',
            $company->id,
            $request->identification_type,
            $request->identification_number
        );

        try {
            $data = Cache::remember(
                $cacheKey,
                now()->addDay(),
                function () use ($request, $company) {
                    $service = new GetAcquirerService(
                        $company->certificate->path,
                        $company->certificate->password
                    );

                    return $service->consult(
                        $request->identification_type,
                        $request->identification_number
                    );
                }
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Consulta realizada correctamente.',
            ]);

        } catch (\Throwable $e) {

            Log::error('Error consultando GetAcquirer DIAN', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo consultar el adquiriente: ' .
                    $e->getMessage(),
            ], 422);
        }
    }
}