<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->normalizePhone((string) $this->input('phone', '')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[0-9]{8,9}$/'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'No telefon mestilah dalam format 0123456789 atau 01123456789.',
        ];
    }

    /**
     * Normalize the phone number.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s-]+/', '', trim($phone)) ?? '';

        if (str_starts_with($phone, '+60')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '60')) {
            $phone = '0' . substr($phone, 2);
        }

        return $phone;
    }
}
