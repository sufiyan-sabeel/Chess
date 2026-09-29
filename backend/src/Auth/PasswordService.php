<?php

declare(strict_types=1);

namespace Checkmate\Auth;

/**
 * Password hashing (docs/API.md §1: PASSWORD_ARGON2ID with bcrypt fallback).
 *
 * This build of PHP ships without libargon2/sodium, so PASSWORD_ARGON2ID is
 * undefined here and PASSWORD_DEFAULT (bcrypt, cost 10) is used. The code
 * prefers Argon2id automatically wherever it exists — no data migration is
 * needed because password_verify() dispatches on the hash prefix, and a rehash
 * on next successful login is handled by needsRehash().
 */
final class PasswordService
{
    private static ?string $dummyHash = null;

    /**
     * Password hashing algorithm identifier.
     *
     * PHP 8.4+ uses string identifiers (`PASSWORD_DEFAULT === '2y'`, and
     * `'argon2id'` where libargon2 is compiled in); older builds used ints.
     * Both are accepted by password_hash()/password_needs_rehash(), so this
     * returns whatever the constant holds as a string.
     */
    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? (string) constant('PASSWORD_ARGON2ID')
            : (string) PASSWORD_DEFAULT;
    }

    public static function algorithmName(): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return 'argon2id';
        }
        // PASSWORD_DEFAULT is '2y'/'2x'/'2b' — the bcrypt family.
        return str_starts_with(self::algorithm(), '2') ? 'bcrypt' : (string) self::algorithm();
    }

    public static function hash(string $password): string
    {
        $hash = password_hash($password, self::algorithm());
        if ($hash === false) {
            throw new \RuntimeException('password_hash failed');
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        try {
            return password_verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return bool true when a stored hash uses a weaker/cheaper algorithm than current */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /**
     * A valid-looking hash of a random secret, verified against when the e-mail
     * is unknown so that "unknown e-mail" and "wrong password" cost the same
     * CPU time (anti-enumeration, docs/API.md §1 login).
     */
    public static function dummyHash(): string
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash(bin2hex(random_bytes(16)), self::algorithm());
        }
        return self::$dummyHash;
    }
}
