<?php

declare(strict_types=1);

/**
 * Read / write Abundomy peer profile payloads (same format as Flutter
 * PeerProfileCodec + PeerProfile.toWireJson).
 *
 * Read:
 *   $p = AbundomyPeerProfile::fromQrPayload($code);
 *
 * Create:
 *   $p = AbundomyPeerProfile::create(fullName: 'Ada', publicEmail: 'a@b.com', ...);
 *   $qr = $p->toQrPayload();
 *
 * Try sample from CLI:
 *   php AbundomyPeerProfile.php --example
 */
final class AbundomyPeerProfile
{
    public const QR_PREFIX = 'ABUNDOMY.P1.';

    public const WIRE_KIND = 'abundomy.peer_profile';

    public const WIRE_VERSION = 1;

    public string $fullName;

    public string $height;

    public string $hair;

    public string $eyeLeft;

    public string $eyeRight;

    public string $specialFeatures;

    public ?string $birthdayIso;

    public ?string $gender;

    public ?string $displayName;

    public ?string $publicEmail;

    /** Raw image bytes for photo_b64 when encoding, or decoded bytes when reading */
    public ?string $photoBytes;

    public function __construct(
        string $fullName,
        string $height,
        string $hair,
        string $eyeLeft,
        string $eyeRight,
        string $specialFeatures,
        ?string $birthdayIso,
        ?string $gender,
        ?string $displayName,
        ?string $publicEmail,
        ?string $photoBytes,
    ) {
        $this->fullName = $fullName;
        $this->height = $height;
        $this->hair = $hair;
        $this->eyeLeft = $eyeLeft;
        $this->eyeRight = $eyeRight;
        $this->specialFeatures = $specialFeatures;
        $this->birthdayIso = $birthdayIso;
        $this->gender = $gender;
        $this->displayName = $displayName;
        $this->publicEmail = $publicEmail;
        $this->photoBytes = $photoBytes;
    }

    /**
     * Build a profile in memory (same fields as the Flutter wire model).
     * Omit optional keys or pass null where not applicable.
     */
    public static function create(
        string $fullName = '',
        string $height = '',
        string $hair = '',
        string $eyeLeft = '',
        string $eyeRight = '',
        string $specialFeatures = '',
        ?string $birthdayIso = null,
        ?string $gender = null,
        ?string $displayName = null,
        ?string $publicEmail = null,
        ?string $photoBytes = null,
    ): self {
        return new self(
            $fullName,
            $height,
            $hair,
            $eyeLeft,
            $eyeRight,
            $specialFeatures,
            $birthdayIso,
            $gender,
            $displayName,
            $publicEmail,
            $photoBytes,
        );
    }

    /**
     * Encode this profile as a single QR / clipboard string (matches Flutter
     * PeerProfileCodec.encode): zlib(JSON wire) then base64url without padding.
     *
     * @param int $compressionLevel zlib level -1 (default zlib), 0–9, same as PHP gzcompress()
     *
     * @throws RuntimeException if zlib is unavailable or compression fails
     */
    public function toQrPayload(int $compressionLevel = -1): string
    {
        $wire = $this->toArray();
        try {
            $json = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Wire JSON encode failed: ' . $e->getMessage(), 0, $e);
        }
        $level = $compressionLevel < 0
            ? -1
            : max(0, min(9, $compressionLevel));
        $zlib = @gzcompress($json, $level);
        if ($zlib === false) {
            throw new RuntimeException('zlib compress failed (enable zlib extension)');
        }

        return self::QR_PREFIX . self::base64UrlEncodeNoPad($zlib);
    }

