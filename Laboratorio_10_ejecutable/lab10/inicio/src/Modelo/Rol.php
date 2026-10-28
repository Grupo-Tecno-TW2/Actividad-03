<?php
// src/Modelo/Rol.php — Roles y matriz de permisos centralizada.
declare(strict_types=1);

namespace Apicultores\Modelo;

enum Rol: string
{
    case Admin = 'admin';
    case Operador = 'operador';
    case Socio = 'socio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operador => 'Operador',
            self::Socio => 'Socio',
        };
    }

    public function puede(string $accion): bool
    {
        return match ($this) {
            self::Admin => in_array($accion, [
                'productos.ver', 'productos.crear', 'productos.editar', 'productos.eliminar',
                'socios.ver', 'socios.crear', 'socios.editar', 'socios.eliminar'
            ], true),
            self::Operador => in_array($accion, [
                'productos.ver', 'productos.crear', 'productos.editar',
                'socios.ver'
            ], true),
            self::Socio => in_array($accion, [
                'productos.ver'
            ], true),
        };
    }
}