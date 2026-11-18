<?php

declare(strict_types=1);

// Bağımlılıksız test koşucusu: `php tests/run.php` (PHPUnit gerekmez).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Pushizo\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Pushizo\Client;
use Pushizo\PushizoException;
use Pushizo\Transport\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var array<int, array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];
    /** @var array<int, array{status:int, body:string, headers:array<string,string>}> */
    private array $responses;

    /** @param array<int, array{status:int, body:string, headers?:array<string,string>}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = array_map(static fn ($r) => $r + ['headers' => []], $responses);
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            throw new PushizoException(0, 'NETWORK', 'bağlantı yok');
        }
        return array_shift($this->responses);
    }
}

$failures = 0;
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? '  ✔ ' : '  ✘ ') . $label . PHP_EOL;
    if (!$cond) {
        $failures++;
    }
};
$noSleep = static function (int $ms): void {
};

echo "notifications.create" . PHP_EOL;
$t = new FakeTransport([['status' => 202, 'body' => '{"id":"msg_1","status":"queued","recipients_estimate":1,"external_id_missing":[]}']]);
$hs = new Client(['api_key' => 'hs_live_x', 'base_url' => 'https://api.test/', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$res = $hs->notifications->create(['target' => ['include_aliases' => ['external_id' => ['u1']]], 'contents' => ['tr' => 'Merhaba']]);
$assert($res['id'] === 'msg_1', 'yanıt id');
$assert($t->calls[0]['url'] === 'https://api.test/v1/notifications', 'url');
$assert($t->calls[0]['headers']['Authorization'] === 'Bearer hs_live_x', 'bearer başlığı');
$sent = json_decode((string) $t->calls[0]['body'], true);
$assert($sent['app_id'] === 'app_1', 'app_id eklendi');
$assert(preg_match('/^[0-9a-f-]{36}$/', $sent['idempotency_key']) === 1, 'otomatik idempotency anahtarı');
$assert($sent['contents']['tr'] === 'Merhaba', 'UTF-8 korunur');

echo "sorgu parametreleri ve yol kodlaması" . PHP_EOL;
$t = new FakeTransport([['status' => 200, 'body' => '{"data":[]}'], ['status' => 200, 'body' => '{"id":"usr_1"}'], ['status' => 200, 'body' => '{}']]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->notifications->list(['limit' => 10, 'status' => 'completed']);
$assert($t->calls[0]['url'] === 'https://api.test/v1/notifications?app_id=app_1&limit=10&status=completed', 'liste sorgusu');
$hs->users->getByAlias('crm id', 'a/b');
$assert($t->calls[1]['url'] === 'https://api.test/v1/apps/app_1/users/by/crm%20id/a%2Fb', 'alias kodlaması');
$hs->subscriptions->setOwner('sub_1', 'u-9');
$assert($t->calls[2]['method'] === 'POST' && $t->calls[2]['body'] === '{"external_id":"u-9"}', 'owner gövdesi');

echo "yeniden deneme ve hatalar" . PHP_EOL;
$t = new FakeTransport([
    ['status' => 429, 'body' => '{"error":{"code":"HS_RATE_LIMITED","message":"yavaş"}}', 'headers' => ['retry-after' => '1']],
    ['status' => 200, 'body' => '{"id":"msg_1"}'],
]);
$slept = [];
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => static function (int $ms) use (&$slept): void { $slept[] = $ms; }]);
$assert($hs->notifications->get('msg_1')['id'] === 'msg_1', '429 sonrası başarı');
$assert($slept === [1000], 'Retry-After kadar beklendi');

$t = new FakeTransport([['status' => 404, 'body' => '{"error":{"code":"HS_NOT_FOUND","message":"Mesaj bulunamadı","request_id":"req_1"}}']]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
try {
    $hs->notifications->get('msg_x');
    $assert(false, '404 istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert($e->status === 404 && $e->errorCode === 'HS_NOT_FOUND' && $e->requestId === 'req_1' && !$e->isRetryable(), '404 → PushizoException');
}

$t = new FakeTransport([]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep, 'max_retries' => 1]);
try {
    $hs->apps->list();
    $assert(false, 'ağ hatası istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert($e->status === 0 && $e->errorCode === 'NETWORK' && count($t->calls) === 2, 'ağ hatasında max_retries kadar denendi');
}

try {
    (new Client(['api_key' => 'k', 'transport' => new FakeTransport([]), 'sleep' => $noSleep]))->users->list();
    $assert(false, 'app_id yoksa hata');
} catch (\InvalidArgumentException $e) {
    $assert(str_contains($e->getMessage(), 'app_id'), 'app_id eksik hatası');
}

echo "yeniden deneme politikası" . PHP_EOL;
$unavailable = ['status' => 503, 'body' => '{"error":{"code":"HS_SERVICE_UNAVAILABLE","message":"x"}}'];
$ok = ['status' => 200, 'body' => '{"id":"ok"}'];
$make = static function (array $responses, array $extra = []) use ($noSleep): array {
    $t = new FakeTransport($responses);
    return [new Client($extra + ['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]), $t];
};

[$hs, $t] = $make([$unavailable, $ok]);
try {
    $hs->events->send([['external_id' => 'u1', 'name' => 'purchase']]);
    $assert(false, 'anahtarsız POST 503 istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert($e->status === 503 && count($t->calls) === 1, 'anahtarsız POST 503 sonrası yeniden denenmez');
}

[$hs, $t] = $make([$unavailable, $ok], ['auto_idempotency_key' => false]);
try {
    $hs->notifications->create(['target' => ['all' => true], 'contents' => ['en' => 'x']]);
    $assert(false, 'otomatik anahtar kapalı: 503 istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert(count($t->calls) === 1, 'otomatik anahtar kapalıyken notifications.create tekrarlanmaz');
}

[$hs, $t] = $make([$unavailable, $ok]);
$hs->request('POST', '/v1/apps/app_1/events', [], ['events' => []], ['idempotency_key' => 'evt-1']);
$assert(count($t->calls) === 2 && $t->calls[1]['headers']['Idempotency-Key'] === 'evt-1', 'Idempotency-Key taşıyan POST 503 sonrası yeniden denenir');

[$hs, $t] = $make([$unavailable, ['status' => 202, 'body' => '{"id":"msg_1"}']]);
$hs->notifications->create(['target' => ['all' => true], 'contents' => ['en' => 'x']]);
$keys = array_map(static fn (array $c): string => json_decode((string) $c['body'], true)['idempotency_key'], $t->calls);
$assert(count($t->calls) === 2 && $keys[0] === $keys[1], 'otomatik idempotency anahtarlı notifications.create aynı anahtarla yeniden denenir');

[$hs, $t] = $make([$unavailable, $ok, $unavailable, ['status' => 204, 'body' => '']]);
$hs->notifications->get('msg_1');
$hs->request('DELETE', '/v1/apps/app_1/webhooks/whk_1');
$assert(array_column($t->calls, 'method') === ['GET', 'GET', 'DELETE', 'DELETE'], 'GET ve DELETE 503 sonrası yeniden denenir');

$slept = [];
$t = new FakeTransport([['status' => 429, 'body' => '{"error":{"code":"HS_RATE_LIMITED","message":"x"}}', 'headers' => ['retry-after' => '2']], $ok]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => static function (int $ms) use (&$slept): void { $slept[] = $ms; }]);
$hs->events->send([]);
$assert(count($t->calls) === 2 && $slept === [2000], '429 alan anahtarsız POST Retry-After sonrası yeniden denenir');

$refusedOnce = new class implements TransportInterface {
    public int $calls = 0;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        if ($this->calls++ === 0) {
            throw new \Pushizo\ConnectionException('Failed to connect: Connection refused');
        }
        return ['status' => 200, 'body' => '{"accepted":1}', 'headers' => []];
    }
};
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $refusedOnce, 'sleep' => $noSleep]);
$assert($hs->events->send([]) === ['accepted' => 1] && $refusedOnce->calls === 2, 'bağlantı kurulamayan (ConnectionException) POST yeniden denenir');

[$hs, $t] = $make([]);
try {
    $hs->events->send([]);
    $assert(false, 'ağ hatası istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert($e->status === 0 && count($t->calls) === 1, 'gönderildikten sonra kopan anahtarsız POST tekrarlanmaz');
}

if (extension_loaded('curl')) {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $slept = [];
    $hs = new Client(['api_key' => 'k', 'base_url' => "http://127.0.0.1:$port", 'app_id' => 'app_1', 'max_retries' => 1, 'sleep' => static function (int $ms) use (&$slept): void { $slept[] = $ms; }]);
    try {
        $hs->events->send([]);
        $assert(false, 'kapalı port istisna fırlatmalı');
    } catch (\Pushizo\ConnectionException $e) {
        $assert($e->status === 0 && $e->errorCode === 'NETWORK' && $slept === [300], 'gerçek bağlantı reddinde (cURL) POST yeniden denenir');
    }
}

echo "inAppMessages.selectWinner" . PHP_EOL;
[$hs, $t] = $make([$ok]);
$hs->inAppMessages->selectWinner('iam_1', 'b');
$assert($t->calls[0]['method'] === 'POST' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_1/in-app-messages/iam_1/winner' && $t->calls[0]['body'] === '{"variant_id":"b"}', 'A/B kazananı POST edilir');

echo "identityHash" . PHP_EOL;
$assert(Client::identityHash('secret', 'u-1') === hash_hmac('sha256', 'u-1', 'secret'), 'HMAC-SHA256 eşleşir');

echo "identityToken (ES256 JWT)" . PHP_EOL;
$ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($ecKey === false) {
    // Bazı Windows PHP paketleri (ör. XAMPP) openssl.cnf olmadan EC anahtarı üretemez; CI'daki Linux PHP'de test tam koşar.
    $assert(true, 'OpenSSL EC anahtar üretimi bu PHP kurulumunda yok; atlandı (CI Linux PHP ortamında koşar)');
} else {
    openssl_pkey_export($ecKey, $ecPem);
    $jwt = Client::identityToken($ecPem, 'main', 'app_1', 'kullanici-1', 600, 1800000000);
    [$h, $p, $sig] = explode('.', $jwt);
    $dec = static fn (string $v): string => (string) base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4));
    $assert(json_decode($dec($h), true) === ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'main'], 'başlık');
    $assert(json_decode($dec($p), true) === ['sub' => 'kullanici-1', 'aud' => 'app_1', 'iat' => 1800000000, 'exp' => 1800000600], 'talepler');
    $raw = $dec($sig);
    $assert(strlen($raw) === 64, 'r||s 64 bayt');
    // r||s → DER (doğrulama için).
    $int = static function (string $b): string { $b = ltrim($b, "\x00"); if ($b === '' || (ord($b[0]) & 0x80)) { $b = "\x00" . $b; } return "\x02" . chr(strlen($b)) . $b; };
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    $der = "\x30" . chr(strlen($seq)) . $seq;
    $pub = openssl_pkey_get_details($ecKey)['key'];
    $assert(openssl_verify($h . '.' . $p, $der, $pub, OPENSSL_ALGO_SHA256) === 1, 'imza genel anahtarla doğrulanır');
    $threw = false;
    try { Client::identityToken($ecPem, 'k', 'a', 'e', 90000); } catch (\InvalidArgumentException) { $threw = true; }
    $assert($threw, '24 saatten uzun ömür reddedilir');
}

echo "Webhook imza doğrulama (ortak vektörler)" . PHP_EOL;
/** @var array{cases: array<int, array{name:string, toleranceSeconds:int, payload:string, header:?string, secrets:string[], now:int, expected:array{ok:bool, timestamp?:int, reason?:string}, events?:int}>} $vectors */
$vectors = json_decode((string) file_get_contents(__DIR__ . '/../../testdata/webhook-signature-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
$assert(count($vectors['cases']) > 10, 'vektör dosyası yüklendi');
foreach ($vectors['cases'] as $case) {
    $secret = count($case['secrets']) === 1 ? $case['secrets'][0] : $case['secrets'];
    $expectedReason = $case['expected']['ok'] ? null : $case['expected']['reason'];
    $reason = \Pushizo\Webhook::verify($case['payload'], $case['header'], $secret, $case['toleranceSeconds'], $case['now']);
    $assert($reason === $expectedReason, $case['name']);
    $assert(
        \Pushizo\Webhook::verify($case['payload'], $case['header'], $case['secrets'], $case['toleranceSeconds'], $case['now']) === $expectedReason,
        $case['name'] . ' (secret dizisi)',
    );
    if (isset($case['events'])) {
        $events = \Pushizo\Webhook::constructEvents($case['payload'], $case['header'], $secret, $case['toleranceSeconds'], $case['now']);
        $assert(count($events) === $case['events'], $case['name'] . ': olay sayısı');
    } elseif ($expectedReason !== null) {
        try {
            \Pushizo\Webhook::constructEvents($case['payload'], $case['header'], $secret, $case['toleranceSeconds'], $case['now']);
            $assert(false, $case['name'] . ': istisna fırlatmalı');
        } catch (\Pushizo\WebhookSignatureException $e) {
            $assert($e->reason === $expectedReason, $case['name'] . ': WebhookSignatureException nedeni');
        }
    }
}

echo "Suppression list" . PHP_EOL;
$supp = '{"id":"sup_1","app_id":"app_1","channel":"email","value_masked":"a***e@pushizo.com","reason":"manual","source":"api","detail":{},"expires_at":null,"created_at":"2026-10-07T00:00:00.000Z","updated_at":"2026-10-07T00:00:00.000Z"}';
$t = new FakeTransport([
    ['status' => 200, 'body' => '{"data":[' . $supp . '],"next_cursor":"c2","has_more":true}'],
    ['status' => 201, 'body' => $supp],
    ['status' => 200, 'body' => '{"ok":true}'],
    ['status' => 400, 'body' => '{"error":{"code":"HS_VALIDATION_ERROR","message":"Geçersiz adres"}}'],
]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$page = $hs->suppressions->list(['channel' => 'email', 'reason' => 'bounce', 'value' => 'Ayse@Pushizo.com', 'limit' => 20]);
$assert($t->calls[0]['method'] === 'GET' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_1/suppressions?channel=email&reason=bounce&value=Ayse%40Pushizo.com&limit=20', 'list sorgusu');
$assert($page['data'][0]['value_masked'] === 'a***e@pushizo.com' && $page['has_more'] === true, 'list yanıtı');
$created = $hs->suppressions->create(['channel' => 'sms', 'value' => '+905320000111', 'note' => 'müşteri talebi'], 'app_9');
$assert($t->calls[1]['method'] === 'POST' && $t->calls[1]['url'] === 'https://api.test/v1/apps/app_9/suppressions', 'create yolu (app parametresi)');
$assert($t->calls[1]['body'] === '{"channel":"sms","value":"+905320000111","note":"müşteri talebi"}', 'create gövdesi (UTF-8)');
$assert($created['id'] === 'sup_1', 'create yanıtı');
$hs->suppressions->delete('sup/1');
$assert($t->calls[2]['method'] === 'DELETE' && $t->calls[2]['url'] === 'https://api.test/v1/apps/app_1/suppressions/sup%2F1', 'delete yolu kodlanır');
try {
    $hs->suppressions->create(['channel' => 'email', 'value' => 'x']);
    $assert(false, '400 istisna fırlatmalı');
} catch (PushizoException $e) {
    $assert($e->status === 400 && $e->errorCode === 'HS_VALIDATION_ERROR', '400 → PushizoException');
}

echo "e-posta/SMS/inbox içerik alanları" . PHP_EOL;
$t = new FakeTransport([['status' => 202, 'body' => '{"id":"msg_2","status":"queued","recipients_estimate":1,"external_id_missing":[]}']]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->notifications->create([
    'target' => ['include_aliases' => ['external_id' => ['u1']], 'channels' => ['email', 'sms']],
    'contents' => ['tr' => 'Siparişin kargoda'],
    'email_subject' => 'Siparişin yolda',
    'email_mjml' => '<mjml><mj-body></mj-body></mjml>',
    'email_from_address' => 'no-reply@pushizo.com',
    'disable_email_click_tracking' => true,
    'sms_from' => 'MAGAZA',
    'add_to_inbox' => true,
    'inbox_ttl' => 86400,
]);
$sent = json_decode((string) $t->calls[0]['body'], true);
$assert($sent['email_subject'] === 'Siparişin yolda' && $sent['sms_from'] === 'MAGAZA' && $sent['add_to_inbox'] === true && $sent['inbox_ttl'] === 86400, 'alanlar gövdede');
$assert($sent['target']['channels'] === ['email', 'sms'], 'kanal hedefi');

echo "OpenAPI kapsamı (her public işlem bir SDK metoduna eşlenir)" . PHP_EOL;
// api/openapi.yaml'ın `paths` bölümü satır bazlı okunur (yaml eklentisi gerekmez).
$specOps = [];
$inPaths = false;
$currentPath = null;
foreach (file(__DIR__ . '/../../../api/openapi.yaml', FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === 'paths:') {
        $inPaths = true;
        continue;
    }
    if ($inPaths && preg_match('/^\S/', $line) === 1) {
        break;
    }
    if (!$inPaths) {
        continue;
    }
    if (preg_match('/^  (\/\S+):\s*$/', $line, $m) === 1) {
        $currentPath = str_starts_with($m[1], '/v1/') ? $m[1] : null;
    } elseif ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m) === 1) {
        $specOps[] = strtoupper($m[1]) . ' ' . $currentPath;
    }
}
sort($specOps);

$definition = ['all' => [['field' => 'tag:vip', 'op' => 'eq', 'value' => 'true']]];
/** @var array<string, callable(Client):mixed> $table */
$table = [
    'GET /v1/organization' => fn (Client $c) => $c->organization->get(),
    'POST /v1/apps/{appId}/segments/{segmentId}/refresh' => fn (Client $c) => $c->segments->refresh('seg_1'),
    'POST /v1/apps/{appId}/templates/{templateId}/versions/{version}/revert' => fn (Client $c) => $c->templates->revert('tpl_1', 1),
    'POST /v1/apps/{appId}/web-campaigns/{campaignId}/winner' => fn (Client $c) => $c->webCampaigns->selectWinner('wcm_1', 'b'),
    'DELETE /v1/apps/{appId}/web-campaigns/{campaignId}' => fn (Client $c) => $c->webCampaigns->delete('wcm_1'),
    'POST /v1/apps/{appId}/web-personalizations/{personalizationId}/winner' => fn (Client $c) => $c->webPersonalizations->selectWinner('wpz_1', 'b'),
    'DELETE /v1/apps/{appId}/web-personalizations/{personalizationId}' => fn (Client $c) => $c->webPersonalizations->delete('wpz_1'),
    'PATCH /v1/apps/{appId}/forms/{formId}' => fn (Client $c) => $c->forms->update('frm_1', ['name' => 'Bülten v2']),
    'DELETE /v1/apps/{appId}/forms/{formId}' => fn (Client $c) => $c->forms->delete('frm_1'),
    'PATCH /v1/apps/{appId}/surveys/{surveyId}' => fn (Client $c) => $c->surveys->update('srv_1', ['name' => 'NPS v2']),
    'DELETE /v1/apps/{appId}/surveys/{surveyId}' => fn (Client $c) => $c->surveys->delete('srv_1'),
    'POST /v1/apps/{appId}/landing-pages' => fn (Client $c) => $c->landingPages->create(['name' => 'Kış kampanyası', 'slug' => 'kis-kampanyasi', 'document' => ['version' => 1, 'sections' => []]]),
    'PATCH /v1/apps/{appId}/landing-pages/{pageId}' => fn (Client $c) => $c->landingPages->update('lp_1', ['name' => 'Bahar kampanyası']),
    'POST /v1/apps/{appId}/landing-pages/{pageId}/archive' => fn (Client $c) => $c->landingPages->archive('lp_1'),
    'GET /v1/apps/{appId}/landing-pages/{pageId}/versions' => fn (Client $c) => $c->landingPages->versions('lp_1'),
    'POST /v1/apps/{appId}/landing-pages/{pageId}/versions/{version}/restore' => fn (Client $c) => $c->landingPages->restoreVersion('lp_1', 1),
    'DELETE /v1/apps/{appId}/landing-pages/{pageId}' => fn (Client $c) => $c->landingPages->delete('lp_1'),
    'GET /v1/organization/members' => fn (Client $c) => $c->organization->members(),
    'GET /v1/organization/audit-logs' => fn (Client $c) => $c->organization->auditLogs(['action' => 'app.update']),
    'GET /v1/organization/usage' => fn (Client $c) => $c->organization->usage(['days' => 30]),
    'GET /v1/apps' => fn (Client $c) => $c->apps->list(),
    'POST /v1/apps' => fn (Client $c) => $c->apps->create(['name' => 'Yeni app']),
    'GET /v1/apps/{appId}' => fn (Client $c) => $c->apps->get(),
    'PATCH /v1/apps/{appId}' => fn (Client $c) => $c->apps->update(['name' => 'Ad']),
    'GET /v1/apps/{appId}/api-keys' => fn (Client $c) => $c->apiKeys->list(),
    'POST /v1/apps/{appId}/api-keys' => fn (Client $c) => $c->apiKeys->create(['name' => 'ci', 'scopes' => ['notifications:write']]),
    'PATCH /v1/apps/{appId}/api-keys/{keyId}' => fn (Client $c) => $c->apiKeys->update('key_1', ['name' => 'x']),
    'DELETE /v1/apps/{appId}/api-keys/{keyId}' => fn (Client $c) => $c->apiKeys->revoke('key_1'),
    'GET /v1/apps/{appId}/identity-keys' => fn (Client $c) => $c->identityKeys->list(),
    'POST /v1/apps/{appId}/identity-keys' => fn (Client $c) => $c->identityKeys->create(['kid' => 'main', 'public_key_pem' => 'pem']),
    'DELETE /v1/apps/{appId}/identity-keys/{keyId}' => fn (Client $c) => $c->identityKeys->revoke('ajk_1'),
    'GET /v1/apps/{appId}/live-activities/types' => fn (Client $c) => $c->liveActivities->listTypes(),
    'POST /v1/apps/{appId}/live-activities/types' => fn (Client $c) => $c->liveActivities->createType(['name' => 'OrderActivity', 'content_state_schema' => ['type' => 'object', 'properties' => ['eta' => ['type' => 'integer']]]]),
    'PATCH /v1/apps/{appId}/live-activities/types/{activityType}' => fn (Client $c) => $c->liveActivities->updateType('OrderActivity', ['description' => 'Sipariş']),
    'DELETE /v1/apps/{appId}/live-activities/types/{activityType}' => fn (Client $c) => $c->liveActivities->deleteType('OrderActivity'),
    'POST /v1/apps/{appId}/live-activities/{activityType}/start' => fn (Client $c) => $c->liveActivities->start('OrderActivity', ['target' => ['all' => true], 'content_state' => ['eta' => 5]]),
    'POST /v1/apps/{appId}/live-activities/{activityType}/update' => fn (Client $c) => $c->liveActivities->update('OrderActivity', ['activity_id' => 'order-1', 'content_state' => ['eta' => 3]]),
    'POST /v1/apps/{appId}/live-activities/{activityType}/end' => fn (Client $c) => $c->liveActivities->end('OrderActivity', ['activity_id' => 'order-1']),
    'POST /v1/apps/{appId}/api-keys/{keyId}/rotate' => fn (Client $c) => $c->apiKeys->rotate('key_1'),
    'GET /v1/apps/{appId}/webhooks' => fn (Client $c) => $c->webhooks->list(),
    'POST /v1/apps/{appId}/webhooks' => fn (Client $c) => $c->webhooks->create(['url' => 'https://pushizo.com/h', 'events' => ['user.created']]),
    'GET /v1/apps/{appId}/webhooks/{webhookId}' => fn (Client $c) => $c->webhooks->get('wh_1'),
    'PATCH /v1/apps/{appId}/webhooks/{webhookId}' => fn (Client $c) => $c->webhooks->update('wh_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/webhooks/{webhookId}' => fn (Client $c) => $c->webhooks->delete('wh_1'),
    'POST /v1/apps/{appId}/webhooks/{webhookId}/rotate-secret' => fn (Client $c) => $c->webhooks->rotateSecret('wh_1'),
    'POST /v1/apps/{appId}/webhooks/{webhookId}/test' => fn (Client $c) => $c->webhooks->test('wh_1'),
    'GET /v1/apps/{appId}/webhooks/{webhookId}/deliveries' => fn (Client $c) => $c->webhooks->deliveries('wh_1'),
    'POST /v1/apps/{appId}/webhooks/deliveries/{deliveryId}/replay' => fn (Client $c) => $c->webhooks->replay('whd_1'),
    'GET /v1/apps/{appId}/users' => fn (Client $c) => $c->users->list(),
    'POST /v1/apps/{appId}/users' => fn (Client $c) => $c->users->upsert(['external_id' => 'u1']),
    'POST /v1/apps/{appId}/users/search' => fn (Client $c) => $c->users->search(['definition' => $definition]),
    'GET /v1/apps/{appId}/users/by/{label}/{value}' => fn (Client $c) => $c->users->getByExternalId('u1'),
    'PATCH /v1/apps/{appId}/users/by/{label}/{value}' => fn (Client $c) => $c->users->update('external_id', 'u1', ['tags' => ['vip' => '1']]),
    'DELETE /v1/apps/{appId}/users/by/{label}/{value}' => fn (Client $c) => $c->users->delete('external_id', 'u1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/identity' => fn (Client $c) => $c->users->getIdentity('external_id', 'u1'),
    'PATCH /v1/apps/{appId}/users/by/{label}/{value}/identity' => fn (Client $c) => $c->users->addAliases('external_id', 'u1', ['crm' => 'c1']),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/export' => fn (Client $c) => $c->users->export('external_id', 'u1'),
    'DELETE /v1/apps/{appId}/users/by/{label}/{value}/identity/{aliasLabel}' => fn (Client $c) => $c->users->removeAlias('external_id', 'u1', 'crm'),
    'POST /v1/apps/{appId}/users/by/{label}/{value}/subscriptions' => fn (Client $c) => $c->users->addSubscription('external_id', 'u1', ['channel' => 'email', 'token' => 'a@pushizo.com']),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/inbox' => fn (Client $c) => $c->inbox->list('external_id', 'u1'),
    'POST /v1/apps/{appId}/users/by/{label}/{value}/inbox/read-all' => fn (Client $c) => $c->inbox->readAll('external_id', 'u1'),
    'POST /v1/apps/{appId}/users/by/{label}/{value}/inbox/{itemId}/read' => fn (Client $c) => $c->inbox->read('external_id', 'u1', 'inb_1'),
    'DELETE /v1/apps/{appId}/users/by/{label}/{value}/inbox/{itemId}' => fn (Client $c) => $c->inbox->archive('external_id', 'u1', 'inb_1'),
    'GET /v1/apps/{appId}/subscriptions/{subscriptionId}' => fn (Client $c) => $c->subscriptions->get('sub_1'),
    'PATCH /v1/apps/{appId}/subscriptions/{subscriptionId}' => fn (Client $c) => $c->subscriptions->update('sub_1', ['status' => 'unsubscribed']),
    'DELETE /v1/apps/{appId}/subscriptions/{subscriptionId}' => fn (Client $c) => $c->subscriptions->delete('sub_1'),
    'GET /v1/apps/{appId}/subscriptions/{subscriptionId}/user/identity' => fn (Client $c) => $c->subscriptions->getUserIdentity('sub_1'),
    'PATCH /v1/apps/{appId}/subscriptions_by_token/{tokenType}/{token}' => fn (Client $c) => $c->subscriptions->updateByToken('email', 'a@pushizo.com', ['status' => 'unsubscribed']),
    'POST /v1/apps/{appId}/subscriptions/{subscriptionId}/owner' => fn (Client $c) => $c->subscriptions->setOwner('sub_1', 'u1'),
    'POST /v1/apps/{appId}/events' => fn (Client $c) => $c->events->send([['name' => 'purchase', 'external_id' => 'u1']]),
    'GET /v1/apps/{appId}/events/definitions' => fn (Client $c) => $c->events->listDefinitions(),
    'GET /v1/apps/{appId}/events/definitions/{name}' => fn (Client $c) => $c->events->getDefinition('purchase'),
    'PATCH /v1/apps/{appId}/events/definitions/{name}' => fn (Client $c) => $c->events->updateDefinition('purchase', ['is_conversion' => true]),
    'GET /v1/apps/{appId}/channels' => fn (Client $c) => $c->channels->list(),
    'PUT /v1/apps/{appId}/channels/{channel}' => fn (Client $c) => $c->channels->upsert('ios_push', ['provider' => 'mock']),
    'POST /v1/apps/{appId}/channels/{channel}/validate' => fn (Client $c) => $c->channels->validate('ios_push'),
    'POST /v1/notifications' => fn (Client $c) => $c->notifications->create(['target' => ['all' => true], 'contents' => ['en' => 'x']]),
    'GET /v1/notifications' => fn (Client $c) => $c->notifications->list(),
    'POST /v1/notifications/estimate' => fn (Client $c) => $c->notifications->estimate(['target' => ['all' => true]]),
    'GET /v1/notifications/{messageId}' => fn (Client $c) => $c->notifications->get('msg_1'),
    'DELETE /v1/notifications/{messageId}' => fn (Client $c) => $c->notifications->cancel('msg_1'),
    'GET /v1/notifications/{messageId}/deliveries' => fn (Client $c) => $c->notifications->deliveries('msg_1'),
    'GET /v1/notifications/{messageId}/history' => fn (Client $c) => $c->notifications->history('msg_1'),
    'POST /v1/notifications/{messageId}/export_events' => fn (Client $c) => $c->notifications->exportEvents('msg_1'),
    'GET /v1/apps/{appId}/analytics/overview' => fn (Client $c) => $c->analytics->overview(['range' => '30d']),
    'GET /v1/apps/{appId}/analytics/messages' => fn (Client $c) => $c->analytics->messages(),
    'GET /v1/apps/{appId}/analytics/subscriptions' => fn (Client $c) => $c->analytics->subscriptions(),
    'GET /v1/apps/{appId}/analytics/events' => fn (Client $c) => $c->analytics->events(['name' => 'purchase']),
    'GET /v1/apps/{appId}/analytics/conversions' => fn (Client $c) => $c->analytics->conversions(),
    'POST /v1/apps/{appId}/analytics/funnels' => fn (Client $c) => $c->analytics->funnel(['steps' => [['name' => 'a'], ['name' => 'b']]]),
    'GET /v1/apps/{appId}/analytics/providers' => fn (Client $c) => $c->analytics->providers(),
    'GET /v1/apps/{appId}/analytics/engagement' => fn (Client $c) => $c->analytics->engagement(),
    'GET /v1/apps/{appId}/analytics/retention' => fn (Client $c) => $c->analytics->retention(['weeks' => 8]),
    'POST /v1/apps/{appId}/exports' => fn (Client $c) => $c->exports->create(['type' => 'users']),
    'GET /v1/apps/{appId}/exports' => fn (Client $c) => $c->exports->list(),
    'GET /v1/apps/{appId}/exports/{exportId}' => fn (Client $c) => $c->exports->get('exp_1'),
    'POST /v1/apps/{appId}/imports' => fn (Client $c) => $c->imports->create('csv_users', "external_id\nu1\n"),
    'GET /v1/apps/{appId}/imports' => fn (Client $c) => $c->imports->list(),
    'GET /v1/apps/{appId}/imports/{importId}' => fn (Client $c) => $c->imports->get('imp_1'),
    'GET /v1/apps/{appId}/segments' => fn (Client $c) => $c->segments->list(),
    'POST /v1/apps/{appId}/segments' => fn (Client $c) => $c->segments->create(['name' => 'VIP', 'definition' => $definition]),
    'POST /v1/apps/{appId}/segments/preview' => fn (Client $c) => $c->segments->preview(['definition' => $definition]),
    'GET /v1/apps/{appId}/segments/{segmentId}' => fn (Client $c) => $c->segments->get('seg_1'),
    'PATCH /v1/apps/{appId}/segments/{segmentId}' => fn (Client $c) => $c->segments->update('seg_1', ['name' => 'X']),
    'DELETE /v1/apps/{appId}/segments/{segmentId}' => fn (Client $c) => $c->segments->delete('seg_1'),
    'GET /v1/apps/{appId}/segments/{segmentId}/campaigns' => fn (Client $c) => $c->segments->usedBy('seg_1'),
    'POST /v1/apps/{appId}/segments/{segmentId}/estimate' => fn (Client $c) => $c->segments->estimate('seg_1'),
    'GET /v1/apps/{appId}/templates' => fn (Client $c) => $c->templates->list(),
    'POST /v1/apps/{appId}/templates' => fn (Client $c) => $c->templates->create(['name' => 'Hoş geldin', 'content' => ['contents' => ['en' => 'Hi']]]),
    'GET /v1/apps/{appId}/templates/{templateId}' => fn (Client $c) => $c->templates->get('tpl_1'),
    'PATCH /v1/apps/{appId}/templates/{templateId}' => fn (Client $c) => $c->templates->update('tpl_1', ['name' => 'Y']),
    'DELETE /v1/apps/{appId}/templates/{templateId}' => fn (Client $c) => $c->templates->delete('tpl_1'),
    'POST /v1/apps/{appId}/templates/{templateId}/copy_to_app' => fn (Client $c) => $c->templates->copyToApp('tpl_1', ['target_app_id' => 'app_2']),
    'GET /v1/apps/{appId}/templates/{templateId}/versions' => fn (Client $c) => $c->templates->versions('tpl_1'),
    'GET /v1/apps/{appId}/templates/{templateId}/stats' => fn (Client $c) => $c->templates->stats('tpl_1'),
    'POST /v1/apps/{appId}/templates/{templateId}/render' => fn (Client $c) => $c->templates->render('tpl_1'),
    'GET /v1/apps/{appId}/campaigns' => fn (Client $c) => $c->campaigns->list(),
    'POST /v1/apps/{appId}/campaigns' => fn (Client $c) => $c->campaigns->create(['name' => 'Bahar']),
    'GET /v1/apps/{appId}/campaigns/{campaignId}' => fn (Client $c) => $c->campaigns->get('cmp_1'),
    'PATCH /v1/apps/{appId}/campaigns/{campaignId}' => fn (Client $c) => $c->campaigns->update('cmp_1', ['name' => 'Yaz']),
    'DELETE /v1/apps/{appId}/campaigns/{campaignId}' => fn (Client $c) => $c->campaigns->delete('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/schedule' => fn (Client $c) => $c->campaigns->schedule('cmp_1', ['send_at' => '2026-11-01T09:00:00Z']),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/send' => fn (Client $c) => $c->campaigns->send('cmp_1', null, true),
    'GET /v1/apps/{appId}/campaigns/{campaignId}/email-risk' => fn (Client $c) => $c->campaigns->emailRisk('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/pause' => fn (Client $c) => $c->campaigns->pause('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/resume' => fn (Client $c) => $c->campaigns->resume('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/cancel' => fn (Client $c) => $c->campaigns->cancel('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/duplicate' => fn (Client $c) => $c->campaigns->duplicate('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/approve' => fn (Client $c) => $c->campaigns->approve('cmp_1'),
    'GET /v1/apps/{appId}/campaigns/{campaignId}/stats' => fn (Client $c) => $c->campaigns->stats('cmp_1'),
    'GET /v1/apps/{appId}/campaigns/{campaignId}/deliveries' => fn (Client $c) => $c->campaigns->deliveries('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/resend-failed' => fn (Client $c) => $c->campaigns->resendFailed('cmp_1'),
    'GET /v1/apps/{appId}/campaigns/{campaignId}/messages' => fn (Client $c) => $c->campaigns->messages('cmp_1'),
    'GET /v1/apps/{appId}/campaigns/{campaignId}/variants/stats' => fn (Client $c) => $c->campaigns->variantStats('cmp_1'),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/winner' => fn (Client $c) => $c->campaigns->selectWinner('cmp_1', 'cvr_1'),
    'GET /v1/apps/{appId}/categories' => fn (Client $c) => $c->preferences->listCategories(),
    'POST /v1/apps/{appId}/categories' => fn (Client $c) => $c->preferences->createCategory(['key' => 'marketing', 'name' => ['tr' => 'Pazarlama']]),
    'PATCH /v1/apps/{appId}/categories/{categoryId}' => fn (Client $c) => $c->preferences->updateCategory('cat_1', ['position' => 1]),
    'DELETE /v1/apps/{appId}/categories/{categoryId}' => fn (Client $c) => $c->preferences->deleteCategory('cat_1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/preferences' => fn (Client $c) => $c->preferences->getUser('external_id', 'u1'),
    'PUT /v1/apps/{appId}/users/by/{label}/{value}/preferences' => fn (Client $c) => $c->preferences->setUser('external_id', 'u1', [['category' => 'marketing', 'opted_in' => false]]),
    'GET /v1/apps/{appId}/suppressions' => fn (Client $c) => $c->suppressions->list(),
    'POST /v1/apps/{appId}/suppressions' => fn (Client $c) => $c->suppressions->create(['channel' => 'email', 'value' => 'a@pushizo.com']),
    'DELETE /v1/apps/{appId}/suppressions/{suppressionId}' => fn (Client $c) => $c->suppressions->delete('sup_1'),
    'GET /v1/apps/{appId}/in-app-messages' => fn (Client $c) => $c->inAppMessages->list(),
    'POST /v1/apps/{appId}/in-app-messages' => fn (Client $c) => $c->inAppMessages->create(['name' => 'Banner', 'triggers' => [['kind' => 'session_start']], 'display' => ['layout' => 'modal', 'blocks' => []]]),
    'GET /v1/apps/{appId}/in-app-messages/{messageId}' => fn (Client $c) => $c->inAppMessages->get('iam_1'),
    'PATCH /v1/apps/{appId}/in-app-messages/{messageId}' => fn (Client $c) => $c->inAppMessages->update('iam_1', ['name' => 'Yeni']),
    'DELETE /v1/apps/{appId}/in-app-messages/{messageId}' => fn (Client $c) => $c->inAppMessages->delete('iam_1'),
    'POST /v1/apps/{appId}/in-app-messages/{messageId}/status' => fn (Client $c) => $c->inAppMessages->setStatus('iam_1', 'active'),
    'POST /v1/apps/{appId}/in-app-messages/{messageId}/winner' => fn (Client $c) => $c->inAppMessages->selectWinner('iam_1', 'a'),
    // journey'ler
    'GET /v1/apps/{appId}/journeys' => fn (Client $c) => $c->journeys->list(),
    'POST /v1/apps/{appId}/journeys' => fn (Client $c) => $c->journeys->create(['name' => 'Karşılama']),
    'GET /v1/apps/{appId}/journeys/{journeyId}' => fn (Client $c) => $c->journeys->get('jrn_1'),
    'PATCH /v1/apps/{appId}/journeys/{journeyId}' => fn (Client $c) => $c->journeys->update('jrn_1', ['name' => 'Yeni ad']),
    'DELETE /v1/apps/{appId}/journeys/{journeyId}' => fn (Client $c) => $c->journeys->delete('jrn_1'),
    'PUT /v1/apps/{appId}/journeys/{journeyId}/draft' => fn (Client $c) => $c->journeys->saveDraft('jrn_1', ['graph' => ['nodes' => [], 'edges' => []]]),
    'PATCH /v1/apps/{appId}/journeys/{journeyId}/nodes/{nodeId}' => fn (Client $c) => $c->journeys->updateNode('jrn_1', 'n1', ['position' => ['x' => 0, 'y' => 0]]),
    'GET /v1/apps/{appId}/journeys/{journeyId}/versions' => fn (Client $c) => $c->journeys->versions('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/publish' => fn (Client $c) => $c->journeys->publish('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/pause' => fn (Client $c) => $c->journeys->pause('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/resume' => fn (Client $c) => $c->journeys->resume('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/archive' => fn (Client $c) => $c->journeys->archive('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/duplicate' => fn (Client $c) => $c->journeys->duplicate('jrn_1'),
    'GET /v1/apps/{appId}/journeys/{journeyId}/stats' => fn (Client $c) => $c->journeys->stats('jrn_1'),
    'GET /v1/apps/{appId}/journeys/{journeyId}/runs' => fn (Client $c) => $c->journeys->listRuns('jrn_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/runs' => fn (Client $c) => $c->journeys->triggerRun('jrn_1', ['external_id' => 'u1']),
    'GET /v1/apps/{appId}/journeys/{journeyId}/runs/{runId}' => fn (Client $c) => $c->journeys->getRun('jrn_1', 'run_1'),
    'DELETE /v1/apps/{appId}/journeys/{journeyId}/runs/{runId}' => fn (Client $c) => $c->journeys->exitRun('jrn_1', 'run_1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/journeys' => fn (Client $c) => $c->journeys->listForUser('external_id', 'u1'),
    // v2: dinamik içerik
    'GET /v1/apps/{appId}/data-feeds' => fn (Client $c) => $c->dataFeeds->list(),
    'POST /v1/apps/{appId}/data-feeds' => fn (Client $c) => $c->dataFeeds->create(['name' => 'stok', 'url_template' => 'https://pushizo.com/stok/{{ user.external_id }}']),
    'GET /v1/apps/{appId}/data-feeds/{feedId}' => fn (Client $c) => $c->dataFeeds->get('df_1'),
    'PATCH /v1/apps/{appId}/data-feeds/{feedId}' => fn (Client $c) => $c->dataFeeds->update('df_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/data-feeds/{feedId}' => fn (Client $c) => $c->dataFeeds->delete('df_1'),
    'POST /v1/apps/{appId}/data-feeds/{feedId}/test' => fn (Client $c) => $c->dataFeeds->test('df_1', ['external_id' => 'u1']),
    'GET /v1/apps/{appId}/content-tables' => fn (Client $c) => $c->contentTables->list(),
    'POST /v1/apps/{appId}/content-tables' => fn (Client $c) => $c->contentTables->create(['name' => 'urunler', 'key_column' => 'sku'], "sku,ad\nA1,Kalem\n"),
    'GET /v1/apps/{appId}/content-tables/{tableId}' => fn (Client $c) => $c->contentTables->get('ct_1'),
    'PATCH /v1/apps/{appId}/content-tables/{tableId}' => fn (Client $c) => $c->contentTables->update('ct_1', ['description' => 'Ürün kataloğu']),
    'DELETE /v1/apps/{appId}/content-tables/{tableId}' => fn (Client $c) => $c->contentTables->delete('ct_1'),
    'GET /v1/apps/{appId}/content-tables/{tableId}/rows' => fn (Client $c) => $c->contentTables->rows('ct_1', ['limit' => 50]),
    'PUT /v1/apps/{appId}/content-tables/{tableId}/rows' => fn (Client $c) => $c->contentTables->replaceRows('ct_1', "sku,ad\nA2,Defter\n"),
    'POST /v1/apps/{appId}/webhooks/preview' => fn (Client $c) => $c->webhooks->preview(['event_type' => 'user.created']),
    // v2: veri ambarı dışa aktarımları
    'GET /v1/apps/{appId}/warehouse-exports/tables' => fn (Client $c) => $c->warehouseExports->tables(),
    'POST /v1/apps/{appId}/warehouse-exports/test-connection' => fn (Client $c) => $c->warehouseExports->testConnection(['destination' => 'bigquery', 'config' => ['project_id' => 'proje-1', 'dataset' => 'hs'], 'credentials' => ['service_account_json' => '{}']]),
    'GET /v1/apps/{appId}/warehouse-exports' => fn (Client $c) => $c->warehouseExports->list(),
    'POST /v1/apps/{appId}/warehouse-exports' => fn (Client $c) => $c->warehouseExports->create(['name' => 'BQ', 'tables' => ['events'], 'destination' => 'bigquery', 'config' => ['project_id' => 'proje-1', 'dataset' => 'hs'], 'credentials' => ['service_account_json' => '{}']]),
    'GET /v1/apps/{appId}/warehouse-exports/{exportId}' => fn (Client $c) => $c->warehouseExports->get('wex_1'),
    'PATCH /v1/apps/{appId}/warehouse-exports/{exportId}' => fn (Client $c) => $c->warehouseExports->update('wex_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/warehouse-exports/{exportId}' => fn (Client $c) => $c->warehouseExports->delete('wex_1'),
    'POST /v1/apps/{appId}/warehouse-exports/{exportId}/test' => fn (Client $c) => $c->warehouseExports->test('wex_1'),
    'POST /v1/apps/{appId}/warehouse-exports/{exportId}/run' => fn (Client $c) => $c->warehouseExports->run('wex_1'),
    'GET /v1/apps/{appId}/warehouse-exports/{exportId}/runs' => fn (Client $c) => $c->warehouseExports->runs('wex_1'),
    'GET /v1/apps/{appId}/reverse-etl' => fn (Client $c) => $c->reverseEtl->list(),
    'POST /v1/apps/{appId}/reverse-etl/test-connection' => fn (Client $c) => $c->reverseEtl->testConnection(['source' => 's3', 'config' => ['bucket' => 'hs-ambar', 'region' => 'eu-central-1', 'key' => 'users.csv', 'format' => 'csv', 'mapping' => ['external_id_column' => 'id']], 'credentials' => ['access_key_id' => 'AKIA_TEST', 'secret_access_key' => 'gizli-anahtar']]),
    'POST /v1/apps/{appId}/reverse-etl' => fn (Client $c) => $c->reverseEtl->create(['name' => 'Kullanıcılar', 'target' => 'user', 'schedule' => 'daily', 'source' => 's3', 'config' => ['bucket' => 'hs-ambar', 'region' => 'eu-central-1', 'key' => 'users.csv', 'format' => 'csv', 'mapping' => ['external_id_column' => 'id']], 'credentials' => ['access_key_id' => 'AKIA_TEST', 'secret_access_key' => 'gizli-anahtar']]),
    'GET /v1/apps/{appId}/reverse-etl/{syncId}' => fn (Client $c) => $c->reverseEtl->get('ret_1'),
    'PATCH /v1/apps/{appId}/reverse-etl/{syncId}' => fn (Client $c) => $c->reverseEtl->update('ret_1', ['schedule' => 'hourly']),
    'DELETE /v1/apps/{appId}/reverse-etl/{syncId}' => fn (Client $c) => $c->reverseEtl->delete('ret_1'),
    'POST /v1/apps/{appId}/reverse-etl/{syncId}/test' => fn (Client $c) => $c->reverseEtl->test('ret_1'),
    'POST /v1/apps/{appId}/reverse-etl/{syncId}/run' => fn (Client $c) => $c->reverseEtl->run('ret_1'),
    'GET /v1/apps/{appId}/reverse-etl/{syncId}/runs' => fn (Client $c) => $c->reverseEtl->runs('ret_1', ['limit' => 20]),
    // v2: iki yönlü mesajlaşma
    'GET /v1/apps/{appId}/conversations' => fn (Client $c) => $c->conversations->list(['unread' => true]),
    'GET /v1/apps/{appId}/conversations/{conversationId}' => fn (Client $c) => $c->conversations->get('cnv_1'),
    'GET /v1/apps/{appId}/conversations/{conversationId}/messages' => fn (Client $c) => $c->conversations->messages('cnv_1'),
    'POST /v1/apps/{appId}/conversations/{conversationId}/messages' => fn (Client $c) => $c->conversations->reply('cnv_1', ['text' => 'Merhaba']),
    'POST /v1/apps/{appId}/conversations/{conversationId}/read' => fn (Client $c) => $c->conversations->markRead('cnv_1'),
    'GET /v1/apps/{appId}/sms-keywords' => fn (Client $c) => $c->smsKeywords->list(),
    'POST /v1/apps/{appId}/sms-keywords' => fn (Client $c) => $c->smsKeywords->create(['keyword' => 'KAMPANYA', 'actions' => ['add_tags' => ['kampanya' => '1']]]),
    'PATCH /v1/apps/{appId}/sms-keywords/{keywordId}' => fn (Client $c) => $c->smsKeywords->update('kw_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/sms-keywords/{keywordId}' => fn (Client $c) => $c->smsKeywords->delete('kw_1'),
    'GET /v1/apps/{appId}/whatsapp/templates' => fn (Client $c) => $c->whatsappTemplates->list(['status' => 'APPROVED']),
    'POST /v1/apps/{appId}/whatsapp/templates/sync' => fn (Client $c) => $c->whatsappTemplates->sync(),
    // v2: e-posta teslim edilebilirliği
    'GET /v1/apps/{appId}/email-deliverability' => fn (Client $c) => $c->emailDeliverability->get(),
    'PUT /v1/apps/{appId}/email-deliverability' => fn (Client $c) => $c->emailDeliverability->update(['bcc_address' => 'arsiv@pushizo.com']),
    'POST /v1/apps/{appId}/email-deliverability/verify-address' => fn (Client $c) => $c->emailDeliverability->verifyAddress('a@pushizo.com'),
    'GET /v1/apps/{appId}/email-deliverability/warmup-plans' => fn (Client $c) => $c->emailDeliverability->listWarmupPlans(),
    'POST /v1/apps/{appId}/email-deliverability/warmup-plans' => fn (Client $c) => $c->emailDeliverability->createWarmupPlan(['name' => 'Yeni alan', 'kind' => 'domain', 'target' => 'mail.pushizo.com']),
    'GET /v1/apps/{appId}/email-deliverability/warmup-plans/{planId}' => fn (Client $c) => $c->emailDeliverability->getWarmupPlan('wup_1'),
    'GET /v1/apps/{appId}/email-deliverability/warmup-plans/{planId}/usage' => fn (Client $c) => $c->emailDeliverability->warmupPlanUsage('wup_1', 7),
    'PATCH /v1/apps/{appId}/email-deliverability/warmup-plans/{planId}' => fn (Client $c) => $c->emailDeliverability->updateWarmupPlan('wup_1', ['status' => 'paused']),
    'DELETE /v1/apps/{appId}/email-deliverability/warmup-plans/{planId}' => fn (Client $c) => $c->emailDeliverability->deleteWarmupPlan('wup_1'),
    'GET /v1/apps/{appId}/email-deliverability/postmaster' => fn (Client $c) => $c->emailDeliverability->postmaster(),
    'POST /v1/apps/{appId}/email-deliverability/postmaster/sync' => fn (Client $c) => $c->emailDeliverability->syncPostmaster(),
    'POST /v1/apps/{appId}/verify/sms' => fn (Client $c) => $c->verify->start(['phone' => '+905321112233', 'locale' => 'tr']),
    'POST /v1/apps/{appId}/verify/sms/check' => fn (Client $c) => $c->verify->check(['id' => 'phv_1', 'code' => '123456']),
    // v3: reklam kitleleri (ADR-0037)
    'GET /v1/apps/{appId}/ads/accounts' => fn (Client $c) => $c->ads->listAccounts(),
    'GET /v1/apps/{appId}/ads/audiences' => fn (Client $c) => $c->ads->listAudiences(['ad_account_id' => 'ada_1']),
    'POST /v1/apps/{appId}/ads/audiences' => fn (Client $c) => $c->ads->createAudience(['ad_account_id' => 'ada_1', 'source' => 'segment', 'segment_id' => 'seg_1', 'name' => 'VIP']),
    'GET /v1/apps/{appId}/ads/audiences/{audienceId}' => fn (Client $c) => $c->ads->getAudience('adu_1'),
    'PATCH /v1/apps/{appId}/ads/audiences/{audienceId}' => fn (Client $c) => $c->ads->updateAudience('adu_1', ['status' => 'paused']),
    'DELETE /v1/apps/{appId}/ads/audiences/{audienceId}' => fn (Client $c) => $c->ads->deleteAudience('adu_1', true),
    'POST /v1/apps/{appId}/ads/audiences/{audienceId}/sync' => fn (Client $c) => $c->ads->syncAudience('adu_1', ['full' => true]),
    'GET /v1/apps/{appId}/ads/audiences/{audienceId}/syncs' => fn (Client $c) => $c->ads->listSyncs('adu_1', ['limit' => 5]),
    'POST /v1/apps/{appId}/ads/audiences/{audienceId}/lookalikes' => fn (Client $c) => $c->ads->createLookalike('adu_1', ['country' => 'TR', 'ratio' => 0.01]),
    // v3: reklam raporlama, Conversions API ve bütçe uyarıları (ADR-0038)
    'GET /v1/apps/{appId}/ads/campaigns' => fn (Client $c) => $c->ads->listCampaigns(['level' => 'ad_set', 'parent_id' => 'ade_1']),
    'GET /v1/apps/{appId}/ads/campaigns/{entityId}' => fn (Client $c) => $c->ads->getCampaign('ade_1'),
    'PATCH /v1/apps/{appId}/ads/campaigns/{entityId}' => fn (Client $c) => $c->ads->updateCampaign('ade_1', ['status' => 'paused', 'daily_budget' => '150.00']),
    'POST /v1/apps/{appId}/ads/accounts/{accountId}/refresh' => fn (Client $c) => $c->ads->refreshAccount('ada_1'),
    'GET /v1/apps/{appId}/ads/reports/spend' => fn (Client $c) => $c->ads->spendReport(['from' => '2026-10-01', 'to' => '2026-10-07', 'group_by' => 'platform']),
    'GET /v1/apps/{appId}/ads/reports/attribution' => fn (Client $c) => $c->ads->attributionReport(['from' => '2026-10-01', 'to' => '2026-10-07', 'model' => 'linear']),
    'GET /v1/apps/{appId}/ads/reports/ltv' => fn (Client $c) => $c->ads->ltvReport(['months' => 6]),
    'GET /v1/apps/{appId}/ads/conversions-api/configs' => fn (Client $c) => $c->ads->listCapiConfigs(),
    'GET /v1/apps/{appId}/ads/conversions-api/deliveries' => fn (Client $c) => $c->ads->listCapiDeliveries(['status' => 'failed', 'limit' => 20]),
    'GET /v1/apps/{appId}/ads/alert-rules' => fn (Client $c) => $c->ads->listAlertRules(),
    'POST /v1/apps/{appId}/ads/alert-rules' => fn (Client $c) => $c->ads->createAlertRule(['name' => 'Günlük tavan', 'kind' => 'daily_spend', 'threshold' => 500]),
    'PATCH /v1/apps/{appId}/ads/alert-rules/{ruleId}' => fn (Client $c) => $c->ads->updateAlertRule('abr_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/ads/alert-rules/{ruleId}' => fn (Client $c) => $c->ads->deleteAlertRule('abr_1'),
    'GET /v1/apps/{appId}/ads/alerts' => fn (Client $c) => $c->ads->listAlerts(['rule_id' => 'abr_1']),
    // v3: konuşma yönetimi ve ekip gelen kutusu (ADR-0040)
    'PATCH /v1/apps/{appId}/conversations/{conversationId}' => fn (Client $c) => $c->conversations->update('cnv_1', ['status' => 'resolved']),
    'POST /v1/apps/{appId}/conversations/{conversationId}/assign' => fn (Client $c) => $c->conversations->assign('cnv_1', ['assignee_id' => 'usr_1', 'team_id' => null]),
    'POST /v1/apps/{appId}/conversations/{conversationId}/tags' => fn (Client $c) => $c->conversations->updateTags('cnv_1', ['add' => ['vip' => '1'], 'remove' => ['lead']]),
    'GET /v1/apps/{appId}/conversations/{conversationId}/activity' => fn (Client $c) => $c->conversations->activity('cnv_1'),
    'GET /v1/apps/{appId}/canned-responses' => fn (Client $c) => $c->inbox->listCannedResponses(['q' => 'iade']),
    'POST /v1/apps/{appId}/canned-responses' => fn (Client $c) => $c->inbox->createCannedResponse(['shortcut' => 'iade', 'title' => 'İade', 'body' => 'Merhaba {{ contact.name }}']),
    'PATCH /v1/apps/{appId}/canned-responses/{cannedId}' => fn (Client $c) => $c->inbox->updateCannedResponse('cr_1', ['title' => 'İade süreci']),
    'DELETE /v1/apps/{appId}/canned-responses/{cannedId}' => fn (Client $c) => $c->inbox->deleteCannedResponse('cr_1'),
    'GET /v1/apps/{appId}/inbox/teams' => fn (Client $c) => $c->inbox->listTeams(),
    'GET /v1/apps/{appId}/inbox/agents' => fn (Client $c) => $c->inbox->listAgents(),
    'GET /v1/apps/{appId}/inbox/settings' => fn (Client $c) => $c->inbox->getSettings(),
    'PUT /v1/apps/{appId}/inbox/settings' => fn (Client $c) => $c->inbox->updateSettings(['routing' => ['auto_assign' => true, 'visibility' => 'team']]),
    'GET /v1/apps/{appId}/inbox/metrics' => fn (Client $c) => $c->inbox->metrics(['from' => '2026-10-01T00:00:00Z', 'channel' => 'chat']),
    // v3: web büyüme (ADR-0039)
    'GET /v1/apps/{appId}/web-campaigns' => fn (Client $c) => $c->webCampaigns->list(['status' => 'active']),
    'POST /v1/apps/{appId}/web-campaigns' => fn (Client $c) => $c->webCampaigns->create(['name' => 'Hoş geldin', 'display' => ['layout' => 'popup', 'blocks' => [['type' => 'title', 'text' => ['tr' => 'Hoş geldiniz']]]], 'trigger' => ['kind' => 'page_load']]),
    'GET /v1/apps/{appId}/web-campaigns/{campaignId}' => fn (Client $c) => $c->webCampaigns->get('wcm_1'),
    'PATCH /v1/apps/{appId}/web-campaigns/{campaignId}' => fn (Client $c) => $c->webCampaigns->update('wcm_1', ['priority' => 5]),
    'GET /v1/apps/{appId}/web-campaigns/{campaignId}/report' => fn (Client $c) => $c->webCampaigns->report('wcm_1', ['days' => 7]),
    'POST /v1/apps/{appId}/web-campaigns/{campaignId}/status' => fn (Client $c) => $c->webCampaigns->setStatus('wcm_1', 'active'),
    'GET /v1/apps/{appId}/forms' => fn (Client $c) => $c->forms->list(),
    'POST /v1/apps/{appId}/forms' => fn (Client $c) => $c->forms->create(['name' => 'Bülten', 'fields' => [['id' => 'email', 'type' => 'email', 'label' => ['tr' => 'E-posta'], 'required' => true]]]),
    'GET /v1/apps/{appId}/forms/{formId}' => fn (Client $c) => $c->forms->get('frm_1'),
    'GET /v1/apps/{appId}/forms/{formId}/submissions' => fn (Client $c) => $c->forms->listSubmissions('frm_1', ['status' => 'confirmed', 'limit' => 50]),
    'POST /v1/apps/{appId}/forms/{formId}/submissions' => fn (Client $c) => $c->forms->submit('frm_1', ['values' => ['email' => 'a@pushizo.com'], 'external_id' => 'u1']),
    'GET /v1/apps/{appId}/surveys' => fn (Client $c) => $c->surveys->list(),
    'POST /v1/apps/{appId}/surveys' => fn (Client $c) => $c->surveys->create(['name' => 'NPS', 'questions' => [['id' => 'q1', 'type' => 'nps', 'text' => ['tr' => 'Bizi önerir misiniz?']]]]),
    'GET /v1/apps/{appId}/surveys/{surveyId}' => fn (Client $c) => $c->surveys->get('srv_1'),
    'POST /v1/apps/{appId}/surveys/{surveyId}/status' => fn (Client $c) => $c->surveys->setStatus('srv_1', 'active'),
    'GET /v1/apps/{appId}/surveys/{surveyId}/report' => fn (Client $c) => $c->surveys->report('srv_1', ['days' => 30]),
    'GET /v1/apps/{appId}/surveys/{surveyId}/responses' => fn (Client $c) => $c->surveys->listResponses('srv_1', ['nps_category' => 'detractor']),
    'POST /v1/apps/{appId}/surveys/{surveyId}/responses' => fn (Client $c) => $c->surveys->respond('srv_1', ['answers' => ['q1' => 9], 'external_id' => 'u1']),
    'GET /v1/apps/{appId}/landing-pages' => fn (Client $c) => $c->landingPages->list(['status' => 'published']),
    'GET /v1/apps/{appId}/landing-pages/{pageId}' => fn (Client $c) => $c->landingPages->get('lp_1'),
    'POST /v1/apps/{appId}/landing-pages/{pageId}/publish' => fn (Client $c) => $c->landingPages->publish('lp_1'),
    'POST /v1/apps/{appId}/landing-pages/{pageId}/unpublish' => fn (Client $c) => $c->landingPages->unpublish('lp_1'),
    // Faz 33–48 kaynakları (plan 15 Faz 52).
    'POST /v1/apps/{appId}/analytics/paths' => fn (Client $c) => $c->analytics->paths(['event' => 'signup']),
    'POST /v1/apps/{appId}/analytics/reports/run' => fn (Client $c) => $c->analytics->runReport(['source' => 'deliveries', 'metrics' => ['sent']]),
    'GET /v1/apps/{appId}/analytics/reports' => fn (Client $c) => $c->savedReports->list(),
    'POST /v1/apps/{appId}/analytics/reports' => fn (Client $c) => $c->savedReports->create(['name' => 'Rapor', 'definition' => ['source' => 'deliveries', 'metrics' => ['sent']]]),
    'GET /v1/apps/{appId}/analytics/reports/{id}' => fn (Client $c) => $c->savedReports->get('rpt_1'),
    'PATCH /v1/apps/{appId}/analytics/reports/{id}' => fn (Client $c) => $c->savedReports->update('rpt_1', ['name' => 'Yeni']),
    'DELETE /v1/apps/{appId}/analytics/reports/{id}' => fn (Client $c) => $c->savedReports->delete('rpt_1'),
    'POST /v1/apps/{appId}/journeys/{journeyId}/versions/{version}/restore' => fn (Client $c) => $c->journeys->restoreVersion('jrn_1', 2),
    'GET /v1/apps/{appId}/decision-agents' => fn (Client $c) => $c->decisionAgents->list(),
    'POST /v1/apps/{appId}/decision-agents' => fn (Client $c) => $c->decisionAgents->create(['slug' => 'teklif', 'name' => 'Teklif', 'arms' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'reward_events' => ['purchase']]),
    'GET /v1/apps/{appId}/decision-agents/{id}' => fn (Client $c) => $c->decisionAgents->get('dag_1'),
    'PATCH /v1/apps/{appId}/decision-agents/{id}' => fn (Client $c) => $c->decisionAgents->update('dag_1', ['status' => 'paused']),
    'DELETE /v1/apps/{appId}/decision-agents/{id}' => fn (Client $c) => $c->decisionAgents->delete('dag_1'),
    'GET /v1/apps/{appId}/decision-agents/{id}/report' => fn (Client $c) => $c->decisionAgents->report('dag_1'),
    'POST /v1/apps/{appId}/decision-agents/{id}/decide' => fn (Client $c) => $c->decisionAgents->decide('dag_1', 'kisi-1'),
    'GET /v1/apps/{appId}/predictions' => fn (Client $c) => $c->predictions->list(),
    'POST /v1/apps/{appId}/predictions' => fn (Client $c) => $c->predictions->create(['slug' => 'kayip', 'name' => 'Kayıp', 'kind' => 'churn']),
    'GET /v1/apps/{appId}/predictions/{id}' => fn (Client $c) => $c->predictions->get('prm_1'),
    'PATCH /v1/apps/{appId}/predictions/{id}' => fn (Client $c) => $c->predictions->update('prm_1', ['name' => 'Yeni']),
    'DELETE /v1/apps/{appId}/predictions/{id}' => fn (Client $c) => $c->predictions->delete('prm_1'),
    'POST /v1/apps/{appId}/predictions/{id}/compute' => fn (Client $c) => $c->predictions->compute('prm_1'),
    'GET /v1/apps/{appId}/predictions/{id}/users' => fn (Client $c) => $c->predictions->users('prm_1', ['bucket' => 'high']),
    'GET /v1/apps/{appId}/wallet/config' => fn (Client $c) => $c->wallet->getConfig(),
    'PUT /v1/apps/{appId}/wallet/config' => fn (Client $c) => $c->wallet->updateConfig(['google' => null]),
    'GET /v1/apps/{appId}/wallet/templates' => fn (Client $c) => $c->wallet->listTemplates(),
    'POST /v1/apps/{appId}/wallet/templates' => fn (Client $c) => $c->wallet->createTemplate(['slug' => 'kupon', 'name' => 'Kupon', 'kind' => 'coupon', 'design' => ['title' => 'T', 'description' => 'A', 'organization' => 'O']]),
    'GET /v1/apps/{appId}/wallet/templates/{id}' => fn (Client $c) => $c->wallet->getTemplate('wlt_1'),
    'PATCH /v1/apps/{appId}/wallet/templates/{id}' => fn (Client $c) => $c->wallet->updateTemplate('wlt_1', ['name' => 'Yeni']),
    'DELETE /v1/apps/{appId}/wallet/templates/{id}' => fn (Client $c) => $c->wallet->deleteTemplate('wlt_1'),
    'GET /v1/apps/{appId}/wallet/passes' => fn (Client $c) => $c->wallet->listPasses(['template_id' => 'wlt_1']),
    'POST /v1/apps/{appId}/wallet/passes' => fn (Client $c) => $c->wallet->issuePass(['template_id' => 'wlt_1']),
    'GET /v1/apps/{appId}/wallet/passes/{id}' => fn (Client $c) => $c->wallet->getPass('wlp_1'),
    'PATCH /v1/apps/{appId}/wallet/passes/{id}' => fn (Client $c) => $c->wallet->updatePass('wlp_1', ['barcode_value' => 'x']),
    'POST /v1/apps/{appId}/wallet/passes/{id}/void' => fn (Client $c) => $c->wallet->voidPass('wlp_1'),
    'GET /v1/apps/{appId}/feature-flags' => fn (Client $c) => $c->featureFlags->list(),
    'POST /v1/apps/{appId}/feature-flags' => fn (Client $c) => $c->featureFlags->create(['key' => 'yeni_sepet', 'name' => 'Yeni sepet']),
    'POST /v1/apps/{appId}/feature-flags/evaluate' => fn (Client $c) => $c->featureFlags->evaluate(['external_id' => 'u1']),
    'GET /v1/apps/{appId}/feature-flags/{flagId}' => fn (Client $c) => $c->featureFlags->get('ffl_1'),
    'PATCH /v1/apps/{appId}/feature-flags/{flagId}' => fn (Client $c) => $c->featureFlags->update('ffl_1', ['enabled' => true]),
    'DELETE /v1/apps/{appId}/feature-flags/{flagId}' => fn (Client $c) => $c->featureFlags->delete('ffl_1'),
    'GET /v1/apps/{appId}/geofences' => fn (Client $c) => $c->geofences->list(),
    'POST /v1/apps/{appId}/geofences' => fn (Client $c) => $c->geofences->create(['name' => 'Mağaza', 'lat' => 41.0, 'lng' => 29.0, 'radius_m' => 200]),
    'GET /v1/apps/{appId}/geofences/{geofenceId}' => fn (Client $c) => $c->geofences->get('gfn_1'),
    'PATCH /v1/apps/{appId}/geofences/{geofenceId}' => fn (Client $c) => $c->geofences->update('gfn_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/geofences/{geofenceId}' => fn (Client $c) => $c->geofences->delete('gfn_1'),
    'GET /v1/apps/{appId}/channel-scores/config' => fn (Client $c) => $c->channelScores->getConfig(),
    'PUT /v1/apps/{appId}/channel-scores/config' => fn (Client $c) => $c->channelScores->updateConfig(['enabled' => true]),
    'POST /v1/apps/{appId}/channel-scores/compute' => fn (Client $c) => $c->channelScores->compute(),
    'GET /v1/apps/{appId}/channel-scores/overview' => fn (Client $c) => $c->channelScores->overview(),
    'GET /v1/apps/{appId}/channel-scores/users' => fn (Client $c) => $c->channelScores->users(['channel' => 'email']),
    'GET /v1/apps/{appId}/channel-scores/users/{userId}' => fn (Client $c) => $c->channelScores->user('usr_1'),
    'GET /v1/apps/{appId}/rfm/config' => fn (Client $c) => $c->rfm->getConfig(),
    'PUT /v1/apps/{appId}/rfm/config' => fn (Client $c) => $c->rfm->updateConfig(['enabled' => true, 'event_names' => ['purchase']]),
    'POST /v1/apps/{appId}/rfm/compute' => fn (Client $c) => $c->rfm->compute(),
    'GET /v1/apps/{appId}/rfm/overview' => fn (Client $c) => $c->rfm->overview(),
    'GET /v1/apps/{appId}/rfm/transitions' => fn (Client $c) => $c->rfm->transitions(['from' => '2026-01-01']),
    'GET /v1/apps/{appId}/rfm/users' => fn (Client $c) => $c->rfm->users(['segment' => 'champions']),
    'GET /v1/apps/{appId}/catalogs/{catalogId}/recommendations' => fn (Client $c) => $c->recommendations->list('ctl_1', ['strategy' => 'popular']),
    'GET /v1/apps/{appId}/catalogs/{catalogId}/recommendations/config' => fn (Client $c) => $c->recommendations->getConfig('ctl_1'),
    'PUT /v1/apps/{appId}/catalogs/{catalogId}/recommendations/config' => fn (Client $c) => $c->recommendations->updateConfig('ctl_1', ['enabled' => true, 'purchase_events' => ['purchase']]),
    'POST /v1/apps/{appId}/catalogs/{catalogId}/recommendations/compute' => fn (Client $c) => $c->recommendations->compute('ctl_1'),
    'GET /v1/apps/{appId}/catalogs' => fn (Client $c) => $c->catalogs->list(),
    'POST /v1/apps/{appId}/catalogs' => fn (Client $c) => $c->catalogs->create(['name' => 'urunler']),
    'GET /v1/apps/{appId}/catalogs/{catalogId}' => fn (Client $c) => $c->catalogs->get('ctl_1'),
    'PATCH /v1/apps/{appId}/catalogs/{catalogId}' => fn (Client $c) => $c->catalogs->update('ctl_1', ['description' => 'Ana']),
    'DELETE /v1/apps/{appId}/catalogs/{catalogId}' => fn (Client $c) => $c->catalogs->delete('ctl_1'),
    'GET /v1/apps/{appId}/catalogs/{catalogId}/items' => fn (Client $c) => $c->catalogs->items('ctl_1', ['limit' => 10]),
    'PUT /v1/apps/{appId}/catalogs/{catalogId}/items' => fn (Client $c) => $c->catalogs->upsertItems('ctl_1', ['items' => [['id' => 'A1', 'title' => 'Kalem']]]),
    'POST /v1/apps/{appId}/catalogs/{catalogId}/items/upload' => fn (Client $c) => $c->catalogs->uploadItems('ctl_1', "id,title\nA1,Kalem\n"),
    'GET /v1/apps/{appId}/catalogs/{catalogId}/items/{itemId}' => fn (Client $c) => $c->catalogs->getItem('ctl_1', 'A1'),
    'DELETE /v1/apps/{appId}/catalogs/{catalogId}/items/{itemId}' => fn (Client $c) => $c->catalogs->deleteItem('ctl_1', 'A1'),
    'GET /v1/apps/{appId}/global-control' => fn (Client $c) => $c->globalControl->get(),
    'PUT /v1/apps/{appId}/global-control' => fn (Client $c) => $c->globalControl->update(['enabled' => true, 'percent' => 5]),
    'POST /v1/apps/{appId}/global-control/reset' => fn (Client $c) => $c->globalControl->reset(),
    'GET /v1/apps/{appId}/global-control/report' => fn (Client $c) => $c->globalControl->report(['days' => 30]),
    'GET /v1/apps/{appId}/promo-code-lists' => fn (Client $c) => $c->promoCodeLists->list(),
    'POST /v1/apps/{appId}/promo-code-lists' => fn (Client $c) => $c->promoCodeLists->create(['name' => 'yaz_indirimi']),
    'GET /v1/apps/{appId}/promo-code-lists/{listId}' => fn (Client $c) => $c->promoCodeLists->get('pcl_1'),
    'PATCH /v1/apps/{appId}/promo-code-lists/{listId}' => fn (Client $c) => $c->promoCodeLists->update('pcl_1', ['description' => 'Yaz']),
    'DELETE /v1/apps/{appId}/promo-code-lists/{listId}' => fn (Client $c) => $c->promoCodeLists->delete('pcl_1'),
    'POST /v1/apps/{appId}/promo-code-lists/{listId}/codes' => fn (Client $c) => $c->promoCodeLists->uploadCodes('pcl_1', "code\nA1\n"),
    'GET /v1/apps/{appId}/promo-code-lists/{listId}/codes' => fn (Client $c) => $c->promoCodeLists->codes('pcl_1', ['limit' => 10, 'status' => 'assigned']),
    // v2: Zapier ve MCP
    'GET /v1/apps/{appId}/integrations/zapier/me' => fn (Client $c) => $c->zapier->me(),
    'POST /v1/apps/{appId}/integrations/zapier/hooks' => fn (Client $c) => $c->zapier->subscribe(['target_url' => 'https://hooks.zapier.com/x', 'event' => 'user.created']),
    'DELETE /v1/apps/{appId}/integrations/zapier/hooks/{hookId}' => fn (Client $c) => $c->zapier->unsubscribe('zh_1'),
    'GET /v1/apps/{appId}/integrations/zapier/samples' => fn (Client $c) => $c->zapier->samples(['event' => 'user.created']),
    'POST /v1/apps/{appId}/mcp/campaign-drafts' => fn (Client $c) => $c->mcp->createCampaignDraft(['name' => 'Taslak']),
    'POST /v1/apps/{appId}/mcp/segment-drafts' => fn (Client $c) => $c->mcp->createSegmentDraft(['name' => 'VIP', 'definition' => $definition]),
    'POST /v1/apps/{appId}/mcp/template-drafts' => fn (Client $c) => $c->mcp->createTemplateDraft(['name' => 'Şablon', 'content' => ['contents' => ['tr' => 'Merhaba']]]),
    'POST /v1/apps/{appId}/mcp/journey-drafts' => fn (Client $c) => $c->mcp->createJourneyDraft(['name' => 'Akış']),
    'POST /v1/apps/{appId}/mcp/in-app-drafts' => fn (Client $c) => $c->mcp->createInAppDraft(['name' => 'Duyuru', 'triggers' => [['kind' => 'session_start']], 'display' => ['layout' => 'modal', 'blocks' => [['type' => 'title', 'text' => ['tr' => 'Yeni']]]]]),
    'POST /v1/apps/{appId}/mcp/test-sends' => fn (Client $c) => $c->mcp->sendTest(['contents' => ['tr' => 'Test']]),
    'POST /v1/apps/{appId}/mcp/web-campaign-drafts' => fn (Client $c) => $c->mcp->createWebCampaignDraft(['name' => 'Bülten', 'display' => ['layout' => 'popup', 'blocks' => [['type' => 'title', 'text' => ['tr' => 'Bülten']]]], 'trigger' => ['kind' => 'page_load']]),
    'POST /v1/apps/{appId}/mcp/web-personalization-drafts' => fn (Client $c) => $c->mcp->createWebPersonalizationDraft(['name' => 'Başlık', 'changes' => [['action' => 'text', 'selector' => 'h1', 'text' => ['tr' => 'Merhaba']]]]),
    'POST /v1/apps/{appId}/mcp/survey-drafts' => fn (Client $c) => $c->mcp->createSurveyDraft(['name' => 'NPS', 'questions' => [['id' => 'nps', 'type' => 'nps', 'text' => ['tr' => 'Bizi tavsiye eder misiniz?']]]]),
    'POST /v1/apps/{appId}/mcp/landing-page-drafts' => fn (Client $c) => $c->mcp->createLandingPageDraft(['name' => 'Kış kampanyası', 'slug' => 'kis-kampanyasi', 'document' => ['version' => 1, 'sections' => []]]),
    // Site araması, ambar/SQL segmentleri, kişiselleştirme, ilgi, şirketler, AI ajanları ve veri boru hattı
    'POST /v1/apps/{appId}/messenger-links' => fn (Client $c) => $c->channels->createMessengerLink(['channel' => 'telegram', 'external_id' => 'musteri-42']),
    'GET /v1/apps/{appId}/analytics/uninstalls' => fn (Client $c) => $c->analytics->uninstalls(),
    'GET /v1/apps/{appId}/analytics/android-delivery' => fn (Client $c) => $c->analytics->androidDelivery(),
    'POST /v1/apps/{appId}/campaigns/{campaignId}/preflight' => fn (Client $c) => $c->campaigns->preflight('cmp_1', null, true),
    'GET /v1/apps/{appId}/affinity/config' => fn (Client $c) => $c->affinity->getConfig(),
    'PUT /v1/apps/{appId}/affinity/config' => fn (Client $c) => $c->affinity->updateConfig(['enabled' => true, 'attributes' => ['category'], 'events' => ['purchase' => 2]]),
    'POST /v1/apps/{appId}/affinity/compute' => fn (Client $c) => $c->affinity->compute(),
    'GET /v1/apps/{appId}/affinity/overview' => fn (Client $c) => $c->affinity->overview(),
    'GET /v1/apps/{appId}/affinity/users/{userId}' => fn (Client $c) => $c->affinity->user('usr_1'),
    'GET /v1/apps/{appId}/sql/schema' => fn (Client $c) => $c->sqlSegments->schema(),
    'POST /v1/apps/{appId}/sql/query' => fn (Client $c) => $c->sqlSegments->query(['query' => 'select user_id from users', 'row_limit' => 10]),
    'GET /v1/apps/{appId}/sql/runs' => fn (Client $c) => $c->sqlSegments->runs(['status' => 'rejected']),
    'GET /v1/apps/{appId}/sql/segments' => fn (Client $c) => $c->sqlSegments->listSegments(),
    'POST /v1/apps/{appId}/sql/segments' => fn (Client $c) => $c->sqlSegments->createSegment(['name' => 'Altın', 'query' => 'select user_id from users', 'refresh_interval_minutes' => 60]),
    'PATCH /v1/apps/{appId}/sql/segments/{segmentId}' => fn (Client $c) => $c->sqlSegments->updateSegment('seg_1', ['refresh_interval_minutes' => null]),
    'POST /v1/apps/{appId}/sql/segments/{segmentId}/refresh' => fn (Client $c) => $c->sqlSegments->refreshSegment('seg_1'),
    'GET /v1/apps/{appId}/companies' => fn (Client $c) => $c->companies->list(['q' => 'acme']),
    'POST /v1/apps/{appId}/companies' => fn (Client $c) => $c->companies->upsert(['external_id' => 'acme', 'name' => 'Acme', 'properties' => ['plan' => 'pro']]),
    'POST /v1/apps/{appId}/companies/batch' => fn (Client $c) => $c->companies->batchUpsert(['companies' => [['external_id' => 'acme']]]),
    'GET /v1/apps/{appId}/companies/{companyId}' => fn (Client $c) => $c->companies->get('cpy_1'),
    'PATCH /v1/apps/{appId}/companies/{companyId}' => fn (Client $c) => $c->companies->update('cpy_1', ['name' => 'Acme A.Ş.']),
    'DELETE /v1/apps/{appId}/companies/{companyId}' => fn (Client $c) => $c->companies->delete('cpy_1'),
    'GET /v1/apps/{appId}/companies/{companyId}/members' => fn (Client $c) => $c->companies->members('cpy_1', ['role' => 'admin']),
    'POST /v1/apps/{appId}/companies/{companyId}/members/batch' => fn (Client $c) => $c->companies->batchMembers('cpy_1', ['members' => [['external_id' => 'ayse', 'role' => 'admin']]]),
    'PUT /v1/apps/{appId}/companies/{companyId}/members/{userId}' => fn (Client $c) => $c->companies->setMember('cpy_1', 'usr_1', ['role' => 'billing']),
    'DELETE /v1/apps/{appId}/companies/{companyId}/members/{userId}' => fn (Client $c) => $c->companies->removeMember('cpy_1', 'usr_1'),
    'GET /v1/apps/{appId}/users/{userId}/companies' => fn (Client $c) => $c->companies->forUser('usr_1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/companies' => fn (Client $c) => $c->companies->forUserBy('external_id', 'ayse'),
    'GET /v1/apps/{appId}/warehouse-segments/options' => fn (Client $c) => $c->warehouseSegments->options(),
    'GET /v1/apps/{appId}/warehouse-segments/connections' => fn (Client $c) => $c->warehouseSegments->listConnections(),
    'POST /v1/apps/{appId}/warehouse-segments/connections' => fn (Client $c) => $c->warehouseSegments->createConnection(['name' => 'Databricks', 'type' => 'databricks', 'config' => ['host' => 'adb-1.azuredatabricks.net', 'warehouse_id' => 'abc123def456'], 'credentials' => ['access_token' => 'dapi_x']]),
    'GET /v1/apps/{appId}/warehouse-segments/connections/{connectionId}' => fn (Client $c) => $c->warehouseSegments->getConnection('whc_1'),
    'PATCH /v1/apps/{appId}/warehouse-segments/connections/{connectionId}' => fn (Client $c) => $c->warehouseSegments->updateConnection('whc_1', ['name' => 'Yeni']),
    'DELETE /v1/apps/{appId}/warehouse-segments/connections/{connectionId}' => fn (Client $c) => $c->warehouseSegments->deleteConnection('whc_1'),
    'POST /v1/apps/{appId}/warehouse-segments/connections/{connectionId}/test' => fn (Client $c) => $c->warehouseSegments->testConnection('whc_1'),
    'POST /v1/apps/{appId}/warehouse-segments/preview' => fn (Client $c) => $c->warehouseSegments->preview(['connection_id' => 'whc_1', 'query' => 'select id from t', 'id_column' => 'id', 'mode' => 'dry_run']),
    'GET /v1/apps/{appId}/warehouse-segments/segments' => fn (Client $c) => $c->warehouseSegments->listSegments(),
    'POST /v1/apps/{appId}/warehouse-segments/segments' => fn (Client $c) => $c->warehouseSegments->createSegment(['name' => 'VIP', 'connection_id' => 'whc_1', 'query' => 'select id from t', 'id_column' => 'id']),
    'PATCH /v1/apps/{appId}/warehouse-segments/segments/{segmentId}' => fn (Client $c) => $c->warehouseSegments->updateSegment('seg_1', ['refresh_interval_minutes' => 1440]),
    'POST /v1/apps/{appId}/warehouse-segments/segments/{segmentId}/refresh' => fn (Client $c) => $c->warehouseSegments->refreshSegment('seg_1'),
    'GET /v1/apps/{appId}/warehouse-segments/runs' => fn (Client $c) => $c->warehouseSegments->runs(['status' => 'failed']),
    'GET /v1/apps/{appId}/ai-agents' => fn (Client $c) => $c->aiAgents->list(),
    'POST /v1/apps/{appId}/ai-agents' => fn (Client $c) => $c->aiAgents->create(['name' => 'Destek', 'channels' => ['sms']]),
    'GET /v1/apps/{appId}/ai-agents/runs' => fn (Client $c) => $c->aiAgents->runs(['limit' => 10]),
    'GET /v1/apps/{appId}/ai-agents/{agentId}' => fn (Client $c) => $c->aiAgents->get('aia_1'),
    'PATCH /v1/apps/{appId}/ai-agents/{agentId}' => fn (Client $c) => $c->aiAgents->update('aia_1', ['enabled' => true]),
    'DELETE /v1/apps/{appId}/ai-agents/{agentId}' => fn (Client $c) => $c->aiAgents->delete('aia_1'),
    'POST /v1/apps/{appId}/ai-agents/{agentId}/rotate-secret' => fn (Client $c) => $c->aiAgents->rotateSecret('aia_1'),
    'GET /v1/apps/{appId}/ai-agents/{agentId}/metrics' => fn (Client $c) => $c->aiAgents->metrics('aia_1'),
    'GET /v1/apps/{appId}/custom-agents' => fn (Client $c) => $c->customAgents->list(),
    'POST /v1/apps/{appId}/custom-agents' => fn (Client $c) => $c->customAgents->create(['name' => 'Analist', 'tools' => ['list_segments']]),
    'GET /v1/apps/{appId}/custom-agents/runs' => fn (Client $c) => $c->customAgents->runs(['limit' => 10]),
    'GET /v1/apps/{appId}/custom-agents/drafts' => fn (Client $c) => $c->customAgents->drafts(['status' => 'pending']),
    'GET /v1/apps/{appId}/custom-agents/{agentId}' => fn (Client $c) => $c->customAgents->get('cag_1'),
    'PATCH /v1/apps/{appId}/custom-agents/{agentId}' => fn (Client $c) => $c->customAgents->update('cag_1', ['enabled' => true]),
    'DELETE /v1/apps/{appId}/custom-agents/{agentId}' => fn (Client $c) => $c->customAgents->delete('cag_1'),
    'POST /v1/apps/{appId}/custom-agents/{agentId}/run' => fn (Client $c) => $c->customAgents->run('cag_1'),
    'GET /v1/apps/{appId}/site-search' => fn (Client $c) => $c->siteSearch->get(),
    'PATCH /v1/apps/{appId}/site-search/settings' => fn (Client $c) => $c->siteSearch->updateSettings(['enabled' => true, 'ai_answers' => true]),
    'GET /v1/apps/{appId}/site-search/sources' => fn (Client $c) => $c->siteSearch->listSources(),
    'POST /v1/apps/{appId}/site-search/sources' => fn (Client $c) => $c->siteSearch->createSource(['name' => 'Site', 'kind' => 'sitemap', 'url' => 'https://www.example.com/sitemap.xml']),
    'PATCH /v1/apps/{appId}/site-search/sources/{sourceId}' => fn (Client $c) => $c->siteSearch->updateSource('ssrc_1', ['refresh_hours' => 12]),
    'DELETE /v1/apps/{appId}/site-search/sources/{sourceId}' => fn (Client $c) => $c->siteSearch->deleteSource('ssrc_1'),
    'POST /v1/apps/{appId}/site-search/sources/{sourceId}/crawl' => fn (Client $c) => $c->siteSearch->crawlSource('ssrc_1'),
    'GET /v1/apps/{appId}/site-search/documents' => fn (Client $c) => $c->siteSearch->listDocuments(['source_id' => 'api']),
    'PUT /v1/apps/{appId}/site-search/documents/{documentKey}' => fn (Client $c) => $c->siteSearch->putDocument('kargo', ['url' => 'https://www.example.com/kargo', 'content' => 'Kargo ücretsiz.']),
    'DELETE /v1/apps/{appId}/site-search/documents/{documentKey}' => fn (Client $c) => $c->siteSearch->deleteDocument('kargo'),
    'GET /v1/apps/{appId}/site-search/report' => fn (Client $c) => $c->siteSearch->report(['days' => 7]),
    'GET /v1/apps/{appId}/data-pipelines/transforms' => fn (Client $c) => $c->dataPipelines->listTransforms(['target' => 'event']),
    'POST /v1/apps/{appId}/data-pipelines/transforms' => fn (Client $c) => $c->dataPipelines->createTransform(['name' => 'Fiyat', 'target' => 'event', 'kind' => 'rules', 'rules' => [['op' => 'cast', 'path' => 'properties.price', 'to' => 'number']]]),
    'POST /v1/apps/{appId}/data-pipelines/transforms/preview' => fn (Client $c) => $c->dataPipelines->previewTransform(['target' => 'event', 'records' => [['name' => 'checkout', 'properties' => new \stdClass()]]]),
    'GET /v1/apps/{appId}/data-pipelines/transforms/{transformId}' => fn (Client $c) => $c->dataPipelines->getTransform('dtf_1'),
    'PATCH /v1/apps/{appId}/data-pipelines/transforms/{transformId}' => fn (Client $c) => $c->dataPipelines->updateTransform('dtf_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/data-pipelines/transforms/{transformId}' => fn (Client $c) => $c->dataPipelines->deleteTransform('dtf_1'),
    'GET /v1/apps/{appId}/data-pipelines/sources' => fn (Client $c) => $c->dataPipelines->listSources(),
    'POST /v1/apps/{appId}/data-pipelines/sources' => fn (Client $c) => $c->dataPipelines->createSource(['name' => 'Mağaza', 'kind' => 'webhook', 'target' => 'event']),
    'GET /v1/apps/{appId}/data-pipelines/sources/{sourceId}' => fn (Client $c) => $c->dataPipelines->getSource('dsrc_1'),
    'PATCH /v1/apps/{appId}/data-pipelines/sources/{sourceId}' => fn (Client $c) => $c->dataPipelines->updateSource('dsrc_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/data-pipelines/sources/{sourceId}' => fn (Client $c) => $c->dataPipelines->deleteSource('dsrc_1'),
    'POST /v1/apps/{appId}/data-pipelines/sources/{sourceId}/rotate-secret' => fn (Client $c) => $c->dataPipelines->rotateSourceSecret('dsrc_1'),
    'POST /v1/apps/{appId}/data-pipelines/sources/{sourceId}/run' => fn (Client $c) => $c->dataPipelines->runSource('dsrc_1'),
    'GET /v1/apps/{appId}/data-pipelines/quarantine' => fn (Client $c) => $c->dataPipelines->listQuarantine(['status' => 'pending']),
    'POST /v1/apps/{appId}/data-pipelines/quarantine/reprocess' => fn (Client $c) => $c->dataPipelines->reprocessQuarantine(['ids' => ['dqr_1']]),
    'POST /v1/apps/{appId}/data-pipelines/quarantine/discard' => fn (Client $c) => $c->dataPipelines->discardQuarantine(['ids' => ['dqr_1']]),
    'GET /v1/apps/{appId}/web-personalizations' => fn (Client $c) => $c->webPersonalizations->list(['status' => 'active']),
    'POST /v1/apps/{appId}/web-personalizations' => fn (Client $c) => $c->webPersonalizations->create(['name' => 'Başlık', 'changes' => [['action' => 'text', 'selector' => 'h1', 'text' => ['tr' => 'Merhaba']]]]),
    'GET /v1/apps/{appId}/web-personalizations/{personalizationId}' => fn (Client $c) => $c->webPersonalizations->get('wpz_1'),
    'PATCH /v1/apps/{appId}/web-personalizations/{personalizationId}' => fn (Client $c) => $c->webPersonalizations->update('wpz_1', ['priority' => 5]),
    'GET /v1/apps/{appId}/web-personalizations/{personalizationId}/report' => fn (Client $c) => $c->webPersonalizations->report('wpz_1', ['days' => 7]),
    'POST /v1/apps/{appId}/web-personalizations/{personalizationId}/status' => fn (Client $c) => $c->webPersonalizations->setStatus('wpz_1', 'active'),
    // ---- public API kapsam tamamlama (2. tur, ADR-0095)
    'GET /v1/organization/logs' => fn (Client $c) => $c->organization->logs(['status_class' => '4xx']),
    'GET /v1/apps/{appId}/sdk-errors' => fn (Client $c) => $c->apps->sdkErrors(['level' => 'error']),
    'GET /v1/apps/{appId}/trace' => fn (Client $c) => $c->notifications->trace('msg_1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/timeline' => fn (Client $c) => $c->users->timeline('external_id', 'u1'),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/events' => fn (Client $c) => $c->users->events('external_id', 'u1', ['limit' => 20]),
    'GET /v1/apps/{appId}/users/by/{label}/{value}/messages' => fn (Client $c) => $c->users->messages('external_id', 'u1', ['limit' => 20]),
    'GET /v1/apps/{appId}/subscriptions' => fn (Client $c) => $c->subscriptions->list(['channel' => 'email', 'status' => 'invalid']),
    'POST /v1/apps/{appId}/subscriptions/cleanup' => fn (Client $c) => $c->subscriptions->cleanup('invalid'),
    'GET /v1/apps/{appId}/events/recent' => fn (Client $c) => $c->events->recent(['name' => 'purchase', 'limit' => 10]),
    'GET /v1/apps/{appId}/analytics/sessions' => fn (Client $c) => $c->analytics->sessions(),
    'GET /v1/apps/{appId}/channels/status' => fn (Client $c) => $c->channels->status(),
    'POST /v1/apps/{appId}/channels/email/verify-dns' => fn (Client $c) => $c->channels->verifyEmailDns(),
    'GET /v1/apps/{appId}/webhooks/event-types' => fn (Client $c) => $c->webhooks->eventTypes(),
    'GET /v1/apps/{appId}/webhooks/deliveries/{deliveryId}' => fn (Client $c) => $c->webhooks->getDelivery('whd_1'),
    'POST /v1/apps/{appId}/webhooks/{webhookId}/replay' => fn (Client $c) => $c->webhooks->replayFailed('wh_1', ['status' => 'dead']),
    'GET /v1/apps/{appId}/conversations/{conversationId}/contact' => fn (Client $c) => $c->conversations->contact('cnv_1'),
    'POST /v1/apps/{appId}/inbox/teams' => fn (Client $c) => $c->inbox->createTeam(['name' => 'Destek']),
    'PATCH /v1/apps/{appId}/inbox/teams/{teamId}' => fn (Client $c) => $c->inbox->updateTeam('itm_1', ['name' => 'Destek TR']),
    'DELETE /v1/apps/{appId}/inbox/teams/{teamId}' => fn (Client $c) => $c->inbox->deleteTeam('itm_1'),
    'GET /v1/apps/{appId}/inbox/assignment-rules' => fn (Client $c) => $c->inbox->listAssignmentRules(),
    'POST /v1/apps/{appId}/inbox/assignment-rules' => fn (Client $c) => $c->inbox->createAssignmentRule(['name' => 'WhatsApp', 'strategy' => 'round_robin', 'team_id' => 'itm_1']),
    'PATCH /v1/apps/{appId}/inbox/assignment-rules/{ruleId}' => fn (Client $c) => $c->inbox->updateAssignmentRule('iar_1', ['enabled' => false]),
    'DELETE /v1/apps/{appId}/inbox/assignment-rules/{ruleId}' => fn (Client $c) => $c->inbox->deleteAssignmentRule('iar_1'),
    'PATCH /v1/apps/{appId}/inbox/agents/{accountId}' => fn (Client $c) => $c->inbox->updateAgent('acc_1', ['max_concurrent' => 5]),
    'GET /v1/apps/{appId}/email-deliverability/hygiene' => fn (Client $c) => $c->emailDeliverability->hygiene(),
    'POST /v1/apps/{appId}/email-deliverability/hygiene-runs' => fn (Client $c) => $c->emailDeliverability->startHygieneRun(),
    'GET /v1/apps/{appId}/email-deliverability/hygiene-runs/{runId}' => fn (Client $c) => $c->emailDeliverability->getHygieneRun('ehr_1'),
    'POST /v1/apps/{appId}/email-deliverability/hygiene-runs/{runId}/suppress' => fn (Client $c) => $c->emailDeliverability->suppressHygieneInvalid('ehr_1'),
    'GET /v1/apps/{appId}/email-deliverability/insights' => fn (Client $c) => $c->emailDeliverability->insights(['days' => 30]),
    'GET /v1/apps/{appId}/email-deliverability/bounces' => fn (Client $c) => $c->emailDeliverability->bounces(['days' => 30]),
    'GET /v1/apps/{appId}/integrations' => fn (Client $c) => $c->integrations->list(),
    'GET /v1/apps/{appId}/integrations/zapier/hooks' => fn (Client $c) => $c->integrations->zapierHooks(),
    'GET /v1/apps/{appId}/integrations/{type}' => fn (Client $c) => $c->integrations->get('slack'),
    'GET /v1/apps/{appId}/integrations/{type}/runs' => fn (Client $c) => $c->integrations->runs('slack', ['limit' => 10]),
    'POST /v1/apps/{appId}/integrations/{type}/test' => fn (Client $c) => $c->integrations->test('slack'),
    'POST /v1/apps/{appId}/integrations/{type}/sync' => fn (Client $c) => $c->integrations->sync('amplitude'),
    'GET /v1/apps/{appId}/ads/platforms' => fn (Client $c) => $c->ads->listPlatforms(),
    'POST /v1/apps/{appId}/ads/campaigns' => fn (Client $c) => $c->ads->createCampaign(['ad_account_id' => 'ada_1', 'audience_id' => 'adu_1', 'name' => 'Kış', 'objective' => 'traffic', 'daily_budget' => '100.00', 'countries' => ['TR']]),
    'GET /v1/apps/{appId}/ads/campaigns/{entityId}/preview' => fn (Client $c) => $c->ads->previewCampaign('ade_1'),
    'GET /v1/apps/{appId}/ads/accounts/{accountId}/pages' => fn (Client $c) => $c->ads->listPages('ada_1'),
    'GET /v1/apps/{appId}/ads/creatives' => fn (Client $c) => $c->ads->listCreatives(),
    'POST /v1/apps/{appId}/ads/creatives' => fn (Client $c) => $c->ads->uploadCreative("\x89PNG", 'kreatif.png'),
    'GET /v1/apps/{appId}/ads/settings' => fn (Client $c) => $c->ads->getSettings(),
    'PUT /v1/apps/{appId}/ads/settings' => fn (Client $c) => $c->ads->updateSettings(['reporting_currency' => 'TRY']),
    'POST /v1/apps/{appId}/ads/conversions-api/configs/{configId}/test' => fn (Client $c) => $c->ads->testCapiConfig('acc_1'),
    'POST /v1/apps/{appId}/ads/conversions-api/deliveries/{deliveryId}/retry' => fn (Client $c) => $c->ads->retryCapiDelivery('acd_1'),
    'POST /v1/apps/{appId}/surveys/{surveyId}/distribute/in-app' => fn (Client $c) => $c->surveys->distributeInApp('srv_1', ['publish' => true]),
];

$assert(count($specOps) >= 142, 'spec en az 142 public işlem içerir (' . count($specOps) . ')');
$missing = array_values(array_diff($specOps, array_keys($table)));
$assert($missing === [], 'her spec işlemi tabloda' . ($missing === [] ? '' : ': eksik ' . implode(', ', $missing)));
$stale = array_values(array_diff(array_keys($table), $specOps));
$assert($stale === [], 'tabloda spec dışı işlem yok' . ($stale === [] ? '' : ': ' . implode(', ', $stale)));
$mismatched = [];
foreach ($table as $op => $call) {
    [$method, $template] = explode(' ', $op, 2);
    $regex = '#^' . implode('[^/]+', array_map(static fn ($p) => preg_quote($p, '#'), preg_split('/\{[^}]+\}/', $template))) . '$#';
    $t = new FakeTransport([['status' => 200, 'body' => '{}']]);
    $call(new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]));
    $path = (string) parse_url($t->calls[0]['url'] ?? '', PHP_URL_PATH);
    if (count($t->calls) !== 1 || $t->calls[0]['method'] !== $method || preg_match($regex, $path) !== 1) {
        $mismatched[] = $op . ' → ' . ($t->calls[0]['method'] ?? '?') . ' ' . $path;
    }
}
$assert($mismatched === [], count($table) . ' metot doğru yöntem ve yola gider' . ($mismatched === [] ? '' : ': ' . implode('; ', $mismatched)));

echo "Yeni kaynaklar: istek şekilleri" . PHP_EOL;
$t = new FakeTransport(array_fill(0, 20, ['status' => 200, 'body' => '{"id":"x"}']));
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->segments->estimate('seg/1', true);
$assert($t->calls[0]['method'] === 'POST' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_1/segments/seg%2F1/estimate?force=true', 'segments.estimate force=true');
$hs->segments->estimate('seg_1');
$assert(str_ends_with($t->calls[1]['url'], '?force=false'), 'segments.estimate varsayılan force=false');
$hs->templates->copyToApp('tpl_1', ['target_app_id' => 'app_2']);
$assert($t->calls[2]['body'] === '{"target_app_id":"app_2"}', 'templates.copyToApp gövdesi');
$hs->templates->render('tpl_1');
$assert($t->calls[3]['body'] === '{}', 'templates.render boş gövde JSON nesnesi');
$hs->campaigns->resendFailed('cmp_1');
$assert($t->calls[4]['url'] === 'https://api.test/v1/apps/app_1/campaigns/cmp_1/resend-failed' && $t->calls[4]['body'] === null, 'campaigns.resendFailed gövdesiz POST');
$hs->notifications->estimate(['target' => ['included_segments' => ['seg_1']]], 'app_7');
$assert($t->calls[5]['url'] === 'https://api.test/v1/notifications/estimate' && json_decode((string) $t->calls[5]['body'], true)['app_id'] === 'app_7', 'notifications.estimate app_id gövdede');
$hs->notifications->exportEvents('msg_1');
$assert($t->calls[6]['method'] === 'POST' && $t->calls[6]['url'] === 'https://api.test/v1/notifications/msg_1/export_events?app_id=app_1', 'notifications.exportEvents');
$hs->users->upsert(['external_id' => 'u1']);
$assert(preg_match('/^[0-9a-f-]{36}$/', $t->calls[7]['headers']['Idempotency-Key'] ?? '') === 1, 'users.upsert otomatik Idempotency-Key');
$hs->users->upsert(['external_id' => 'u1'], null, 'signup-u1');
$assert(($t->calls[8]['headers']['Idempotency-Key'] ?? null) === 'signup-u1', 'users.upsert verilen Idempotency-Key');
$hs->subscriptions->updateByToken('ChromePush', 'https://push.example/a?b=c', ['status' => 'unsubscribed']);
$assert($t->calls[9]['method'] === 'PATCH' && $t->calls[9]['url'] === 'https://api.test/v1/apps/app_1/subscriptions_by_token/ChromePush/https%3A%2F%2Fpush.example%2Fa%3Fb%3Dc', 'subscriptions.updateByToken token kodlanır');
$hs->inbox->list('external_id', 'u1', ['include_archived' => true, 'limit' => 20]);
$assert($t->calls[10]['url'] === 'https://api.test/v1/apps/app_1/users/by/external_id/u1/inbox?include_archived=true&limit=20', 'inbox.list boolean sorgu true/false olur');
$hs->inAppMessages->setStatus('iam_1', 'paused');
$assert($t->calls[11]['body'] === '{"status":"paused"}', 'inAppMessages.setStatus gövdesi');
$hs->events->updateDefinition('sepete ekle', ['is_conversion' => true]);
$assert($t->calls[12]['method'] === 'PATCH' && $t->calls[12]['url'] === 'https://api.test/v1/apps/app_1/events/definitions/sepete%20ekle', 'events.updateDefinition yolu');
$hs->analytics->messages(['from' => '2026-10-01T00:00:00Z', 'compare' => true, 'group_by' => 'channel']);
$assert($t->calls[13]['url'] === 'https://api.test/v1/apps/app_1/analytics/messages?from=2026-10-01T00%3A00%3A00Z&compare=true&group_by=channel', 'analytics.messages sorgusu');
$hs->organization->auditLogs(['app_id' => 'app_1', 'action' => 'api_key.rotate']);
$assert($t->calls[14]['url'] === 'https://api.test/v1/organization/audit-logs?app_id=app_1&action=api_key.rotate', 'organization.auditLogs filtreleri');

echo "v2 kaynakları: istek şekilleri" . PHP_EOL;
$t = new FakeTransport(array_fill(0, 10, ['status' => 200, 'body' => '{"id":"x"}']));
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->contentTables->create(['name' => 'urunler', 'key_column' => 'sku', 'description' => 'Ürün kataloğu'], "sku,ad\nA1,Kalem\n", 'urunler.csv');
$ct = $t->calls[0]['headers']['Content-Type'] ?? '';
$body = (string) $t->calls[0]['body'];
$assert($t->calls[0]['method'] === 'POST' && str_starts_with($ct, 'multipart/form-data; boundary=')
    && str_contains($body, "name=\"name\"\r\n\r\nurunler\r\n") && str_contains($body, "name=\"key_column\"\r\n\r\nsku\r\n")
    && str_contains($body, "name=\"description\"\r\n\r\nÜrün kataloğu\r\n") && str_contains($body, 'filename="urunler.csv"'), 'contentTables.create multipart alanları');
$hs->contentTables->replaceRows('ct/1', "sku\nA2\n");
$body = (string) $t->calls[1]['body'];
$assert($t->calls[1]['method'] === 'PUT' && $t->calls[1]['url'] === 'https://api.test/v1/apps/app_1/content-tables/ct%2F1/rows'
    && !str_contains($body, 'name="key_column"') && str_contains($body, 'name="file"'), 'contentTables.replaceRows anahtar sütunu isteğe bağlı');
$hs->contentTables->rows('ct_1', ['limit' => 10, 'offset' => 20, 'q' => 'A1']);
$assert($t->calls[2]['url'] === 'https://api.test/v1/apps/app_1/content-tables/ct_1/rows?limit=10&offset=20&q=A1', 'contentTables.rows sorgusu');
$hs->dataFeeds->test('df_1');
$assert($t->calls[3]['body'] === '{}', 'dataFeeds.test boş gövde JSON nesnesi');
$hs->conversations->list(['channel' => 'whatsapp', 'unread' => true]);
$assert($t->calls[4]['url'] === 'https://api.test/v1/apps/app_1/conversations?channel=whatsapp&unread=true', 'conversations.list boolean filtre');
$hs->conversations->markRead('cnv_1', 'app_9');
$assert($t->calls[5]['method'] === 'POST' && $t->calls[5]['url'] === 'https://api.test/v1/apps/app_9/conversations/cnv_1/read' && $t->calls[5]['body'] === null, 'conversations.markRead gövdesiz POST');
$hs->emailDeliverability->verifyAddress('ayşe@pushizo.com');
$assert($t->calls[6]['body'] === '{"address":"ayşe@pushizo.com"}', 'emailDeliverability.verifyAddress gövdesi (UTF-8)');
$hs->zapier->samples(['event' => 'event.received', 'event_name' => 'purchase']);
$assert($t->calls[7]['url'] === 'https://api.test/v1/apps/app_1/integrations/zapier/samples?event=event.received&event_name=purchase', 'zapier.samples sorgusu');
$hs->warehouseExports->run('wex_1');
$assert($t->calls[8]['method'] === 'POST' && $t->calls[8]['url'] === 'https://api.test/v1/apps/app_1/warehouse-exports/wex_1/run' && $t->calls[8]['body'] === null, 'warehouseExports.run gövdesiz POST');

echo "v3 kaynakları: istek şekilleri" . PHP_EOL;
$t = new FakeTransport(array_merge(
    array_fill(0, 8, ['status' => 200, 'body' => '{"id":"x"}']),
    [
        ['status' => 200, 'body' => '{"data":[{"id":"frs_1"},{"id":"frs_2"}],"next_cursor":"c2","has_more":true}'],
        ['status' => 200, 'body' => '{"data":[{"id":"frs_3"}],"next_cursor":null,"has_more":false}'],
    ],
    array_fill(0, 4, ['status' => 200, 'body' => '{"id":"x"}']),
));
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->conversations->update('cnv/1', ['status' => 'pending'], 'app_2');
$assert($t->calls[0]['method'] === 'PATCH' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_2/conversations/cnv%2F1' && $t->calls[0]['body'] === '{"status":"pending"}', 'conversations.update PATCH gövdesi');
$hs->conversations->assign('cnv_1', ['assignee_id' => null, 'team_id' => 'team_1']);
$assert($t->calls[1]['body'] === '{"assignee_id":null,"team_id":"team_1"}', 'conversations.assign null atamayı kaldırır');
$hs->inbox->metrics(['from' => '2026-10-01T00:00:00Z', 'channel' => 'chat']);
$assert($t->calls[2]['url'] === 'https://api.test/v1/apps/app_1/inbox/metrics?from=2026-10-01T00%3A00%3A00Z&channel=chat', 'inbox.metrics sorgusu');
$hs->inbox->updateSettings(['widget' => ['enabled' => true, 'position' => 'left']]);
$assert($t->calls[3]['method'] === 'PUT' && $t->calls[3]['body'] === '{"widget":{"enabled":true,"position":"left"}}', 'inbox.updateSettings PUT gövdesi');
$hs->ads->spendReport(['from' => '2026-10-01', 'to' => '2026-10-07', 'currency' => 'TRY', 'group_by' => 'campaign']);
$assert($t->calls[4]['url'] === 'https://api.test/v1/apps/app_1/ads/reports/spend?from=2026-10-01&to=2026-10-07&currency=TRY&group_by=campaign', 'ads.spendReport sorgusu');
$hs->ads->refreshAccount('ada_1');
$assert($t->calls[5]['method'] === 'POST' && $t->calls[5]['url'] === 'https://api.test/v1/apps/app_1/ads/accounts/ada_1/refresh' && $t->calls[5]['body'] === null, 'ads.refreshAccount gövdesiz POST');
$hs->webCampaigns->setStatus('wcm_1', 'paused');
$assert($t->calls[6]['url'] === 'https://api.test/v1/apps/app_1/web-campaigns/wcm_1/status' && $t->calls[6]['body'] === '{"status":"paused"}', 'webCampaigns.setStatus gövdesi');
$hs->forms->submit('frm_1', ['values' => ['email' => 'ayşe@pushizo.com', 'consent' => true], 'external_id' => 'u1']);
$assert($t->calls[7]['body'] === '{"values":{"email":"ayşe@pushizo.com","consent":true},"external_id":"u1"}', 'forms.submit gövdesi (UTF-8)');
$subIds = [];
foreach ($hs->paginate(fn (?string $c) => $hs->forms->listSubmissions('frm_1', ['cursor' => $c, 'limit' => 2])) as $sub) {
    $subIds[] = $sub['id'];
}
$assert($subIds === ['frs_1', 'frs_2', 'frs_3'] && str_contains($t->calls[9]['url'], 'cursor=c2'), 'forms.listSubmissions paginate ile dolaşılır');
$hs->surveys->respond('srv_1', ['answers' => ['q1' => 10, 'q2' => 'Harika']]);
$assert($t->calls[10]['url'] === 'https://api.test/v1/apps/app_1/surveys/srv_1/responses' && $t->calls[10]['body'] === '{"answers":{"q1":10,"q2":"Harika"}}', 'surveys.respond gövdesi');
$hs->surveys->listResponses('srv_1', ['nps_category' => 'promoter', 'text_question' => 'q2']);
$assert($t->calls[11]['url'] === 'https://api.test/v1/apps/app_1/surveys/srv_1/responses?nps_category=promoter&text_question=q2', 'surveys.listResponses filtreleri');
$hs->landingPages->unpublish('lp/1', 'app_3');
$assert($t->calls[12]['method'] === 'POST' && $t->calls[12]['url'] === 'https://api.test/v1/apps/app_3/landing-pages/lp%2F1/unpublish' && $t->calls[12]['body'] === null, 'landingPages.unpublish gövdesiz POST');

echo "Journey'ler: istek şekilleri" . PHP_EOL;
$t = new FakeTransport(array_fill(0, 12, ['status' => 200, 'body' => '{"id":"jrn_1"}']));
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$graph = [
    'nodes' => [
        ['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => ['kind' => 'api']]],
        ['id' => 'n2', 'type' => 'exit', 'position' => ['x' => 0, 'y' => 120], 'data' => ['label' => 'Bitiş']],
    ],
    'edges' => [['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'sourceHandle' => 'next']],
];
$hs->journeys->saveDraft('jrn/1', ['graph' => $graph], 'app_9');
$assert($t->calls[0]['method'] === 'PUT' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_9/journeys/jrn%2F1/draft' && json_decode((string) $t->calls[0]['body'], true) === ['graph' => $graph], 'journeys.saveDraft PUT gövdesi ve app_id');
$hs->journeys->updateNode('jrn_1', 'n/2', ['data' => ['label' => 'Son']]);
$assert($t->calls[1]['method'] === 'PATCH' && $t->calls[1]['url'] === 'https://api.test/v1/apps/app_1/journeys/jrn_1/nodes/n%2F2', 'journeys.updateNode düğüm kimliği kodlanır');
$hs->journeys->publish('jrn_1');
$assert($t->calls[2]['method'] === 'POST' && $t->calls[2]['url'] === 'https://api.test/v1/apps/app_1/journeys/jrn_1/publish' && $t->calls[2]['body'] === null, 'journeys.publish gövdesiz POST');
$hs->journeys->triggerRun('jrn_1', ['alias' => ['label' => 'crm', 'value' => 'c1'], 'context' => ['order_id' => 'o1']]);
$assert($t->calls[3]['body'] === '{"alias":{"label":"crm","value":"c1"},"context":{"order_id":"o1"}}', 'journeys.triggerRun alias + context gövdesi');
$hs->journeys->listRuns('jrn_1', ['status' => 'waiting', 'version' => 2, 'limit' => 10]);
$assert($t->calls[4]['url'] === 'https://api.test/v1/apps/app_1/journeys/jrn_1/runs?status=waiting&version=2&limit=10', 'journeys.listRuns filtreleri');
$hs->journeys->stats('jrn_1', ['version' => 3]);
$assert($t->calls[5]['url'] === 'https://api.test/v1/apps/app_1/journeys/jrn_1/stats?version=3', 'journeys.stats sürüm sorgusu');
$hs->journeys->exitRun('jrn_1', 'run_1');
$assert($t->calls[6]['method'] === 'DELETE' && $t->calls[6]['url'] === 'https://api.test/v1/apps/app_1/journeys/jrn_1/runs/run_1', 'journeys.exitRun DELETE');
$hs->journeys->listForUser('external_id', 'u 1');
$assert($t->calls[7]['url'] === 'https://api.test/v1/apps/app_1/users/by/external_id/u%201/journeys', 'journeys.listForUser alias yolu');

$t = new FakeTransport([['status' => 200, 'body' => "date,sent\n2026-10-01,5\n", 'headers' => ['content-type' => 'text/csv; charset=utf-8']]]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$csv = $hs->analytics->events(['format' => 'csv']);
$assert($csv === "date,sent\n2026-10-01,5\n" && str_contains($t->calls[0]['url'], 'format=csv'), 'analytics format=csv metin döner');

echo "Parite kaynakları: istek şekilleri" . PHP_EOL;
$t = new FakeTransport(array_fill(0, 20, ['status' => 200, 'body' => '{"id":"x"}']));
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$hs->siteSearch->putDocument('sss/kargo', ['url' => 'https://www.example.com/kargo', 'content' => 'Kargo ücretsiz.']);
$assert($t->calls[0]['method'] === 'PUT' && $t->calls[0]['url'] === 'https://api.test/v1/apps/app_1/site-search/documents/sss%2Fkargo' && $t->calls[0]['body'] === '{"url":"https://www.example.com/kargo","content":"Kargo ücretsiz."}', 'siteSearch.putDocument PUT gövdesi ve kodlanmış anahtar');
$hs->siteSearch->listDocuments(['source_id' => 'api', 'limit' => 5]);
$assert($t->calls[1]['url'] === 'https://api.test/v1/apps/app_1/site-search/documents?source_id=api&limit=5', 'siteSearch.listDocuments sorgu parametreleri');
$hs->companies->setMember('cpy_1', 'usr_1', ['role' => 'billing']);
$assert($t->calls[2]['method'] === 'PUT' && $t->calls[2]['url'] === 'https://api.test/v1/apps/app_1/companies/cpy_1/members/usr_1' && $t->calls[2]['body'] === '{"role":"billing"}', 'companies.setMember rol gövdesi');
$hs->companies->setMember('cpy_1', 'usr_1');
$assert($t->calls[3]['body'] === '{}', 'companies.setMember boş gövde JSON nesnesi');
$hs->companies->forUserBy('external_id', 'ayşe/1');
$assert($t->calls[4]['url'] === 'https://api.test/v1/apps/app_1/users/by/external_id/ay%C5%9Fe%2F1/companies', 'companies.forUserBy kodlanmış değer');
$hs->sqlSegments->query(['query' => 'select user_id from users', 'row_limit' => 10]);
$assert($t->calls[5]['method'] === 'POST' && $t->calls[5]['url'] === 'https://api.test/v1/apps/app_1/sql/query' && $t->calls[5]['body'] === '{"query":"select user_id from users","row_limit":10}', 'sqlSegments.query gövdesi');
$hs->sqlSegments->refreshSegment('seg_1');
$assert($t->calls[6]['url'] === 'https://api.test/v1/apps/app_1/sql/segments/seg_1/refresh' && $t->calls[6]['body'] === null, 'sqlSegments.refreshSegment gövdesiz POST');
$hs->dataPipelines->reprocessQuarantine(['ids' => ['dqr_1', 'dqr_2']]);
$assert($t->calls[7]['url'] === 'https://api.test/v1/apps/app_1/data-pipelines/quarantine/reprocess' && $t->calls[7]['body'] === '{"ids":["dqr_1","dqr_2"]}', 'dataPipelines.reprocessQuarantine ids gövdesi');
$hs->dataPipelines->reprocessQuarantine();
$assert($t->calls[8]['body'] === '{}', 'dataPipelines.reprocessQuarantine kimliksiz boş JSON nesnesi');
$hs->dataPipelines->discardQuarantine(['ids' => ['dqr_1']]);
$assert($t->calls[9]['url'] === 'https://api.test/v1/apps/app_1/data-pipelines/quarantine/discard' && $t->calls[9]['body'] === '{"ids":["dqr_1"]}', 'dataPipelines.discardQuarantine gövdesi');
$hs->analytics->androidDelivery(['from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => true]);
$assert($t->calls[10]['method'] === 'GET' && $t->calls[10]['url'] === 'https://api.test/v1/apps/app_1/analytics/android-delivery?from=2026-10-01&to=2026-10-31&compare=true', 'analytics.androidDelivery sorgu parametreleri');
$hs->campaigns->preflight('cmp_1', null, true);
$assert($t->calls[11]['method'] === 'POST' && $t->calls[11]['url'] === 'https://api.test/v1/apps/app_1/campaigns/cmp_1/preflight?check_links=true' && $t->calls[11]['body'] === null, 'campaigns.preflight check_links=true');
$hs->campaigns->preflight('cmp_1');
$assert($t->calls[12]['url'] === 'https://api.test/v1/apps/app_1/campaigns/cmp_1/preflight', 'campaigns.preflight varsayılan sorgusuz');
$hs->webPersonalizations->setStatus('wpz_1', 'active', 'app_9');
$assert($t->calls[13]['url'] === 'https://api.test/v1/apps/app_9/web-personalizations/wpz_1/status' && $t->calls[13]['body'] === '{"status":"active"}', 'webPersonalizations.setStatus gövdesi ve app geçersiz kılma');
$hs->channels->createMessengerLink(['channel' => 'telegram', 'external_id' => 'musteri-42']);
$assert($t->calls[14]['url'] === 'https://api.test/v1/apps/app_1/messenger-links' && $t->calls[14]['body'] === '{"channel":"telegram","external_id":"musteri-42"}', 'channels.createMessengerLink gövdesi');
$hs->warehouseSegments->testConnection('whc_1');
$assert($t->calls[15]['method'] === 'POST' && $t->calls[15]['url'] === 'https://api.test/v1/apps/app_1/warehouse-segments/connections/whc_1/test' && $t->calls[15]['body'] === null, 'warehouseSegments.testConnection gövdesiz POST');
$t = new FakeTransport([['status' => 200, 'body' => "date,uninstalls\n2026-10-01,3\n"]]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$csv = $hs->analytics->uninstalls(['format' => 'csv']);
$assert($csv === "date,uninstalls\n2026-10-01,3\n" && str_ends_with($t->calls[0]['url'], '/analytics/uninstalls?format=csv'), 'analytics.uninstalls format=csv metin döner');


echo "Import (multipart) ve yeniden deneme" . PHP_EOL;
$t = new FakeTransport([
    ['status' => 429, 'body' => '{"error":{"code":"HS_RATE_LIMITED","message":"meşgul"}}'],
    ['status' => 200, 'body' => '{"id":"imp_1","status":"pending"}'],
]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$import = $hs->imports->create('csv_users', "external_id,email\nu1,a@pushizo.com\n", 'kullanicilar.csv');
$assert($import['id'] === 'imp_1' && count($t->calls) === 2, '429 sonrası yeniden denendi');
$ct = $t->calls[1]['headers']['Content-Type'] ?? '';
$assert(preg_match('/^multipart\/form-data; boundary=(\S+)$/', $ct, $bm) === 1, 'multipart Content-Type ve boundary');
$mp = (string) $t->calls[1]['body'];
$assert($t->calls[0]['body'] === $mp, 'yeniden denemede aynı gövde');
$assert(str_contains($mp, "Content-Disposition: form-data; name=\"type\"\r\n\r\ncsv_users\r\n"), 'type alanı');
$assert(str_contains($mp, "name=\"file\"; filename=\"kullanicilar.csv\"\r\nContent-Type: text/csv\r\n\r\nexternal_id,email\nu1,a@pushizo.com\n\r\n"), 'file alanı');
$assert(str_ends_with($mp, '--' . ($bm[1] ?? '') . "--\r\n"), 'kapanış sınırı');

echo "paginate" . PHP_EOL;
$t = new FakeTransport([
    ['status' => 200, 'body' => '{"data":[{"id":"tpl_1"},{"id":"tpl_2"}],"next_cursor":"c2","has_more":true}'],
    ['status' => 200, 'body' => '{"data":[{"id":"tpl_3"}],"next_cursor":null,"has_more":false}'],
]);
$hs = new Client(['api_key' => 'k', 'base_url' => 'https://api.test', 'app_id' => 'app_1', 'transport' => $t, 'sleep' => $noSleep]);
$ids = [];
foreach ($hs->paginate(fn (?string $c) => $hs->templates->list(['cursor' => $c, 'limit' => 2])) as $tpl) {
    $ids[] = $tpl['id'];
}
$assert($ids === ['tpl_1', 'tpl_2', 'tpl_3'] && str_contains($t->calls[1]['url'], 'cursor=c2'), 'tüm sayfalar dolaşıldı');

echo PHP_EOL . ($failures === 0 ? 'Tüm testler geçti.' : "$failures test başarısız.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
