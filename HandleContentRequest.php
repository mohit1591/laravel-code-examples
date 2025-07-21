<?php

namespace App\Jobs;

use App\Clients\Claude;
use App\Clients\ElevenLabs;
use App\Clients\Gemini;
use App\Clients\Leonardo;
use App\Clients\OpenAI;
use App\Clients\StabilityAI;
use App\Clients\StableDiffusion;
use App\Models\ContentRequest;
use App\Models\ContentResult;
use App\Models\Customer;
use App\Models\Setting;
use App\Notifications\GeneralNotification;
use Blade;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Image;
use phpDocumentor\Reflection\Types\Parent_;
use Soundasleep\Html2Text;

class HandleContentRequest extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Giving a timeout of 20 minutes to the Job to process
    public $timeout = 1200;

    protected ContentRequest $contentRequest;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(ContentRequest $contentRequest)
    {
        $this->contentRequest = $contentRequest;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $contentRequest = $this->contentRequest;
        $template = $contentRequest->contentTemplate;
        $endpoint = $template->endpoint;
        $model = $template->api_model;

        if ($contentRequest->batchRequest && $template->batch_model) {
            $model = $template->batch_model;
        }

        $workspace = $this->contentRequest->workspace;

        if ($workspace->entity_type === Customer::class) {
            $customer = $workspace->entity;
        } else {
            $customer = $workspace->entity->customer;
        }

        if ($template->max_blocks) {
            if ($template->number > $template->max_blocks) {
                $this->fail(new \Exception('Max blocks exceeded.'));
                return;
            }
        }

        if (!$this->checkAllotment($template->type, $customer)) {
            return;
        }

        // prepare API request based on which model/platform we are using
        switch ($template->type) {
            case 'text':
                $input = $this->buildTextPrompt($contentRequest, $template, $customer);
                break;
            case 'audio':
                $input = $this->buildAudioPrompt($contentRequest, $template, $customer);
                break;
            case 'image':
                $input = $this->buildImagePrompt($contentRequest, $template, $customer);
                break;
            default:
                $this->fail(new \Exception('Invalid template type.'));
                return;
        }

        if (!$input) {
            $this->fail(new \Exception('Invalid input.'));
            return;
        }

        $this->contentRequest->status = 2;
        $this->contentRequest->save();

        // if the customer has trained data, put this request through langchain instead
        if ($template->type == 'text' && $this->contentRequest->has_training) {

            $this->handleRequestWithTraining($contentRequest, $template, $input, $model);
            return;
        }

        switch ($template->provider) {
            case 'stable-diffusion':
                $client = new StableDiffusion($endpoint, $model, $input);
                $client->setNegativePrompt($contentRequest->custom_fields->negative_prompt ?? null);
                break;
            case 'openai':
                $client = new OpenAI($endpoint, $model, $input);
                $client->setMaxTokens($contentRequest->max_tokens);
                $client->setTemperature($template->temperature / 100);

                if ($template->type == 'text') {

                    $client->setFrequencyPenalty($template->frequency_penalty);
                    $client->setPresencePenalty($template->presence_penalty);

                    if ($template->api_model == 'gpt-4-vision-preview') {

                        $images = [];

                        foreach ($template->templateFields as $field) {
                            if ($field->type == 'image') {
                                $images[] = [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $contentRequest->custom_input[$field->key],
                                    ]
                                ];

                            }
                        }

                        $content = [
                            [
                                'type' => 'text',
                                'text' => $input,
                            ],
                        ];

                        $content = array_merge($content, $images);

                        $client->setPrompt($content);
                    }
                }
                // we use this as a prompt for audio conversions as well as text generation
                if ($template->input_prep_text) {
                    $client->setInputPrepText($template->input_prep_text);
                }
                if ($template->system_message) {
                    $client->setSystemMessage($template->system_message);
                }
                break;
            case 'stability-ai':
                $client = new StabilityAI($endpoint, $model, $input);
                break;
            case 'leonardo':
                $client = new Leonardo($endpoint, $model, $input);
                break;
            case 'eleven-labs':
                $client = new ElevenLabs($endpoint, $model, $input);
                $client->setVoice($contentRequest->custom_input['voice'] ?? null);
                break;
            case 'claude':
                $client = new Claude($endpoint, $model, $input);

                $client->setMaxTokens($contentRequest->max_tokens);
                $client->setTemperature($template->temperature / 100);

                // Anthropic doesn't allow two messages from the same user back to back, so we combine instead.
                if ($template->input_prep_text) {
                    $input = $template->input_prep_text . '\n\n ' . $input;
                    $client->setPrompt($input);
                }

                if ($template->system_message) {
                    $client->setSystemMessage($template->system_message);
                }

                break;

            case 'gemini':

                $client = new Gemini($endpoint, $model, $input);

                $client->setTemperature($template->temperature / 100);
                $client->setMaxTokens($contentRequest->max_tokens);

                if ($template->input_prep_text) {
                    $client->setInputPrepText($template->input_prep_text);
                }

                if ($template->system_message) {
                    $client->setSystemMessage($template->system_message);
                }

                break;
            default:
                $this->fail(new \Exception('Invalid provider.'));
                return;
        }

        if ($template->type == 'image') {
            $client->setResolution($contentRequest->resolution);
        }

        // since the input is the same for all content results, we can just use the same client for all of them
        for ($i = 0; $i < $contentRequest->number; $i++) {
            GenerateContentResult::dispatch($client, $contentRequest)->onConnection('sync');
        }
    }

    public function buildTextPrompt($contentRequest, $template, $customer)
    {
        if ($contentRequest->batchRequest && $template->batch_input) {
            $input = $template->batch_input;
        } else {
            $input = $template->input_text;
        }

        $input = $this->renderPromptBlade($input, $contentRequest, $customer);

        return $input;
    }

    public function buildAudioPrompt($contentRequest, $template, $customer)
    {
        if ($template->endpoint == 'text-to-speech') {
            $input = $template->input_text;

            $input = $this->renderPromptBlade($input, $contentRequest, $customer, false);
        } else { // audio conversion
            $input = storage_path('app/public_auth/' . $contentRequest->input_file);
        }

        return $input;

    }

    public function buildImagePrompt($contentRequest, $template, $customer)
    {
        if ($template->endpoint == 'image-generation') {

            $input = $template->input_text;

            $input = $this->renderPromptBlade($input, $contentRequest, $customer, false);

        } else { // upscale / mask
            $input = storage_path('app/public_auth/' . $contentRequest->input_file);
        }

        return $input;
    }

    protected function renderPromptBlade($input, $contentRequest, $customer, $affix = true)
    {
        if ($affix) {
            if ($prefix = Setting::where('name', 'template_prefix')->first()) {
                $input = $prefix->value . ' ' . $input;
            }

            if ($suffix = Setting::where('name', 'template_suffix')->first()) {
                $input = $input . ' ' . $suffix->value;
            }
        }

        $template = $contentRequest->contentTemplate;

        foreach($template->templateFields as $field) {
            if (isset($field->appended_prompt)) {
                $input .= "\n" . $field->appended_prompt;
            }
        }

        $business_name = $customer->business_name ?? '';
        $business_description = $customer->business_description ?? '';
        $business_street = $customer->business_street ?? '';
        $business_city = $customer->business_city ?? '';

        $replacements = [
            'name' => $contentRequest->name,
            'description' => $contentRequest->description,
            'tone' => $contentRequest->tone,
            'input_language' => $contentRequest->input_language,
            'output_language' => $contentRequest->output_language,
            'business_name' => $business_name,
            'business_description' => $business_description,
            'business_street' => $business_street,
            'business_city' => $business_city,
            'style' => $contentRequest->style,
            'medium' => $contentRequest->medium,
            'mood' => $contentRequest->mood,
            'resolution' => $contentRequest->resolution,
            'brand_voice' => '',
        ];

        // add additional fields from service record
        if ($contentRequest->service) {
            $replacements['product_url'] = $contentRequest->service->product_url;
            $replacements['product_audience'] = $contentRequest->service->ideal_customer;
            $replacements['product_benefits'] = $contentRequest->service->customer_wants;
        }

        if($contentRequest->brandVoice) {
            $replacements['brand_voice'] = $contentRequest->brandVoice->internal_description;
        }

        if ($contentRequest->custom_input != null) {
            $replacements = array_merge($replacements, $contentRequest->custom_input);
        }

        $input = Blade::render($input, $replacements);

        \Log::debug('Prompt with appended from field: ' . $input);

        return $input;
    }

    protected function checkAllotment($type, $customer)
    {
        switch ($type) {
            case 'text':
                $permitted = $this->checkAllottedWords($this->contentRequest->contentTemplate->get30DayAvgWords(), $customer);
                if (!$permitted) {
                    $this->contentRequest->status = 0;
                    $this->contentRequest->save();
                    $this->contentRequest->user->notify((new GeneralNotification([
                        'type' => 'words-exceeded',
                        'subject' => __('notification.Words Exceeded'),
                        'message' => __('notification.You have exceeded your allotted word limit.'),
                        'action' => route('front.plans'),
                        'archived' => 0,

                    ]))->locale(app()->getLocale()));
                    $this->fail(new \Exception(__('notification.Customer has exceeded their allotted word limit.')));
                    return false;
                }
                break;
            case 'image':
                $permitted = $this->checkAllottedImages($customer);
                if (!$permitted) {
                    $this->contentRequest->status = 0;
                    $this->contentRequest->save();
                    $this->contentRequest->user->notify((new GeneralNotification([
                        'type' => 'images-exceeded',
                        'subject' => __('notification.Images Exceeded'),
                        'message' => __('notification.You have exceeded your allotted limit of image generations.'),
                        'action' => route('front.plans'),
                        'archived' => 0,

                    ]))->locale(app()->getLocale()));
                    $this->fail(new \Exception(__('notification.Customer has exceeded their allotted images limit.')));
                    return false;
                }
                break;

            case 'audio':
                $permitted = $this->checkAllotedSpeechToText($customer);
                if (!$permitted) {
                    $this->contentRequest->status = 0;
                    $this->contentRequest->save();
                    $this->contentRequest->user->notify((new GeneralNotification([
                        'type' => 'speechtotext-exceeded',
                        'subject' => __('notification.Speech To Text Exceeded'),
                        'message' => __('notification.You have exceeded your allotted limit of speech to text conversions.'),
                        'action' => route('front.plans'),
                        'archived' => 0,

                    ]))->locale(app()->getLocale()));
                    $this->fail(new \Exception(__('notification.Customer has exceeded their allotted speech to text limit.')));
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * TODO: better error handling
     * @param $exception
     * @return mixed
     */
    public function fail($exception = null)
    {
        throw $exception;
    }

    private function handleRequestWithTraining(ContentRequest $contentRequest, mixed $template, string $input, mixed $model)
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => config('services.langchain-trainer.base_url'),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.langchain-trainer.api_key'),
                'Accept' => 'application/json',
            ],
        ]);

        $customer = $contentRequest->workspace->entity_type === Customer::class ? $contentRequest->workspace->entity : $contentRequest->workspace->entity->customer;

        for ($i = 0; $i < $contentRequest->number; $i++) {
            try {
                $response = $client->post('/query', [
                    'json' => [
                        'index_name' => $contentRequest->trainingDataSet->index_name,
                        'prompt' => $input,
                        'model' => $model,
                        'provider' => $template->provider,
                        'max_tokens' => $contentRequest->max_tokens,
                        'temperature' => $template->temperature / 100,
                        'prep_text' => $template->input_prep_text,
                        'system_message' => $template->system_message,
                    ],
                ]);
            } catch (\Exception $e) {
                $this->fail($e);
            }

            $result = json_decode($response->getBody()->getContents(), true);

            if(!$result || !isset($result['response'])) {
                $this->fail(new \Exception('Invalid response from trainer.'));
            }

            $words = Str::of($result['response'])->wordCount();

            if ($template->parse_markdown) {
                //$result['response'] = (new \Parsedown())->setBreaksEnabled(false)->text($result['response']);
                $result['response'] = (new \Spatie\LaravelMarkdown\MarkdownRenderer())->toHtml($result['response']);
                $result['response'] = str_replace("\n", " ", $result['response']);

            }

            ContentResult::create([
                'status' => 1,
                'content_request_id' => $this->contentRequest->id,
                'result' => $result['response'],
                'words' => $words,
                'input' => $input,
                'tokens_used' => 0, // TODO: get tokens used
                'folder_id' => $contentRequest->folder_id,
                'expires_at' => Carbon::now()->addYear(),
            ]);
        }


    }
}
