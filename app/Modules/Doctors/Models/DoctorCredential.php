<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Doctors\Enums\CredentialType;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Supporting document for professional verification (stored privately).
 *
 * @property string $id
 * @property string $doctor_id
 * @property CredentialType $type
 * @property string $title
 * @property string|null $issuer
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $review_status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 */
class DoctorCredential extends Model
{
    use HasUuids, SoftDeletes;
    use StoresUtcDates;

    protected $fillable = [
        'doctor_id', 'type', 'title', 'issuer', 'issued_on', 'expires_on',
        'file_path', 'file_name', 'mime_type', 'size_bytes', 'sha256',
    ];

    protected $hidden = ['file_path'];

    protected $attributes = ['review_status' => 'submitted'];

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
