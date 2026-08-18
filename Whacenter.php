<?php

namespace WaNotif;

class Whacenter
{
    const BASE_URL = 'https://app.whacenter.com/api/';

    private string $deviceId;

    public function __construct(string $deviceId)
    {
        $this->deviceId = trim($deviceId);
    }

    public static function fromConfig(): ?self
    {
        $config = WaConfig::load();
        if (empty($config['device_id'])) return null;
        return new self($config['device_id']);
    }

    public static function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        if (str_starts_with($number, '0')) $number = '62' . substr($number, 1);
        elseif (str_starts_with($number, '8')) $number = '62' . $number;
        return $number;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL . $endpoint;
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method === 'GET' && !empty($data)) {
            $options[CURLOPT_URL] = $url . '?' . http_build_query($data);
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'body' => null, 'raw' => $error];
        }

        $body = json_decode($raw, true);
        $ok = is_array($body) && (($body['status'] ?? false) === true);
        return ['ok' => $ok, 'body' => $body, 'raw' => $raw];
    }

    public function send(string $number, string $message): array
    {
        if ($this->deviceId === '') {
            return ['ok' => false, 'body' => null, 'raw' => 'Device ID is empty'];
        }
        return $this->request('POST', 'send', [
            'device_id' => $this->deviceId,
            'number' => self::normalizeNumber($number),
            'message' => $message,
        ]);
    }

    public function status(): array
    {
        return $this->request('GET', 'statusDevice', ['device_id' => $this->deviceId]);
    }

    public function setWebhook(string $url): array
    {
        return $this->request('GET', 'setWebhook', [
            'device_id' => $this->deviceId,
            'webhook' => $url,
        ]);
    }
}
