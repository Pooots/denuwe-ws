<?php

namespace App\Http\Requests\Feed;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:3000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'repost_of_id' => ['nullable', 'integer', 'exists:posts,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'tournament_id' => ['nullable', 'integer', 'exists:tournaments,id', 'prohibits:repost_of_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'Photos can be up to 5 MB.',
            'image.mimes' => 'Use a JPG, PNG, GIF or WEBP photo.',
            'club_id.exists' => 'That club or community doesn’t exist anymore.',
            'tournament_id.exists' => 'That tournament doesn’t exist anymore.',
            'tournament_id.prohibits' => 'Share a tournament or repost a post, not both.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (blank($this->input('body')) && ! $this->hasFile('image') && blank($this->input('repost_of_id')) && blank($this->input('tournament_id'))) {
                    $validator->errors()->add('body', 'Write something or add a photo to post.');
                }
            },
        ];
    }
}
