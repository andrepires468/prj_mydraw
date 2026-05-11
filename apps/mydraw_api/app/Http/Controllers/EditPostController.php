<?php

namespace App\Http\Controllers;

use App\Services\EditPostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EditPostController extends Controller
{
    public function __construct(private readonly EditPostService $editPostService)
    {
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'title'       => 'sometimes|nullable|string|max:160',
            'description' => 'sometimes|nullable|string|max:2000',
            'status'      => 'sometimes|string|in:draft,published',
        ]);

        return ApiResponse::success($this->editPostService->update($request, $id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->editPostService->destroy($request, $id));
    }

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
        ]);

        return ApiResponse::success($this->editPostService->uploadImage($request, $id), '', 201);
    }

    public function destroyImage(Request $request, int $id, int $galleryId): JsonResponse
    {
        return ApiResponse::success($this->editPostService->destroyImage($request, $id, $galleryId));
    }

    public function reorderImages(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'order'   => 'required|array',
            'order.*' => 'integer|distinct|min:1',
        ]);

        return ApiResponse::success($this->editPostService->reorderImages($request, $id));
    }

    public function extractGalleryMetadata(Request $request): JsonResponse
    {
        $this->validate($request, [
            'all_posts' => 'sometimes|boolean',
            'post_id'   => 'sometimes|integer|min:1',
        ]);

        return ApiResponse::success($this->editPostService->extractGalleryMetadata($request));
    }

    public function categorizeWithAi(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->editPostService->categorizeWithAi($request, $id));
    }
}
