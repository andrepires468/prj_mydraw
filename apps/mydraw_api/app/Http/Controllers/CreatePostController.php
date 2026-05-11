<?php

namespace App\Http\Controllers;

use App\Services\CreatePostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatePostController extends Controller
{
    public function __construct(private readonly CreatePostService $createPostService)
    {
    }

    public function upload(Request $request): JsonResponse
    {
        $this->validate($request, [
            'file'       => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
            'draft_uuid' => 'required|uuid',
        ]);

        return ApiResponse::success($this->createPostService->upload($request), '', 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'draft_uuid'  => 'required|uuid',
            'title'       => 'nullable|string|max:160',
            'description' => 'nullable|string|max:2000',
        ]);

        return ApiResponse::success($this->createPostService->store($request), '', 201);
    }
}
