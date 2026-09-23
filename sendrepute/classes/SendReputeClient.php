<?php

final class SendReputeClient
{
    const MAX_RESPONSE = 262144;

    public static function request($method, $path, ?array $body = null)
    {
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match('#^/v1/[A-Za-z0-9/_-]*$#D', $path)) {
            throw new RuntimeException('Invalid SendRepute request.');
        }
        $base = getenv('SENDREPUTE_API_BASE');
        $token = getenv('SENDREPUTE_API_TOKEN');
        if (!is_string($base) || !is_string($token) || trim($token) === '') {
            throw new RuntimeException('SendRepute server environment is not configured.');
        }
        $origin = self::verifiedOrigin(trim($base));
        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new RuntimeException('SendRepute request encoding failed.');
            }
        }
        $ch = curl_init($origin['url'] . $path);
        if ($ch === false) {
            throw new RuntimeException('SendRepute transport could not be initialized.');
        }
        $raw = '';
        $tooLarge = false;
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . trim($token)];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $configured = curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $origin['resolve'],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$raw, &$tooLarge) {
                if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (!$configured) {
            curl_close($ch);
            throw new RuntimeException('SendRepute transport could not be configured.');
        }
        $executed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) {
            throw new RuntimeException('SendRepute response was too large.');
        }
        if ($executed !== true) {
            throw new RuntimeException('SendRepute transport failed: ' . self::safeError($error));
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('SendRepute API rejected the request (HTTP ' . $status . ').');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('SendRepute returned invalid JSON.');
        }
        return $decoded;
    }

    private static function verifiedOrigin($base)
    {
        if (strlen($base) > 2048 || preg_match('/[\\x00-\\x20\\x7f]/', $base)) {
            throw new RuntimeException('Invalid SendRepute API base URL.');
        }
        $parts = parse_url($base);
        if (!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new RuntimeException('SendRepute API base must be a canonical HTTPS origin.');
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535 || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('SendRepute API host must be a public DNS name.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) {
            throw new RuntimeException('SendRepute API host did not resolve.');
        }
        $resolve = [];
        foreach ($records as $record) {
            $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('SendRepute API host resolved to a non-public address.');
            }
            $resolve[] = $host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
        }
        $authority = $host . ($port === 443 ? '' : ':' . $port);
        return ['url' => 'https://' . $authority, 'resolve' => array_values(array_unique($resolve))];
    }

    private static function safeError($error)
    {
        return preg_replace('/[^A-Za-z0-9 .:_-]/', '', substr((string) $error, 0, 160));
    }

    public static function classification(array $response)
    {
        $top = ['requestId', 'model', 'result', 'billing'];
        if (array_diff(array_keys($response), $top)
            || !is_string(isset($response['requestId']) ? $response['requestId'] : null)
            || $response['requestId'] === '' || strlen($response['requestId']) > 128
            || !in_array(isset($response['model']) ? $response['model'] : null, ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'], true)
            || !isset($response['result']) || !is_array($response['result'])
            || !isset($response['billing']) || !is_array($response['billing'])) {
            throw new RuntimeException('Classification response is incomplete.');
        }
        $r = $response['result'];
        $required = ['label', 'spamProbability', 'confidence', 'reasons', 'flaggedTerms', 'analyzedFields', 'modelVersion', 'analyzedAt'];
        $allowed = array_merge($required, ['flaggedTermCount', 'contentAudit']);
        $billing = $response['billing'];
        if (array_diff(array_keys($r), $allowed) || array_diff($required, array_keys($r))
            || !in_array($r['label'], ['inbox', 'spam'], true)
            || !is_int($r['spamProbability']) && !is_float($r['spamProbability'])
            || !is_finite((float) $r['spamProbability'])
            || $r['spamProbability'] < 0 || $r['spamProbability'] > 1
            || !in_array($r['confidence'], ['low', 'medium', 'high'], true)
            || !self::validReasons($r['reasons'])
            || !self::stringList($r['flaggedTerms'], 1000)
            || !self::stringList($r['analyzedFields'], 20)
            || !is_string($r['modelVersion']) || $r['modelVersion'] === '' || strlen($r['modelVersion']) > 128
            || !is_string($r['analyzedAt']) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/D', $r['analyzedAt'])
            || isset($r['flaggedTermCount']) && (!is_int($r['flaggedTermCount']) || $r['flaggedTermCount'] < 0)
            || isset($r['contentAudit']) && !is_array($r['contentAudit'])
            || array_diff(array_keys($billing), ['chargedMillicents', 'replayed'])
            || !isset($billing['chargedMillicents']) || !is_int($billing['chargedMillicents']) || $billing['chargedMillicents'] < 0
            || !isset($billing['replayed']) || !is_bool($billing['replayed'])) {
            throw new RuntimeException('Classification response fields are invalid.');
        }
        return [
            'label' => $r['label'],
            'probability' => (float) $r['spamProbability'],
            'confidence' => $r['confidence'],
            'charged_millicents' => $billing['chargedMillicents'],
            'replayed' => $billing['replayed'],
        ];
    }

    private static function stringList($value, $maximum)
    {
        if (!is_array($value) || array_values($value) !== $value || count($value) > $maximum) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) > 4096) {
                return false;
            }
        }
        return true;
    }

    private static function validReasons($value)
    {
        if (!is_array($value) || array_values($value) !== $value || count($value) > 100) {
            return false;
        }
        foreach ($value as $reason) {
            if (!is_array($reason) || count($reason) !== 3
                || array_diff(array_keys($reason), ['signal', 'detail', 'weight'])
                || !is_string(isset($reason['signal']) ? $reason['signal'] : null) || strlen($reason['signal']) > 256
                || !is_string(isset($reason['detail']) ? $reason['detail'] : null) || strlen($reason['detail']) > 4096
                || (!is_int(isset($reason['weight']) ? $reason['weight'] : null) && !is_float(isset($reason['weight']) ? $reason['weight'] : null))
                || !is_finite((float) $reason['weight'])) {
                return false;
            }
        }
        return true;
    }

    public static function pricing(array $response)
    {
        foreach (['classificationBaseMillicents', 'additionalTermMillicents', 'maximumClassificationMillicents'] as $field) {
            if (!isset($response[$field]) || !is_int($response[$field]) || $response[$field] < 0) {
                throw new RuntimeException('Pricing response fields are invalid.');
            }
        }
        return $response;
    }
}