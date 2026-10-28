<?php
declare(strict_types=1);

namespace Apicultores\Reportes;

use DateTimeImmutable;
use PDO;

final class ReporteVentas
{
    public function __construct(private readonly PDO $bd)
    {
    }

    private function periodo(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        return ['desde' => $desde->format('Y-m-d 00:00:00'), 'hasta' => $hasta->modify('+1 day')->format('Y-m-d 00:00:00')];
    }

    public function porMes(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $c = $this->bd->prepare(
            'SELECT DATE_FORMAT(fecha, \'%Y-%m\') AS mes, COUNT(*) AS ventas, SUM(total) AS total
             FROM ventas
             WHERE fecha >= :desde AND fecha < :hasta
             GROUP BY mes
             ORDER BY mes ASC'
        );
        $c->execute($this->periodo($desde, $hasta));
        return $c->fetchAll();
    }

    public function topProductos(DateTimeImmutable $desde, DateTimeImmutable $hasta, int $limite = 5): array
    {
        $c = $this->bd->prepare(
            'SELECT p.codigo, p.nombre, SUM(d.cantidad) AS unidades, SUM(d.cantidad * d.precio_unitario) AS importe
             FROM venta_detalle d
             JOIN ventas v    ON v.id = d.venta_id
             JOIN productos p ON p.id = d.producto_id
             WHERE v.fecha >= :desde AND v.fecha < :hasta
             GROUP BY p.id, p.codigo, p.nombre
             ORDER BY importe DESC, p.codigo ASC
             LIMIT :limite'
        );
        
        // SOLUCIÓN AL HY093: Pasamos todos los parámetros juntos en el execute.
        // El límite debe ser forzosamente un entero.
        $parametros = $this->periodo($desde, $hasta);
        $parametros['limite'] = $limite;
        
        $c->execute($parametros);
        return $c->fetchAll();
    }

    public function porSocio(DateTimeImmutable $desde, DateTimeImmutable $hasta, float $minimo = 0): array
    {
        $c = $this->bd->prepare(
            'SELECT s.nombre AS socio, s.comunidad, SUM(d.cantidad * d.precio_unitario) AS importe
             FROM venta_detalle d
             JOIN ventas v    ON v.id = d.venta_id
             JOIN productos p ON p.id = d.producto_id
             JOIN socios s    ON s.id = p.socio_id
             WHERE v.fecha >= :desde AND v.fecha < :hasta
             GROUP BY s.id, s.nombre, s.comunidad
             HAVING importe >= :minimo
             ORDER BY importe DESC');
        $c->execute($this->periodo($desde, $hasta) + ['minimo' => $minimo]);
        return $c->fetchAll();
    }

    public function sinVentas(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $c = $this->bd->prepare(
            'SELECT p.codigo, p.nombre, p.stock
             FROM productos p
             LEFT JOIN (
                 SELECT d.producto_id 
                 FROM venta_detalle d
                 JOIN ventas v ON v.id = d.venta_id
                 WHERE v.fecha >= :desde AND v.fecha < :hasta
                 GROUP BY d.producto_id
             ) vendidos ON p.id = vendidos.producto_id
             WHERE vendidos.producto_id IS NULL
             ORDER BY p.codigo ASC'
        );
        $c->execute($this->periodo($desde, $hasta));
        return $c->fetchAll();
    }

    public function detalle(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $c = $this->bd->prepare(
            'SELECT v.id AS venta, DATE_FORMAT(v.fecha, \'%Y-%m-%d %H:%i\') AS fecha, v.cliente, p.codigo, p.nombre AS producto,
                    d.cantidad, d.precio_unitario, d.cantidad * d.precio_unitario AS subtotal
             FROM venta_detalle d
             JOIN ventas v    ON v.id = d.venta_id
             JOIN productos p ON p.id = d.producto_id
             WHERE v.fecha >= :desde AND v.fecha < :hasta
             ORDER BY v.fecha, v.id, p.codigo');
        $c->execute($this->periodo($desde, $hasta));
        return $c->fetchAll();
    }
}