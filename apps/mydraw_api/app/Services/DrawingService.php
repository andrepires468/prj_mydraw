<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Drawing;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DrawingService
{
    public function index(Request $request): mixed
    {
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));

        return Drawing::query()
            ->with('user:id,name')
            ->latest()
            ->paginate($perPage);
    }

    public function show(int $id): Drawing
    {
        return Drawing::query()
            ->with('user:id,name')
            ->findOrFail($id);
    }

    /**
     * @return array{drawing: Drawing}
     */
    public function store(Request $request): array
    {
        $user = $this->resolveAuthenticatedUser($request);

        $userId = (int) $user->getKey();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $key = sprintf(
            'drawings/%d/%s.%s',
            $userId,
            Str::uuid(),
            $file->getClientOriginalExtension()
        );

        Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
            'visibility'    => 'public',
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

        return ['drawing' => $drawing->fresh('user')];
    }

    /**
     * @return array{deleted: bool}
     */
    public function destroy(Request $request, int $id): array
    {
        $auth = $this->resolveAuthenticatedUser($request);

        $drawing = Drawing::findOrFail($id);

        if ($drawing->user_id !== (int) $auth->getKey()) {
            throw new ApiException(403, 'Forbidden.');
        }

        Storage::disk('s3')->delete($drawing->object_key);
        $drawing->delete();

        return ['deleted' => true];
    }

    private function resolveAuthenticatedUser(Request $request): User
    {
        $user = $request->input('auth_user');
        if ($user instanceof User) {
            return $user;
        }

        $fallback = Auth::user();
        if ($fallback instanceof User) {
            return $fallback;
        }

        throw new ApiException(401, 'Unauthorized.');
    }
}
