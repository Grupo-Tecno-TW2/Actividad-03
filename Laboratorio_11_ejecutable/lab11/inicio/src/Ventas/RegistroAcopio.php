<?php
declare(strict_types=1);

namespace Apicultores\Ventas;

use Apicultores\Excepciones\DatosInvalidos;
use PDO;
use Throwable;

final class RegistroAcopio
{
    public function __construct(private readonly PDO $bd)
    {
    }

    public function registrar(string $codigo, int $cantidad, string $precioPagado): int
    {
        if ($cantidad <= 0 || (float) $precioPagado <= 0) {
            throw new DatosInvalidos(['general' => 'La cantidad y el precio pagado deben ser mayores que cero.']);
        }
        
        $this->bd->beginTransaction();
        try {
            $c = $this->bd->prepare('SELECT id, socio_id FROM productos WHERE codigo = :codigo FOR UPDATE');
            $c->execute(['codigo' => $codigo]);
            $p = $c->fetch() ?: throw new DatosInvalidos(['general' => "No existe el producto $codigo."]);
            
            $this->bd->prepare('INSERT INTO acopios (fecha, socio_id, producto_id, cantidad, precio_pagado) VALUES (NOW(), :socio, :prod, :cant, :precio)')
                ->execute([
                    'socio' => $p['socio_id'], 
                    'prod' => $p['id'], 
                    'cant' => $cantidad, 
                    'precio' => $precioPagado
                ]);
                
            $id = (int) $this->bd->lastInsertId();
            
            $this->bd->prepare('UPDATE productos SET stock = stock + :cantidad WHERE id = :id')
                ->execute(['cantidad' => $cantidad, 'id' => $p['id']]);
                
            $this->bd->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->bd->inTransaction()) {
                $this->bd->rollBack();
            }
            throw $e;
        }
    }
}