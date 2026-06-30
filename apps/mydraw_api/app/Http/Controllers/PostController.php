<?php

namespace App\Http\Controllers;

use App\Services\PostReorderService;
use App\Services\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $postService,
        private readonly PostReorderService $postReorderService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->postService->index($request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->postService->show($request, $id));
    }

    public function reorderList(Request $request): JsonResponse
    {
        return ApiResponse::success($this->postReorderService->listForReorder($request));
    }

    public function reorder(Request $request): JsonResponse
    {
        return ApiResponse::success($this->postReorderService->reorder($request));
    }
}
