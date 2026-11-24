<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    private const string ALPHASMS_BALANCE_URL = 'https://api.alphasms.ua/api/json.php';

    public function show(): View
    {
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();

        return view('admin.integrations', [
            'sender' => $settings->sender,
            'hasKey' => $settings->getRawOriginal('api_key') !== null,
            'updatedAt' => $settings->updated_at,
            'canUpdate' => Gate::allows('integrations.alphasms.update'),
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

    public function check(): RedirectResponse
    {
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $apiKey = $settings->api_key;

        if ($apiKey === null) {
            return to_route('admin.integrations.show')->withErrors(['connection' => 'Сначала сохраните ключ AlphaSMS.']);
        }

        try {
            $response = Http::asJson()->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post(self::ALPHASMS_BALANCE_URL, ['auth' => $apiKey, 'data' => [['type' => 'balance']]]);

            if ($response->successful() && $response->json('success') === true && $response->json('data.0.success') === true) {
                return to_route('admin.integrations.show')->with('status', 'AlphaSMS принял ключ. Подключение работает.');
            }
        } catch (ConnectionException) {
            return to_route('admin.integrations.show')->withErrors(['connection' => 'AlphaSMS недоступен. Повторите проверку позже.']);
        }

        return to_route('admin.integrations.show')->withErrors(['connection' => 'AlphaSMS не подтвердил подключение. Проверьте ключ и настройки кабинета.']);
    }
}
