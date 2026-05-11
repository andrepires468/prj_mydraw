<?php

namespace App\Http\Controllers;

use App\Services\DrawingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrawingController extends Controller
{
    public function __construct(private readonly DrawingService $drawingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->drawingService->index($request));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['drawing' => $this->drawingService->show($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'file'        => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
            'title'       => 'required|string|max:160',
            'description' => 'nullable|string|max:2000',
            'prompt'      => 'nullable|string|max:2000',
            'model'       => 'nullable|string|max:80',
        ]);

        return ApiResponse::success($this->drawingService->store($request), '', 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->drawingService->destroy($request, $id));
    }
}
