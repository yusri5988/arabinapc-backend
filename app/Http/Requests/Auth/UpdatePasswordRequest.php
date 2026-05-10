<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'Pengesahan kata laluan tidak sepadan.',
            'password.min' => 'Kata laluan mestilah sekurang-kurangnya 6 aksara.',
            'current_password.required' => 'Sila masukkan kata laluan semasa anda.',
        ];
    }
}
