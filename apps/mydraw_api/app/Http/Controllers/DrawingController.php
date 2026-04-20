<?php

namespace App\Http\Controllers;

use App\Models\Drawing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DrawingController extends Controller
{
    /**
     * GET /api/drawings — listagem pública paginada.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));

        $drawings = Drawing::query()
            ->with('user:id,name')
            ->latest()
            ->paginate($perPage);

        return response()->json($drawings);
    }

    /**
     * GET /api/drawings/{id}
     */
    public function show(int $id): JsonResponse
    {
        $drawing = Drawing::query()
            ->with('user:id,name')
            ->findOrFail($id);

        return response()->json(['drawing' => $drawing]);
    }

    /**
     * POST /api/drawings — autenticado (JWT).
     * multipart/form-data com "file".
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'file'        => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
            'title'       => 'required|string|max:160',
            'description' => 'nullable|string|max:2000',
            'prompt'      => 'nullable|string|max:2000',
            'model'       => 'nullable|string|max:80',
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file   = $request->file('file');
        $userId = Auth::id();

        $key = sprintf(
            'drawings/%d/%s.%s',
            $userId,
            Str::uuid(),
            $file->getClientOriginalExtension()
        );

        Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
            'visibility'  => 'public',
            'ContentType' => $file->getMimeType(),
        ]);

        $drawing = Drawing::create([
            'user_id'     => $userId,
            'title'       => $request->input('title'),
            'description' => $request->input('description'),
            'prompt'      => $request->input('prompt'),
            'model'       => $request->input('model'),
            'object_key'  => $key,
            'mime_type'   => $file->getMimeType(),
            'size'        => $file->getSize(),
        ]);

        return response()->json(['drawing' => $drawing->fresh('user')], 201);
    }

    /**
     * DELETE /api/drawings/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $drawing = Drawing::findOrFail($id);

        if ($drawing->user_id !== Auth::id()) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        Storage::disk('s3')->delete($drawing->object_key);
        $drawing->delete();

        return response()->json(['deleted' => true]);
    }
}
