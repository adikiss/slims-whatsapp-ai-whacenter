<?php

namespace WaNotif;

class WaConfig
{
    public static function path(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'config.json';
    }

    public static function defaults(): array
    {
        return [
            'device_id' => '',
            'enable' => false,
            'notify_loan' => true,
            'notify_return' => true,
            'notify_extend' => true,
            'notify_overdue' => true,
            'notify_new_member' => true,
            'enable_chatbot' => true,
            'enable_webchat' => false,
            'enable_ai' => false,
            'ai_api_key' => '',
            'ai_model' => 'google/gemini-2.0-flash-001',
            'ai_base_url' => 'https://openrouter.ai/api/v1',
            'library_name' => '',
            'footer_text' => 'Harap simpan pesan ini sebagai bukti transaksi.',
            'webhook_url' => '',
        ];
    }

    public static function load(): array
    {
        $config = self::defaults();
        if (is_readable(self::path())) {
            $json = json_decode((string)file_get_contents(self::path()), true);
            if (is_array($json)) $config = array_merge($config, $json);
        }
        if (empty($config['library_name'])) {
            $config['library_name'] = $GLOBALS['sysconf']['library_name'] ?? 'Perpustakaan';
        }
        return $config;
    }

    public static function save(array $config): bool
    {
        $config = array_merge(self::defaults(), $config);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents(self::path(), $json . PHP_EOL) !== false;
    }

    public static function isPluginDirWritable(): bool
    {
        return is_writable(__DIR__);
    }
}
