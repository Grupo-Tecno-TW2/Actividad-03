<?php
declare(strict_types=1);

namespace Apicultores\Seguridad;

use Apicultores\Excepciones\Prohibido;

final class Csrf
{
    public const CAMPO = '_csrf';

    public static function token(): string
    {
        Sesion::iniciar();

        if (
            !isset($_SESSION[self::CAMPO]) ||
            !is_string($_SESSION[self::CAMPO]) ||
            strlen($_SESSION[self::CAMPO]) !== 64
        ) {
            $_SESSION[self::CAMPO] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CAMPO];
    }

    public static function validar(?string $token): void
    {
        Sesion::iniciar();

        $esperado = $_SESSION[self::CAMPO] ?? null;

        if (
            !is_string($esperado) ||
            strlen($esperado) !== 64 ||
            !is_string($token) ||
            !hash_equals($esperado, $token)
        ) {
            throw new Prohibido('Token CSRF inválido o ausente.');
        }
    }
}
