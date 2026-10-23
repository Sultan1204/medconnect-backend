<?php

namespace App\Modules\Administration\Models;

use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message sent through the public contact form.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $subject
 * @property string $message
 * @property string|null $handled_by_user_id
 * @property Carbon|null $handled_at
 * @property Carbon $created_at
 */
class ContactMessage extends Model
{
    use HasUuids;
    use StoresUtcDates;

    protected $fillable = ['name', 'email', 'subject', 'message'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'email' => 'encrypted',
            'message' => 'encrypted',
            'handled_at' => 'datetime',
        ];
    }
}
