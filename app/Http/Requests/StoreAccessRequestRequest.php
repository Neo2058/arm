<?php

namespace App\Http\Requests;

use App\Models\AccessRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAccessRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tab_number' => trim(strip_tags((string) $this->input('tab_number', ''))),
            'fio' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->input('fio', '')))),
        ]);
    }

    public function rules(): array
    {
        return [
            'tab_number' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[0-9A-Za-z\-]+$/'],
            'fio' => ['required', 'string', 'min:5', 'max:150', 'regex:/^[\p{L}]+(?:[\s\-\.]+[\p{L}\.]+)+$/u'],
            'consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'tab_number.required' => 'Укажите табельный номер.',
            'tab_number.min' => 'Табельный номер должен содержать не меньше 4 символов.',
            'tab_number.max' => 'Табельный номер слишком длинный.',
            'tab_number.regex' => 'Табельный номер может содержать только цифры, латинские буквы и дефис.',
            'fio.required' => 'Укажите ФИО полностью.',
            'fio.min' => 'ФИО слишком короткое.',
            'fio.max' => 'ФИО слишком длинное.',
            'fio.regex' => 'Укажите фамилию и имя буквами. Допустимы пробелы, дефисы и точки.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tab = $this->string('tab_number')->toString();
            $fio = $this->string('fio')->toString();

            if (UserProfile::query()->where('tab_number', $tab)->exists()) {
                $validator->errors()->add(
                    'tab_number',
                    'Учётная запись с таким табельным номером уже есть. Войдите в систему или обратитесь к администратору.'
                );
            }

            $fioTaken = User::query()->select('id', 'name')->get()
                ->contains(fn (User $user) => mb_strtolower($user->name) === mb_strtolower($fio));

            if ($fioTaken) {
                $validator->errors()->add(
                    'fio',
                    'Пользователь с таким ФИО уже зарегистрирован. Войдите в систему или уточните данные у администратора.'
                );
            }

            if (AccessRequest::query()->where('tab_number', $tab)->where('status', 'pending')->exists()) {
                $validator->errors()->add(
                    'tab_number',
                    'Заявка с таким табельным номером уже находится на рассмотрении. Повторно подавать не нужно.'
                );
            }
        });
    }
}
