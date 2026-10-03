<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\ContactIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public const MIN_AGE = 13;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $contact = trim((string) $this->input('contact', ''));
        $parsed = $contact !== '' ? ContactIdentifier::parse($contact) : null;

        $this->merge([
            'first_name' => trim((string) $this->input('first_name', '')),
            'last_name' => trim((string) $this->input('last_name', '')),
            'contact' => $contact,
            'email' => $parsed && $parsed['column'] === 'email' ? $parsed['value'] : null,
            'phone' => $parsed && $parsed['column'] === 'phone' ? $parsed['value'] : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.now()->subYears(self::MIN_AGE)->toDateString(),
                'after:1900-01-01',
            ],
            'gender' => ['required', Rule::in(User::GENDERS)],
            'contact' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'regex:'.ContactIdentifier::PHONE_PATTERN, Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => "What's your name?",
            'last_name.required' => "What's your name?",
            'birthday.required' => 'Select your birthday.',
            'birthday.date_format' => 'Select a valid date.',
            'birthday.before_or_equal' => 'You must be at least '.self::MIN_AGE.' years old to join denuwe.',
            'birthday.after' => 'Select a valid date.',
            'gender.required' => 'Select your gender.',
            'gender.in' => 'Select your gender.',
            'contact.required' => 'Enter a valid mobile number or email address.',
            'email.email' => 'Enter a valid mobile number or email address.',
            'email.unique' => 'An account with this email already exists.',
            'phone.regex' => 'Enter a valid mobile number or email address.',
            'phone.unique' => 'An account with this mobile number already exists.',
            'password.required' => 'Use at least 8 characters for your password.',
            'password.min' => 'Use at least 8 characters for your password.',
        ];
    }

    /**
     * Report email/phone problems on the `contact` field the form actually shows.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                foreach (['email', 'phone'] as $key) {
                    if ($errors->has($key)) {
                        foreach ($errors->get($key) as $message) {
                            $errors->add('contact', $message);
                        }
                        $errors->forget($key);
                    }
                }
            },
        ];
    }
}
