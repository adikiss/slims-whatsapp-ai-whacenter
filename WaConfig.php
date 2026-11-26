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
            'mcp_enable' => false,
            'mcp_token' => '',
            // Tool MCP send_whatsapp (mengirim pesan) — harus diaktifkan secara terpisah
            'mcp_allow_send' => false,
            // Notifikasi terjadwal (cron.php)
            'cron_enable' => false,
            'cron_token' => '',
            'cron_channel_wa' => true,
            'cron_channel_email' => false,
            'cron_min_days' => 1,
            'cron_repeat_days' => 7,
            'cron_max_per_run' => 50,
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
        // Token MCP dibuat otomatis sekali agar plugin langsung siap pakai
        if (empty($config['mcp_token'])) {
            $config['mcp_token'] = bin2hex(random_bytes(16));
            if (self::isPluginDirWritable()) self::save($config);
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
