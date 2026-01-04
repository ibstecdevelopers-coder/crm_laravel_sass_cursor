<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasDistanceCalculation;
class Applicant extends Model
{
    use HasFactory, SoftDeletes, HasDistanceCalculation;

    protected $table = 'applicants';
    protected $fillable = [
        'id',
        'applicant_uid',
        'user_id',
        'job_source_id',
        'job_category_id',
        'job_title_id',
        'job_type',
        'applicant_name',
        'applicant_email',
        'applicant_email_secondary',
        'applicant_postcode',
        'applicant_phone',
        'applicant_phone_secondary',
        'applicant_landline',
        'applicant_cv',
        'updated_cv',
        'applicant_notes',
        'applicant_experience',
        'lat',
        'lng',
        'gender',
        'dob',

        // Boolean flags
        'is_blocked',
        'is_temp_not_interested',
        'is_callback_enable',
        'is_no_job',
        'is_no_response',
        'is_in_nurse_home',
        'is_circuit_busy',
        'is_cv_in_quality',
        'is_cv_in_quality_clear',
        'is_cv_sent',
        'is_cv_in_quality_reject',
        'is_interview_confirm',
        'is_interview_attend',
        'is_in_crm_request',
        'is_in_crm_reject',
        'is_in_crm_request_reject',
        'is_crm_request_confirm',
        'is_crm_interview_attended',
        'is_in_crm_start_date',
        'is_in_crm_invoice',
        'is_in_crm_invoice_sent',
        'is_in_crm_start_date_hold',
        'is_in_crm_paid',
        'is_in_crm_dispute',
        'is_job_within_radius',
        'have_nursing_home_experience',

        // Status fields
        'status',
        'paid_status',
        'paid_timestamp',
        'deleted_at',
        'created_at',
        'updated_at'
    ];
    protected $casts = [
        'is_blocked' => 'boolean',
        'is_no_job' => 'boolean',
        'is_no_response' => 'boolean',
        'is_circuit_busy' => 'boolean',
        'is_cv_in_quality' => 'boolean',
        'is_cv_in_quality_clear' => 'boolean',
        'is_cv_sent' => 'boolean',
        'is_cv_in_quality_reject' => 'boolean',
        'is_interview_confirm' => 'boolean',
        'is_interview_attend' => 'boolean',
        'is_in_crm_request' => 'boolean',
        'is_in_crm_reject' => 'boolean',
        'is_in_crm_request_reject' => 'boolean',
        'is_crm_request_confirm' => 'boolean',
        'is_crm_interview_attended' => 'boolean',
        'is_in_crm_start_date' => 'boolean',
        'is_in_crm_invoice' => 'boolean',
        'is_in_crm_invoice_sent' => 'boolean',
        'is_in_crm_start_date_hold' => 'boolean',
        'is_in_crm_paid' => 'boolean',
        'is_in_crm_dispute' => 'boolean',
        // Add other casts as needed
    ];
    protected $hidden = [
        'deleted_at',
        'dob',
    ];
    public function scopeIgnoreBooleans($query)
    {
        $booleanColumns = [
            'is_blocked',
            'is_no_job',
            'is_no_response',
            'is_circuit_busy',
            'is_cv_in_quality',
            'is_cv_in_quality_clear',
            'is_cv_sent',
            'is_cv_in_quality_reject',
            'is_interview_confirm',
            'is_interview_attend',
            'is_in_crm_request',
            'is_in_crm_reject',
            'is_in_crm_request_reject',
            'is_crm_request_confirm',
            'is_crm_interview_attended',
            'is_in_crm_start_date',
            'is_in_crm_invoice',
            'is_in_crm_invoice_sent',
            'is_in_crm_start_date_hold',
            'is_in_crm_paid',
            'is_in_crm_dispute',
            'is_job_within_radius',
            'have_nursing_home_experience',
        ];

        foreach ($booleanColumns as $column) {
            $query->whereNull($column);
        }

        return $query;
    }
    public function scopeStatusWise($query, $status)
    {
        return $query->where('status', $status);
    }
    public function scopeWithExperience($query)
    {
        return $query->where('have_nursing_home_experience', true);
    }
    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->applicant_postcode ?? '-');
    }
    public function getFormattedApplicantNameAttribute()
    {
        return ucwords(strtolower($this->applicant_name));
    }
    public function getFormattedPhoneAttribute()
    {
        return $this->applicant_phone;
    }
    public function getFormattedLandlineAttribute()
    {
        return $this->applicant_landline;
    }
    public function getFormattedCvAttribute()
    {
        return $this->applicant_cv ? "<a href='" . asset('storage/' . $this->applicant_cv) . "' target='_blank'>View CV</a>" : 'No CV';
    }
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function jobSource()
    {
        return $this->belongsTo(JobSource::class, 'job_source_id');
    }
    public function jobCategory()
    {
        return $this->belongsTo(JobCategory::class, 'job_category_id');
    }
    public function jobTitle()
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }
    public function getJobTitleName()
    {
        return $this->jobTitle ? $this->jobTitle->name : '-';
    }
    public function getJobCategoryName()
    {
        return $this->jobCategory ? $this->jobCategory->name : '-';
    }
    public function getJobSourceName()
    {
        return $this->jobSource ? $this->jobSource->name : '-';
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
    public function module_note()
    {
        return $this->morphMany(ModuleNote::class, 'module_noteable');
    }
    public function crmNotes()
    {
        return $this->hasMany(CrmNote::class, 'applicant_id');
    }
    public function crmHistory()
    {
        return $this->hasMany(History::class)->where('stage', 'crm')->where('status', 1);
    }
    public function history()
    {
        return $this->hasMany(History::class, 'applicant_id');
    }
    public function crm_notes()
    {
        return $this->hasMany(CrmNote::class, 'applicant_id');
    }
    public function callback_notes()
    {
        return $this->hasMany(ApplicantNote::class)
            ->whereIn('moved_tab_to', ['callback', 'revert_callback'])
            ->orderBy('id', 'desc');
    }
    public function no_nursing_home_notes()
    {
        return $this->hasMany(ApplicantNote::class)
            ->whereIn('moved_tab_to', ['no_nursing_home', 'revert_no_nursing_home'])
            ->orderBy('id', 'desc');
    }
    public function cv_notes()
    {
        return $this->hasMany(CVNote::class, 'applicant_id', 'id');
    }
    public function pivotSales()
    {
        return $this->hasMany(ApplicantPivotSale::class, 'applicant_id');
    }
    public function history_request_nojob()
    {
        return $this->hasMany(History::class, 'applicant_id', 'id')
                    ->whereIn('sub_stage', ['quality_cleared_no_job', 'crm_no_job_request']); // Limit to 1 result
    }
    public function applicant_notes()
    {
        return $this->hasMany(ApplicantNote::class, 'applicant_id');
    }
    public function updated_by_audits()
    {
        return $this->morphMany(Audit::class, 'auditable')
            ->where('message', 'like', '%has been updated%')
            ->with('user');
    }

    public function created_by_audit()
    {
        return $this->morphOne(Audit::class, 'auditable')
            ->where('message', 'like', '%has been created%')
            ->with('user');
    }
    public function messages()
    {
        return $this->hasMany(Message::class, 'module_id')
            ->where('module_type', 'Horsefly\\Applicant');
    }
    public function qualityNotes()
    {
        return $this->hasMany(QualityNotes::class, 'applicant_id', 'id');
    }
}
