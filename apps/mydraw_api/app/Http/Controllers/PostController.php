<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * GET /api/v1/posts — listagem pública paginada com imagens da Gallery.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));

        $posts = Post::query()
            ->with([
                'user:Id,Name',
                'images' => function ($q) {
                    $q->orderBy('position')->orderBy('id');
                },
            ])
            ->latest()
            ->paginate($perPage);

        return response()->json($posts);
    }

    /**
     * GET /api/v1/posts/{id}
     */
    public function show(int $id): JsonResponse
    {
        $post = Post::query()
            ->with([
                'user:Id,Name',
                'images' => function ($q) {
                    $q->orderBy('position')->orderBy('id');
                },
            ])
            ->findOrFail($id);

        return response()->json(['post' => $post]);
    }
}
