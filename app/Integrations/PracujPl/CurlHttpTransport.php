<?php

namespace App\Integrations\PracujPl;

final class CurlHttpTransport implements HttpTransport
{
    public function get(string $url, string $pinnedAddress): HttpResult
    {
        $host = parse_url($url, PHP_URL_HOST);
        $handle = curl_init($url);
        $body = '';
        $headers = [];
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => 'RecruitFlow Desktop/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: pl-PL,pl;q=0.9'],
            CURLOPT_RESOLVE => [$host.':443:'.$pinnedAddress],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (preg_match('/^HTTP\//', $line)) {
                    $headers = [];
                } elseif (($separator = strpos($line, ':')) !== false) {
                    $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 2_000_000) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($handle);
        curl_close($handle);
        if ($tooLarge) {
            throw new ImportException('response_too_large', 'Strona ogłoszenia przekracza bezpieczny limit 2 MB.', 502);
        }
        if ($ok === false) {
            $reason = $errorCode === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network_error';
            throw new ImportException($reason, $reason === 'timeout' ? 'Pobieranie ogłoszenia przekroczyło limit czasu.' : 'Nie można pobrać ogłoszenia.', 502);
        }

        return new HttpResult($status, $headers, $body);
    }
}
