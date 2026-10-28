<?php
declare(strict_types=1);

namespace Apicultores\Ventas;

use Apicultores\Excepciones\Conflicto;
use Apicultores\Excepciones\DatosInvalidos;
use PDO;
use Throwable;

final class RegistroVenta
{
    public function __construct(private readonly PDO $bd)
    {
    }

    public function registrar(array $lineas, string $cliente, int $usuarioId): int
    {
        if ($lineas === [] || trim($cliente) === '') {
            throw new DatosInvalidos(['general' => 'Indica el cliente y al menos un producto con cantidad.']);
        }
        
        $this->bd->beginTransaction();
        
        try {
            // Usamos nombres únicos para evitar el error HY093
            $buscar = $this->bd->prepare('SELECT id, precio FROM productos WHERE codigo = :codigo');
            
            // Parámetros únicos para el UPDATE
            $descontar = $this->bd->prepare('UPDATE productos SET stock = stock - :cant1 WHERE id = :id AND stock >= :cant2');
            
            $detalle = [];
            $total = 0.0;
            
            foreach ($lineas as $codigo => $cantidad) {
                $buscar->execute(['codigo' => $codigo]);
                $p = $buscar->fetch() ?: throw new DatosInvalidos(['general' => "No existe el producto $codigo."]);
                
                $descontar->execute([
                    'cant1' => $cantidad,
                    'id' => $p['id'],
                    'cant2' => $cantidad
                ]);
                
                if ($descontar->rowCount() === 0) {
                    throw new Conflicto("Stock insuficiente de $codigo para vender $cantidad.");
                }
                
                $detalle[] = [$p['id'], $cantidad, $p['precio']];
                $total += $cantidad * (float) $p['precio'];
            }
            
            $this->bd->prepare('INSERT INTO ventas (fecha, cliente, usuario_id, total) VALUES (NOW(), :cliente, :usuario, :total)')
                ->execute(['cliente' => trim($cliente), 'usuario' => $usuarioId, 'total' => number_format($total, 2, '.', '')]);
            
            $ventaId = (int) $this->bd->lastInsertId();
            
            $insertar = $this->bd->prepare('INSERT INTO venta_detalle (venta_id, producto_id, cantidad, precio_unitario) VALUES (:venta, :prod, :cant, :precio)');
            foreach ($detalle as [$productoId, $cantidad, $precio]) {
                $insertar->execute([
                    'venta' => $ventaId,
                    'prod' => $productoId,
                    'cant' => $cantidad,
                    'precio' => $precio
                ]);
            }
            
            $this->bd->commit();
            return $ventaId;
            
        } catch (Throwable $e) {
            if ($this->bd->inTransaction()) {
                $this->bd->rollBack();
            }
            throw $e;
        }
    }
}