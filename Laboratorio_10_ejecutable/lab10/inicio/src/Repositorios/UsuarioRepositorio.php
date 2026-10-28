<?php
// src/Repositorios/UsuarioRepositorio.php
// Usuarios y control de intentos fallidos.
declare(strict_types=1);

namespace Apicultores\Repositorios;

use PDO;

final class UsuarioRepositorio
{
    public function __construct(private readonly PDO $bd)
    {
    }

    /** Fila completa: solo la utiliza el Autenticador. */
    public function porCorreo(string $correo): ?array
    {
        $correo = mb_strtolower(trim($correo));

        // Si el bloqueo ya venció, iniciar un nuevo ciclo de intentos.
        $reiniciar = $this->bd->prepare(
            'UPDATE usuarios
             SET intentos_fallidos = 0, bloqueado_hasta = NULL
             WHERE correo = ?
               AND bloqueado_hasta IS NOT NULL
               AND bloqueado_hasta <= NOW()'
        );
        $reiniciar->execute([$correo]);

        $consulta = $this->bd->prepare(
            'SELECT id, correo, nombre, clave_hash, rol, socio_id,
                    intentos_fallidos,
                    GREATEST(
                        0,
                        TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta)
                    ) AS segundos_bloqueo
             FROM usuarios
             WHERE correo = ?'
        );

        $consulta->execute([$correo]);

        return $consulta->fetch() ?: null;
    }

    public function registrarFallo(
        int $id,
        int $maximo,
        int $bloqueoSegundos
    ): void {
        $consulta = $this->bd->prepare(
            'UPDATE usuarios
             SET bloqueado_hasta = IF(
                     intentos_fallidos + 1 >= :maximo,
                     DATE_ADD(NOW(), INTERVAL :segundos SECOND),
                     bloqueado_hasta
                 ),
                 intentos_fallidos = intentos_fallidos + 1
             WHERE id = :id'
        );

        $consulta->execute([
            'maximo' => $maximo,
            'segundos' => $bloqueoSegundos,
            'id' => $id,
        ]);
    }

    public function registrarExito(int $id): void
    {
        $consulta = $this->bd->prepare(
            'UPDATE usuarios
             SET intentos_fallidos = 0,
                 bloqueado_hasta = NULL
             WHERE id = ?'
        );

        $consulta->execute([$id]);
    }

    public function actualizarHash(int $id, string $hash): void
    {
        $consulta = $this->bd->prepare(
            'UPDATE usuarios
             SET clave_hash = ?
             WHERE id = ?'
        );

        $consulta->execute([$hash, $id]);
    }
}
