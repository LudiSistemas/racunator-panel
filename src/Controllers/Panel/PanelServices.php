<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Services\KeyGenerator;
use RacunatorPanel\Services\QrPayload;

/**
 * Tanak omotač oko servisa jezgra (lozinke, TOTP, ključevi, QR sadržaj) da bi kontroleri panela bili nezavisni od načina
 * na koji su ti servisi napravljeni i da bi testovi mogli da podmetnu predvidive zamene (nasleđivanjem ove klase).
 */
class PanelServices
{
    private ?PasswordHasher $hasher = null;
    private ?Totp $totp = null;
    private ?KeyGenerator $keys = null;
    private ?QrPayload $qr = null;

    public function hashPassword(string $password): string
    {
        return $this->hasher()->hash($password);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return $this->hasher()->verify($password, $hash);
    }

    public function totpSecret(): string
    {
        return $this->totp()->generateSecret();
    }

    public function totpUri(string $secret, string $label, string $issuer): string
    {
        return $this->totp()->uri($secret, $label, $issuer);
    }

    public function totpVerify(string $secret, string $code): bool
    {
        return $this->totp()->verify($secret, $code);
    }

    /** Nasumičan slug (32 hex znaka). */
    public function slug(): string
    {
        return $this->keys()->slug();
    }

    /** Nov API ključ u čitljivom obliku (prikazuje se samo jednom). */
    public function apiKey(): string
    {
        return $this->keys()->apiKey();
    }

    public function keyPrefix(string $key): string
    {
        return $this->keys()->prefix($key);
    }

    public function keyHash(string $key): string
    {
        return $this->keys()->hash($key);
    }

    /** JSON sadržaj QR koda za uparivanje aplikacije. */
    public function qrPayload(Client $client, string $plainKey, string $publicUrl, string $accountantName): string
    {
        return $this->qr()->build($client, $plainKey, $publicUrl, $accountantName);
    }

    private function hasher(): PasswordHasher
    {
        return $this->hasher ??= new PasswordHasher();
    }

    private function totp(): Totp
    {
        return $this->totp ??= new Totp();
    }

    private function keys(): KeyGenerator
    {
        return $this->keys ??= new KeyGenerator();
    }

    private function qr(): QrPayload
    {
        return $this->qr ??= new QrPayload();
    }
}
