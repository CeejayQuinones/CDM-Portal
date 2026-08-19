<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $role_name
 * @property string|null $description
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 */
class Role extends Model
{
    use HasFactory;

    public const GUEST = 'Guest';

    public const ADMIN = 'Admin';

    public const REGISTRAR_STAFF = 'Registrar Staff';

    public const PROFESSOR = 'Professor';

    public const STUDENT = 'Student';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_name',
        'description',
    ];

    /**
     * Get the users assigned to this role.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
