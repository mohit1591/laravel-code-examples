<?php

namespace App\Models;

use App\Jobs\HandleContentRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Debugbar;
use Str;

class ContentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_template_id',
        'workspace_id',
        'user_id',
        'service_id',
        'name',
        'description',
        'number',
        'max_output_length',
        'max_tokens',
        'tone',
        'language',
        'status',
        'custom_input',
        'brand_voice_id',
        'input_language',
        'output_language',
        'workflow_id',
        'batch_request_id',
        'folder_id',
        'resolution',
        'medium',
        'style',
        'mood',
        'uid',
        'input_file',
        'training_data_set_id',
    ];

    protected $casts = [
        'custom_input' => 'array',
    ];

    public $isSeeding = false;

    protected static function booted()
    {
        static::creating(function ($contentRequest) {

            $contentRequest->uid = Str::uuid();

            if (!$contentRequest->isSeeding) {
                if ($contentRequest->user_id == null) {
                    $contentRequest->user_id = auth()->user()->id;
                }
                if (!$contentRequest->workspace_id) {
                    $contentRequest->workspace_id = session()->get('workspace_id') ?? (request()->has('workspace_id') ? request()->get('workspace_id') : null);
                }
            }
        });

        static::created(function ($contentRequest) {
            if (!$contentRequest->isSeeding) {
                HandleContentRequest::dispatch($contentRequest)->onConnection('sync');
            }
        });

        static::deleting(function ($contentRequest) {
            $contentRequest->contentResults->each(function ($contentResult) {
                $contentResult->delete();
            });
        });
    }

    public function contentTemplate()
    {
        return $this->belongsTo(ContentTemplate::class);
    }

    public function contentResults()
    {
        return $this->hasMany(ContentResult::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function batchRequest()
    {
        return $this->belongsTo(BatchRequest::class);
    }

    public function trainingDataSet()
    {
        return $this->belongsTo(TrainingDataSet::class);
    }

    public function getHasTrainingAttribute()
    {
        return $this->trainingDataSet && $this->trainingDataSet->trainingRecords->count() > 0;
    }

    public function brandVoice()
    {
        return $this->belongsTo(BrandVoice::class);
    }
}
