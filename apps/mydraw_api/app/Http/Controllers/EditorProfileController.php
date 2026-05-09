<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class EditorProfileController extends Controller
{
    /**
     * GET /api/v1/editor/profile — perfil público do usuário administrador (editor do site).
     */
    public function show(): JsonResponse
    {
        $admin = User::query()
            ->where('IsAdmin', true)
            ->orderBy('Id')
            ->first();

        if ($admin === null) {
            return response()->json(['message' => 'Editor not found'], 404);
        }

        return response()->json([
            'editor' => [
                'id'         => $admin->id,
                'name'       => $admin->name,
                'avatar_url' => $admin->avatar_url,
            ],
        ]);
    }
}
