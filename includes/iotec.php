<?php
declare(strict_types=1);

/**
 * iotec Pay client — mobile money collection (MTN MoMo / Airtel Money,
 * routed automatically by iotec from the payer's phone number, so obitickets
 * never has to know or choose which network a number belongs to).
 * Ported from Obin Academy's own includes/iotec.php, which uses the same
 * ioTec Pay wallet in production — see https://iotec.io/api-docs/pay.
 */

const IOTEC_TOKEN_URL = 'https://id.iotec.io/connect/token';
const IOTEC_COLLECT_URL = 'https://pay.iotec.io/api/collections/collect';
const IOTEC_STATUS_URL = 'https://pay.iotec.io/api/collections/status';

class IotecException extends RuntimeException
{
}

function iotec_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '256')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '256' . substr($digits, 1);
    }
    return '256' . $digits;
}

/** @return array{0:int,1:string} [HTTP status, raw response body] */
function iotec_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new IotecException("iotec request failed: $error");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $responseBody];
}

function iotec_get_access_token(): string
{
    if (!IOTEC_CLIENT_ID || !IOTEC_CLIENT_SECRET) {
        throw new IotecException('iotec is not configured — missing IOTEC_CLIENT_ID / IOTEC_CLIENT_SECRET.');
    }

    [$status, $body] = iotec_http('POST', IOTEC_TOKEN_URL,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => IOTEC_CLIENT_ID,
            'client_secret' => IOTEC_CLIENT_SECRET,
        ])
    );

    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec token request failed ($status): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['access_token'])) {
        throw new IotecException("iotec token response missing access_token: $body");
    }
    return $data['access_token'];
}

/** @return array{transactionId: string} */
function iotec_initiate_collection(float $amount, string $phone, string $reference, string $note): array
{
    if (!IOTEC_WALLET_ID) {
        throw new IotecException('iotec is not configured — missing IOTEC_WALLET_ID.');
    }

    $token = iotec_get_access_token();

    [$status, $body] = iotec_http('POST', IOTEC_COLLECT_URL, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], json_encode([
        'category' => 'MobileMoney',
        'currency' => 'UGX',
        'walletId' => IOTEC_WALLET_ID,
        'externalId' => $reference,
        'payer' => iotec_normalize_phone($phone),
        'amount' => (int) round($amount),
        'payerNote' => $note,
        'payeeNote' => $note,
        'channel' => 'WEB',
    ]));

    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec collection request failed ($status): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['id'])) {
        throw new IotecException("iotec collection response missing transaction id: $body");
    }
    return ['transactionId' => $data['id']];
}

/** @return array{status: string, statusMessage: ?string} */
function iotec_check_collection_status(string $transactionId): array
{
    $token = iotec_get_access_token();

    [$status, $body] = iotec_http('GET', IOTEC_STATUS_URL . '/' . urlencode($transactionId), [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec status check failed ($status): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['status'])) {
        throw new IotecException("iotec status response missing status field: $body");
    }
    return ['status' => $data['status'], 'statusMessage' => $data['statusMessage'] ?? null];
}
