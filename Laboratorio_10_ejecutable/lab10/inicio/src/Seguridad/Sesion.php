<?php
// src/Seguridad/Sesion.php — Sesión endurecida: cookie HttpOnly + SameSite, modo estricto y nuevo ID al iniciar sesión.
declare(strict_types=1);

namespace Apicultores\Seguridad;

use Apicultores\Modelo\Rol;
use Apicultores\Modelo\Usuario;

final class Sesion
{
    public const NOMBRE = 'APISESION';
    public const INACTIVIDAD = 1800; // 30 minutos sin actividad

    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && $_SERVER['HTTPS'] !== 'off';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name(self::NOMBRE);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        $ahora = time();
        $ultimaActividad = $_SESSION['ultima_actividad'] ?? null;

        if (
            is_numeric($ultimaActividad) &&
            ($ahora - (int) $ultimaActividad) > self::INACTIVIDAD
        ) {
            // La sesión caducó: eliminar los datos anteriores.
            $_SESSION = [];

            // Regenerar el identificador y descartar el anterior.
            session_regenerate_id(true);
        }

        // Registrar la actividad actual.
        $_SESSION['ultima_actividad'] = $ahora;
    }

    public static function entrar(Usuario $u): void
    {
        self::iniciar();

        // Evitar reutilizar el identificador previo al inicio de sesión.
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException(
                'No se pudo regenerar el identificador de sesión.'
            );
        }

        $_SESSION['usuario'] = [
            'id' => $u->id,
            'correo' => $u->correo,
            'nombre' => $u->nombre,
            'rol' => $u->rol->value,
            'socio_id' => $u->socioId,
        ];

        // El token anterior no debe mantenerse después del inicio de sesión.
        unset($_SESSION[Csrf::CAMPO]);

        $_SESSION['ultima_actividad'] = time();

        // Guardar la sesión antes de redirigir.
        session_write_close();
    }

    public static function usuario(): ?Usuario
    {
        self::iniciar();

        $d = $_SESSION['usuario'] ?? null;

        if (!is_array($d)) {
            return null;
        }

        try {
            return new Usuario(
                id: (int) $d['id'],
                correo: (string) $d['correo'],
                nombre: (string) $d['nombre'],
                rol: Rol::from((string) $d['rol']),
                socioId: isset($d['socio_id']) && $d['socio_id'] !== null
                    ? (int) $d['socio_id']
                    : null
            );
        } catch (\ValueError | \TypeError | \InvalidArgumentException) {
            // Una sesión con datos inválidos no debe autenticar a nadie.
            self::cerrar();
            return null;
        }
    }

    public static function cerrar(): void
    {
        self::iniciar();

        $_SESSION = [];

        $p = session_get_cookie_params();

        setcookie(self::NOMBRE, '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);

        session_destroy();
    }
}
