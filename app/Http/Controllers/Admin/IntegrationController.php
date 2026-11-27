<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AlphaSmsException;
use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\AlphaSms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function show(): View
    {
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();

        return view('admin.integrations', [
            'sender' => $settings->sender,
            'hasKey' => $settings->getRawOriginal('api_key') !== null,
            'updatedAt' => $settings->updated_at,
            'canUpdate' => Gate::allows('integrations.alphasms.update'),
            'diagnostics' => $settings,
            'sendSms' => config('mobile_auth.send_sms'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sender' => ['required', 'string', 'max:11', 'regex:/\A[A-Za-z0-9 ._-]+\z/'],
            'api_key' => ['nullable', 'string', 'max:512', 'regex:/\A[\x21-\x7e]+\z/'],
            'admin_password' => ['bail', 'required', 'string', 'regex:/\A[^\x00]{1,72}\z/', 'current_password:admin'],
        ], [
            'sender.required' => 'Введите имя отправителя.',
            'sender.max' => 'В имени отправителя может быть до 11 символов.',
            'sender.regex' => 'Используйте латинские буквы, цифры, пробелы, точки, дефисы или подчёркивания.',
            'api_key.regex' => 'Введите ключ одной строкой без пробелов.',
            'admin_password.current_password' => 'Пароль администратора не совпадает.',
            'admin_password.required' => 'Подтвердите изменение паролем администратора.',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $settings = IntegrationSetting::query()->where('provider', 'alphasms')->lockForUpdate()->firstOrFail();
            $apiKey = $data['api_key'] ?? null;

            if ($apiKey === null && $settings->getRawOriginal('api_key') === null) {
                throw ValidationException::withMessages(['api_key' => 'Введите API-ключ AlphaSMS.']);
            }

            $settings->sender = $data['sender'];

            if ($apiKey !== null && ! hash_equals((string) $settings->api_key, $apiKey)) {
                $settings->api_key = $apiKey;
            }

            $changedFields = array_keys($settings->getDirty());

            if ($changedFields !== []) {
                if (in_array('api_key', $changedFields, true)) {
                    $settings->balance_amount = null;
                    $settings->balance_currency = null;
                    $settings->balance_checked_at = null;
                    $settings->last_sms_request_id = null;
                    $settings->last_sms_accepted_at = null;
                    $settings->last_sms_delivery_status = null;
                }
                $settings->save();

                DB::table('integration_audit_events')->insert([
                    'admin_user_id' => $request->user('admin')->getAuthIdentifier(),
                    'integration_setting_id' => $settings->getKey(),
                    'changed_fields' => json_encode($changedFields, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });

        return to_route('admin.integrations.show')->with('status', 'Настройки AlphaSMS сохранены.');
    }

    public function check(AlphaSms $sms): RedirectResponse
    {
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();

        try {
            $sms->refreshBalance($settings);
        } catch (AlphaSmsException $exception) {
            $message = match ($exception->reason) {
                'settings_missing' => 'Сначала сохраните ключ AlphaSMS.',
                'unreachable', 'rate_limited' => 'AlphaSMS недоступен. Повторите проверку позже.',
                default => 'AlphaSMS не подтвердил подключение. Проверьте ключ и настройки кабинета.',
            };

            return to_route('admin.integrations.show')->withErrors(['connection' => $message]);
        }

        return to_route('admin.integrations.show')->with('status', 'Баланс AlphaSMS обновлён.');
    }
}
