<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public const ROLES = ['user', 'moderator', 'admin'];

    /** Moderators and admins review contributed content; only admins change the brand tree. */
    public function isStaff(): bool
    {
        return in_array($this->role, ['moderator', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function mealOffers(): HasMany
    {
        return $this->hasMany(MealOffer::class);
    }

    public function advisoryMatchReviews(): HasMany
    {
        return $this->hasMany(AdvisoryMatchReview::class);
    }
}
