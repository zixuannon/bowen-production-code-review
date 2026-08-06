<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Traits\DateFormatTrait;

class Leave extends Model
{
    use HasFactory, DateFormatTrait;

    // ---- Status constants (leaves.status) ----
    const STATUS_PENDING   = 0;
    const STATUS_APPROVED  = 1;
    const STATUS_REJECTED  = 2;
    const STATUS_WITHDRAWN = 3;

    // ---- Approval sub-status (supervisor_status / hr_status) ----
    const APPROVAL_PENDING   = 0;
    const APPROVAL_APPROVED  = 1;
    const APPROVAL_REJECTED  = 2;

    protected $fillable = [
        'user_id',
        'reason',
        'from_date',
        'to_date',
        'status',
        'school_id',
        'leave_master_id',
        // Two-stage approval fields (Phase 1)
        'supervisor_status',
        'supervisor_comment',
        'supervisor_user_id',
        'supervisor_reviewed_at',
        'hr_status',
        'hr_comment',
        'hr_user_id',
        'hr_reviewed_at',
        'withdrawn_at',
    ];

    /**
     * The attributes that should be treated as dates.
     *
     * @var array
     */
    protected $dates = [
        'supervisor_reviewed_at',
        'hr_reviewed_at',
        'withdrawn_at',
    ];

    public function scopeOwner()
    {
        if (Auth::user()) {
            return $this->where('school_id', Auth::user()->school_id);
        }
    }

    /**
     * Get the user that owns the Leave
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get all of the leave_detail for the Leave
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function leave_detail()
    {
        return $this->hasMany(LeaveDetail::class);
    }

    public function attendance()
    {
        return $this->hasMany(StaffAttendance::class, 'leave_id');
    }

    public function getCreatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('created_at'));
    }

    /**
     * Get the leave_master that owns the Leave
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function leave_master()
    {
        return $this->belongsTo(LeaveMaster::class);
    }

    public function file()
    {
        return $this->morphMany(File::class, 'modal');
    }

    /**
     * Get the supervisor who approved/rejected this leave.
     */
    public function supervisorApprover()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id')->withTrashed();
    }

    /**
     * Get the HR who approved/rejected this leave.
     */
    public function hrApprover()
    {
        return $this->belongsTo(User::class, 'hr_user_id')->withTrashed();
    }

    public function getUpdatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('updated_at'));
    }

    public function getFromDateAttribute($value)
    {
        return $this->formatDateValue($value);
    }

    public function getToDateAttribute($value)
    {
        return $this->formatDateValue($value);
    }


}
