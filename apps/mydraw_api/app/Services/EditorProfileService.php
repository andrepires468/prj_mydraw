<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;

class EditorProfileService
{
    /**
     * Perfil público do primeiro usuário administrador.
     *
     * @return array{editor: array{id: int, name: string|null, avatar_url: string|null}}
     */
    public function getPublicEditorProfile(): array
    {
        $admin = User::query()
            ->where('IsAdmin', true)
            ->orderBy('Id')
            ->first();

        if ($admin === null) {
            throw new ApiException(404, 'Editor not found.');
        }

        return [
            'editor' => [
                'id'         => $admin->id,
                'name'       => $admin->name,
                'avatar_url' => $admin->avatar_url,
            ],
        ];
    }
}