    /**
     * Decode a full QR / clipboard string (prefix + base64url(zlib(json))).
     */
    public static function fromQrPayload(string $raw): ?self
    {
        $raw = trim($raw);
        if (!str_starts_with($raw, self::QR_PREFIX)) {
            return null;
        }
        $b64 = substr($raw, strlen(self::QR_PREFIX));
        $b64 = preg_replace('/\s+/', '', $b64) ?? '';
        $binary = self::base64UrlDecodePadded($b64);
        if ($binary === '') {
            return null;
        }
        $json = @gzuncompress($binary);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return self::fromWireArray($data);
    }

    /**
     * Map an already-decoded wire JSON object (e.g. from DB or API).
     */
    public static function fromWireArray(array $m): ?self
    {
        if (($m['kind'] ?? null) !== self::WIRE_KIND) {
            return null;
        }
        if (($m['v'] ?? null) !== self::WIRE_VERSION) {
            return null;
        }

        $photoBytes = null;
        $photoB64 = $m['photo_b64'] ?? null;
        if (is_string($photoB64) && $photoB64 !== '') {
            $decoded = base64_decode($photoB64, true);
            if ($decoded !== false) {
                $photoBytes = $decoded;
            }
        }

        return new self(
            self::str($m, 'full_name'),
            self::str($m, 'height'),
            self::str($m, 'hair'),
            self::str($m, 'eye_left'),
            self::str($m, 'eye_right'),
            self::str($m, 'special_features'),
            self::nullableString($m, 'birthday_iso'),
            self::nullableString($m, 'gender'),
            self::nullableString($m, 'display_name'),
            self::nullableString($m, 'public_email'),
            $photoBytes,
        );
    }

    /** Flat array for DB insert / JSON API (photo as base64 again). */
    public function toArray(): array
    {
        return [
            'kind' => self::WIRE_KIND,
            'v' => self::WIRE_VERSION,
            'full_name' => $this->fullName,
            'height' => $this->height,
            'hair' => $this->hair,
            'eye_left' => $this->eyeLeft,
            'eye_right' => $this->eyeRight,
            'special_features' => $this->specialFeatures,
            'birthday_iso' => $this->birthdayIso,
            'gender' => $this->gender,
            'display_name' => $this->displayName,
            'public_email' => $this->publicEmail,
            'photo_b64' => $this->photoBytes !== null
                ? base64_encode($this->photoBytes)
                : null,
        ];
    }

    private static function str(array $m, string $key): string
    {
        $v = $m[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    private static function nullableString(array $m, string $key): ?string
    {
        $v = $m[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return is_string($v) ? $v : null;
    }

    private static function base64UrlDecodePadded(string $input): string
    {
        $input = strtr($input, '-_', '+/');
        $pad = strlen($input) % 4;
        if ($pad > 0) {
            $input .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($input, true);

        return $out === false ? '' : $out;
    }

    private static function base64UrlEncodeNoPad(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

/**
 * Sample: build a profile, emit a QR payload string, parse it back, print JSON.
 * Run: php AbundomyPeerProfile.php --example
 */
function abundomy_peer_profile_example(): void
{
    $tinyPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
    if ($tinyPng === false) {
        fwrite(STDERR, "sample base64 decode failed\n");
        exit(1);
    }

    $created = AbundomyPeerProfile::create(
        fullName: 'Ada Lovelace',
        height: '170 cm',
        hair: 'Brown',
        eyeLeft: 'Brown',
        eyeRight: 'Brown',
        specialFeatures: '',
        birthdayIso: '1815-12-10',
        gender: 'female',
        displayName: 'Ada',
        publicEmail: 'ada@example.com',
        photoBytes: $tinyPng,
    );

    $payload = $created->toQrPayload();
    echo "Encoded length: " . strlen($payload) . "\n";

    $roundTrip = AbundomyPeerProfile::fromQrPayload($payload);
    if ($roundTrip === null) {
        fwrite(STDERR, "round-trip decode failed\n");
        exit(1);
    }

    echo json_encode($roundTrip->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

if (PHP_SAPI === 'cli' && in_array('--example', $_SERVER['argv'] ?? [], true)) {
    abundomy_peer_profile_example();
}
