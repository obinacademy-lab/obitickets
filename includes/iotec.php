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

/**
 * amount and charge are extra, optional fields (null when ioTec does not send them): OB Gifts uses them to check the paid amount
 * and to record the fee. Tickets and Socials only read status and statusMessage, so nothing changes for them.
 * @return array{status: string, statusMessage: ?string, amount: ?float, charge: ?float}
 */
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
    $charge = $data['totalTransactionCharge'] ?? (isset($data['transactionCharge']) ? (float) $data['transactionCharge'] + (float) ($data['vendorCharge'] ?? 0) : null);
    return [
        'status' => $data['status'],
        'statusMessage' => $data['statusMessage'] ?? null,
        'amount' => isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null,
        'charge' => $charge !== null && is_numeric($charge) ? (float) $charge : null,
    ];
}

// ---------------------------------------------------------------------------
// Disbursements: sending money OUT of the ioTec Pay wallet to a mobile money number (organizer payouts).
// Same OAuth token and wallet as collections. https://pay.iotec.io/api/disbursements/disburse
// ---------------------------------------------------------------------------
const IOTEC_DISBURSE_URL = 'https://pay.iotec.io/api/disbursements/disburse';
const IOTEC_DISBURSE_STATUS_URL = 'https://pay.iotec.io/api/disbursements/status';
const IOTEC_DISBURSE_EXTERNAL_URL = 'https://pay.iotec.io/api/disbursements/external-id';

/** Thrown when ioTec clearly REFUSED the request (nothing was sent), so the payout can be marked failed safely. */
class IotecRefusedException extends IotecException
{
}

/** The wallet currency for payouts: UGX live; ioTec's test wallet uses ITX (set IOTEC_CURRENCY in the test config). */
function iotec_payout_currency(): string
{
    return defined('IOTEC_CURRENCY') && IOTEC_CURRENCY ? (string) IOTEC_CURRENCY : 'UGX';
}

/**
 * Sends $amount to a mobile money number. $externalId is OUR unique reference for this payout.
 * Returns ['transactionId' => ..., 'status' => ...]. A 4xx answer means ioTec refused it (IotecRefusedException:
 * safe to mark the payout failed). A network error or 5xx is an UNKNOWN outcome (plain IotecException): the money
 * may have gone, so the payout must stay "processing" until ioTec confirms.
 */
function iotec_disburse(float $amount, string $phone, string $externalId, string $note): array
{
    try {
        $token = iotec_get_access_token();
    } catch (IotecException $e) {
        throw new IotecRefusedException('could not sign in to ioTec, nothing was sent: ' . $e->getMessage());   // before any money request, so safe to fail
    }
    [$status, $body] = iotec_http('POST', IOTEC_DISBURSE_URL, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], json_encode([
        'category' => 'MobileMoney',
        'currency' => iotec_payout_currency(),
        'walletId' => IOTEC_WALLET_ID,
        'externalId' => $externalId,
        'payee' => iotec_normalize_phone($phone),
        'amount' => (int) round($amount),
        'payerNote' => $note,
        'payeeNote' => $note,
    ]));
    if ($status >= 400 && $status < 500) {
        throw new IotecRefusedException("iotec disbursement refused ($status): $body");
    }
    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec disbursement unclear ($status): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['id'])) {
        throw new IotecException("iotec disbursement response missing transaction id: $body");
    }
    return ['transactionId' => (string) $data['id'], 'status' => (string) ($data['status'] ?? 'Pending')];
}

/** @return array{status: string, statusMessage: ?string} */
function iotec_check_disbursement_status(string $transactionId): array
{
    $token = iotec_get_access_token();
    [$status, $body] = iotec_http('GET', IOTEC_DISBURSE_STATUS_URL . '/' . urlencode($transactionId), [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json',
    ]);
    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec disbursement status check failed ($status): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['status'])) {
        throw new IotecException("iotec disbursement status missing status field: $body");
    }
    return ['status' => (string) $data['status'], 'statusMessage' => $data['statusMessage'] ?? null];
}

/** Finds a disbursement by OUR reference (used when the first answer was lost). Returns ['transactionId','status'] or null. */
function iotec_find_disbursement_by_external_id(string $externalId): ?array
{
    $token = iotec_get_access_token();
    [$status, $body] = iotec_http('GET', IOTEC_DISBURSE_EXTERNAL_URL . '/' . urlencode($externalId), [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json',
    ]);
    if ($status === 404) {
        return null;
    }
    if ($status < 200 || $status >= 300) {
        throw new IotecException("iotec disbursement lookup failed ($status): $body");
    }
    $data = json_decode($body, true);
    return !empty($data['id']) ? ['transactionId' => (string) $data['id'], 'status' => (string) ($data['status'] ?? 'Pending')] : null;
}
