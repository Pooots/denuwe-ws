<?php

namespace App\Http\Requests\Profile;

use App\Support\ContactIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    private const TEXT_FIELDS = [
        'first_name',
        'last_name',
        'headline',
        'pronouns',
        'location',
        'bio',
        'website',
        'contact_email',
        'contact_phone',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (self::TEXT_FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $clean[$field] = is_string($value) ? (trim($value) ?: null) : $value;
            }
        }

        $website = $clean['website'] ?? null;
        if (is_string($website) && ! preg_match('#^https?://#i', $website)) {
            $clean['website'] = 'https://'.$website;
        }

        if (is_string($clean['contact_email'] ?? null)) {
            $clean['contact_email'] = ContactIdentifier::normalizeEmail($clean['contact_email']);
        }
        if (is_string($clean['contact_phone'] ?? null)) {
            $clean['contact_phone'] = ContactIdentifier::normalizePhone($clean['contact_phone']);
        }

        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'headline' => ['nullable', 'string', 'max:160'],
            'pronouns' => ['nullable', 'string', 'max:32'],
            'location' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'regex:'.ContactIdentifier::PHONE_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Enter your first name.',
            'last_name.required' => 'Enter your last name.',
            'website.url' => 'Enter a valid website address.',
            'contact_email.email' => 'Enter a valid email address.',
            'contact_phone.regex' => 'Enter a valid phone number, e.g. 09171234567 or +639171234567.',
        ];
    }
}
