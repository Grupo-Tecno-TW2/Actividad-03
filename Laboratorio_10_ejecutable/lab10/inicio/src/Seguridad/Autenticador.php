<?php
// src/Seguridad/Autenticador.php — Verifica correo y contraseña con password_verify y limita los intentos.
declare(strict_types=1);

namespace Apicultores\Seguridad;

use Apicultores\Excepciones\DemasiadosIntentos;
use Apicultores\Modelo\Rol;
use Apicultores\Modelo\Usuario;
use Apicultores\Repositorios\UsuarioRepositorio;

final class Autenticador
{
    public const MAXIMO_INTENTOS = 5;
    public const BLOQUEO_SEGUNDOS = 900;                        // 15 minutos
    public const OPCIONES_HASH = ['cost' => 12];
    private const HASH_FICTICIO = '$2y$12$pPcTmVFOsiW4JHoTFr/Z8O4DWj4Nj3S9DisPdBl77Zpu7VMMM5nbO';

    public function __construct(private readonly UsuarioRepositorio $usuarios)
    {
    }

    public function intentar(string $correo, string $clave): ?Usuario
    {
        $fila = $this->usuarios->porCorreo($correo);
        if ($fila === null) {
            password_verify($clave, self::HASH_FICTICIO);      
            return null;
        }

        if (isset($fila['segundos_bloqueo']) && (int) $fila['segundos_bloqueo'] > 0) {
            throw new DemasiadosIntentos((int) $fila['segundos_bloqueo']);
        }

        if (!password_verify($clave, $fila['clave_hash'])) {
            $this->usuarios->registrarFallo((int) $fila['id'], self::MAXIMO_INTENTOS, self::BLOQUEO_SEGUNDOS);
            return null;
        }

        $this->usuarios->registrarExito((int) $fila['id']);

        if (password_needs_rehash($fila['clave_hash'], PASSWORD_DEFAULT, self::OPCIONES_HASH)) {
            $nuevoHash = password_hash($clave, PASSWORD_DEFAULT, self::OPCIONES_HASH);
            $this->usuarios->actualizarHash((int) $fila['id'], $nuevoHash);
        }

        $socioId = null;
        if (isset($fila['socio_id']) && $fila['socio_id'] !== null && $fila['socio_id'] !== '') {
            $socioId = (int) $fila['socio_id'];
        }

        return new Usuario(
            id: (int) $fila['id'],
            correo: (string) $fila['correo'],
            nombre: (string) $fila['nombre'],
            rol: Rol::from((string) $fila['rol']),
            socioId: $socioId
        );
    }
}