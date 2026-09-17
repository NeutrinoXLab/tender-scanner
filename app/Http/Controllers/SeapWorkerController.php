<?php

namespace App\Http\Controllers;

use App\Services\SeapWorkerExchange;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SeapWorkerController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['protocol' => 1, 'ready' => true]);
    }

    public function claim(SeapWorkerExchange $exchange): JsonResponse|Response
    {
        return $this->safely(function () use ($exchange): Response {
            $batch = $exchange->claim();

            return $batch ? response()->json(['batch_id' => $batch->id, 'request' => $batch->request]) : response()->noContent();
        });
    }

    public function store(Request $request, string $batch, SeapWorkerExchange $exchange): Response
    {
        return $this->safely(fn () => response()->json($exchange->accept($batch, $request->json()->all())));
    }

    private function safely(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof ValidationException) {
                throw $exception;
            }

            return response()->json(['message' => 'Transfer indisponibil. Lotul poate fi retransmis.'], 503);
        }
    }
}
