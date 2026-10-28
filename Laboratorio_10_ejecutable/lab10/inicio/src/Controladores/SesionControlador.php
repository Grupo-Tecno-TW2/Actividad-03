<?php
// src/Controladores/SesionControlador.php — Iniciar y cerrar sesión de forma segura.
declare(strict_types=1);

namespace Apicultores\Controladores;

use Apicultores\Excepciones\DemasiadosIntentos;
use Apicultores\Seguridad\Autenticador;
use Apicultores\Seguridad\Sesion;
use Apicultores\Vista\Plantilla;

final class SesionControlador
{
    public function __construct(private readonly Autenticador $autenticador, private readonly Plantilla $vista)
    {
    }

    public function formulario(string $error = '', string $correo = ''): string
    {
        return $this->vista->pagina('entrar', [
            'error' => $error, 
            'correo' => $correo, 
            'volver' => self::volver()
        ], 'Iniciar sesión');
    }

    public function entrar(): string
    {
        $correo = trim((string) ($_POST['correo'] ?? ''));
        
        try {
            $usuario = $this->autenticador->intentar($correo, (string) ($_POST['clave'] ?? ''));
        } catch (DemasiadosIntentos $e) {
            http_response_code(429);
            header('Retry-After: ' . $e->segundos());
            return $this->formulario('Demasiados intentos fallidos. Intente más tarde.', $correo);
        }
        
        if ($usuario === null) {
            http_response_code(401);
            // Mensaje idéntico para credenciales incorrectas y correos inexistentes
            return $this->formulario('Correo o contraseña incorrectos.', $correo);
        }

        Sesion::entrar($usuario);
        header('Location: ' . self::volver(), true, 303);
        exit;
    }

    public function salir(): never
    {
        Sesion::cerrar();
        header('Location: /entrar', true, 303);
        exit;
    }

    private static function volver(): string
    {
        $v = (string) ($_POST['volver'] ?? $_GET['volver'] ?? '/productos');
        
        // Bloquea redirecciones abiertas de forma estricta (evita //sitio-malo.com)
        if (!str_starts_with($v, '/') || str_starts_with($v, '//') || !preg_match('#^/(?!/)[\w\-/?=&%.]*$#', $v)) {
            return '/productos';
        }
        
        return $v;
    }
}