<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
        if ($this->has('phone')) {
            $this->merge([
                'phone' => $this->normalizePhone((string) $this->input('phone', '')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = $this->user();
        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|regex:/^01[0-9]{8,9}$/|unique:users,phone,'.$user->id,
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'No telefon mestilah dalam format 0123456789 atau 01123456789.',
            'phone.unique' => 'Nombor telefon ini telah pun didaftarkan.',
            'name.required' => 'Sila masukkan nama penuh anda.',
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
