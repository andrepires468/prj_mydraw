<?php

namespace App\Models;

use App\Support\StorageObjectUrl;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Lumen\Auth\Authorizable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements AuthenticatableContract, AuthorizableContract, JWTSubject
{
    use Authenticatable, Authorizable, HasFactory;

    protected $table = 'users';

    protected $primaryKey = 'Id';

    public $timestamps = false;

    protected $fillable = [
        'Name',
        'Email',
        'Password',
        'GoogleId',
        'AvatarKey',
    ];

    /**
     * Esconde as colunas originais (PascalCase) da serialização JSON.
     * A API expõe esses dados via accessors em snake_case (`id`, `name`, `email`).
     */
    protected $hidden = [
        'Id',
        'Name',
        'Email',
        'Password',
        'GoogleId',
        'AvatarKey',
    ];

    protected $appends = [
        'id',
        'name',
        'email',
        'google_linked',
        'avatar_url',
    ];

    public function getIdAttribute(): int
    {
        return (int) ($this->attributes['Id'] ?? 0);
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['Name'] ?? null;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->attributes['Email'] ?? null;
    }

    public function getGoogleLinkedAttribute(): bool
    {
        return filled($this->attributes['GoogleId'] ?? null);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return StorageObjectUrl::urlForKey($this->attributes['AvatarKey'] ?? null);
    }

    /**
     * Usado pelo Laravel Auth para validar a senha do usuário.
     * A coluna no banco é `Password` (PascalCase).
     */
    public function getAuthPassword(): string
    {
        return $this->attributes['Password'] ?? '';
    }

    /**
     * Retorna o identificador a ser armazenado no claim "sub" do JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Claims customizados adicionados ao JWT.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(Drawing::class, 'user_id', 'Id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id', 'Id');
    }
}
