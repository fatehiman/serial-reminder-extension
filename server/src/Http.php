<?php
declare(strict_types=1);

namespace SR;

/** Thin curl wrapper used by the new-episode checker. */
final class Http
{
    /** @return array{status:int, body:string, error:?string, url:string} */
    public static function get(string $url, array $headers = []): array
    {
        return self::request('GET', $url, null, $headers);
    }

    /** @return array{status:int, body:string, error:?string, url:string} */
    public static function request(string $method, string $url, ?string $body, array $headers = []): array
    {
        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = is_int($k) ? (string) $v : "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => (int) Config::get('http.timeout', 20),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => (string) Config::get('http.user_agent', 'serial-reminder/1.0'),
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $hdr,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $proxy = Config::get('http.proxy');
        if (is_string($proxy) && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $ca = Config::get('http.ca_bundle');
        if (is_string($ca) && $ca !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        // Development escape hatch for machines behind a TLS-inspecting proxy.
        // Leave this false on the server.
        if (Config::get('http.insecure_ssl', false) === true) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        return [
            'status' => $status,
            'body'   => is_string($out) ? $out : '',
            'error'  => $error,
            'url'    => $url,
        ];
    }

    /** POST a JSON body. Used for GraphQL APIs. */
    public static function postJson(string $url, array $payload, array $headers = []): ?array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return null;
        }
        $res = self::request('POST', $url, $body, [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ] + $headers);

        if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 300) {
            return null;
        }
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : null;
    }

    /** GET + json_decode. Returns null when the request or the parse failed. */
    public static function getJson(string $url, array $headers = []): ?array
    {
        $res = self::get($url, ['Accept' => 'application/json'] + $headers);
        if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 300) {
            return null;
        }
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : null;
    }
}
