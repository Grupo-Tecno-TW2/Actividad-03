<?php
// src/Repositorios/ProductoRepositorioMysql.php — Implementación MySQL del repositorio de productos.
declare(strict_types=1);

namespace Apicultores\Repositorios;

use Apicultores\Modelo\Categoria;
use Apicultores\Modelo\Producto;
use Apicultores\Modelo\Socio;
use PDO;

final class ProductoRepositorioMysql implements ProductoRepositorio
{
    private const SQL_ORDENES = [
        'nombre' => 'p.nombre ASC',
        'precio' => 'p.precio ASC',
        'precio_desc' => 'p.precio DESC',
        'stock' => 'p.stock ASC',
    ];

    public function __construct(private readonly PDO $bd)
    {
    }

    public function porCodigo(string $codigo): ?Producto
    {
        $stmt = $this->bd->prepare(
            'SELECT p.codigo, p.nombre, p.categoria, p.precio, p.stock,
                    s.id AS socio_id,
                    s.nombre AS socio_nombre,
                    s.comunidad AS socio_comunidad,
                    s.telefono AS socio_telefono
             FROM productos p
             JOIN socios s ON p.socio_id = s.id
             WHERE p.codigo = ?'
        );

        $stmt->execute([$codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ? $this->hidratar($fila) : null;
    }

    public function buscar(
        string $texto,
        ?Categoria $categoria,
        string $orden,
        int $pagina,
        int $porPagina,
        ?int $socioId = null
    ): Pagina {
        $condiciones = ['1=1'];
        $parametros = [];

        if ($texto !== '') {
            $condiciones[] = '(p.codigo LIKE :q OR p.nombre LIKE :q)';
            $parametros['q'] = '%' .
                str_replace(['%', '_'], ['\%', '\_'], $texto) . '%';
        }

        if ($categoria !== null) {
            $condiciones[] = 'p.categoria = :categoria';
            $parametros['categoria'] = $categoria->value;
        }

        if ($socioId !== null) {
            $condiciones[] = 'p.socio_id = :socio_id';
            $parametros['socio_id'] = $socioId;
        }

        $sqlWhere = implode(' AND ', $condiciones);

        // 1. Conteo total
        $stmtCount = $this->bd->prepare(
            "SELECT COUNT(*) FROM productos p WHERE $sqlWhere"
        );
        $stmtCount->execute($parametros);
        $total = (int) $stmtCount->fetchColumn();

        // 2. Orden seguro: solo se aceptan columnas predefinidas
        $columnaOrden = self::SQL_ORDENES[$orden] ?? 'p.nombre ASC';

        // 3. Paginación
        $pagina = max(1, $pagina);
        $totalPaginas = max(1, (int) ceil($total / $porPagina));

        if ($pagina > $totalPaginas) {
            $pagina = $totalPaginas;
        }

        $offset = ($pagina - 1) * $porPagina;

        // 4. Consulta principal
        $sql = "SELECT p.codigo, p.nombre, p.categoria, p.precio, p.stock,
                       s.id AS socio_id,
                       s.nombre AS socio_nombre,
                       s.comunidad AS socio_comunidad,
                       s.telefono AS socio_telefono
                FROM productos p
                JOIN socios s ON p.socio_id = s.id
                WHERE $sqlWhere
                ORDER BY $columnaOrden
                LIMIT :limite OFFSET :offset";

        $stmt = $this->bd->prepare($sql);

        foreach ($parametros as $k => $v) {
            $tipo = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(":$k", $v, $tipo);
        }

        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $elementos = array_map(
            $this->hidratar(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        return new Pagina($elementos, $total, $pagina, $porPagina);
    }

    public function deSocio(int $socioId): array
    {
        $stmt = $this->bd->prepare(
            'SELECT p.codigo, p.nombre, p.categoria, p.precio, p.stock,
                    s.id AS socio_id,
                    s.nombre AS socio_nombre,
                    s.comunidad AS socio_comunidad,
                    s.telefono AS socio_telefono
             FROM productos p
             JOIN socios s ON p.socio_id = s.id
             WHERE p.socio_id = ?'
        );

        $stmt->execute([$socioId]);

        return array_map(
            $this->hidratar(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function existeCodigo(string $codigo): bool
    {
        $stmt = $this->bd->prepare(
            'SELECT COUNT(*) FROM productos WHERE codigo = ?'
        );

        $stmt->execute([$codigo]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function guardar(array $datos): void
    {
        $sql = 'INSERT INTO productos
                    (codigo, nombre, categoria, precio, stock, socio_id)
                VALUES
                    (:codigo, :nombre_insert, :categoria_insert,
                     :precio_insert, :stock_insert, :socio_id_insert)
                ON DUPLICATE KEY UPDATE
                    nombre = :nombre_update,
                    categoria = :categoria_update,
                    precio = :precio_update,
                    stock = :stock_update,
                    socio_id = :socio_id_update';

        $stmt = $this->bd->prepare($sql);

        $categoria = $datos['categoria'] instanceof Categoria
            ? $datos['categoria']->value
            : $datos['categoria'];

        $stmt->execute([
            'codigo' => $datos['codigo'],

            // Parámetros para INSERT
            'nombre_insert' => $datos['nombre'],
            'categoria_insert' => $categoria,
            'precio_insert' => $datos['precio'],
            'stock_insert' => $datos['stock'],
            'socio_id_insert' => $datos['socio_id'],

            // Parámetros distintos para UPDATE
            'nombre_update' => $datos['nombre'],
            'categoria_update' => $categoria,
            'precio_update' => $datos['precio'],
            'stock_update' => $datos['stock'],
            'socio_id_update' => $datos['socio_id'],
        ]);
    }

    public function eliminar(string $codigo): void
    {
        $stmt = $this->bd->prepare(
            'DELETE FROM productos WHERE codigo = ?'
        );

        $stmt->execute([$codigo]);
    }

    private function hidratar(array $f): Producto
    {
        return new Producto(
            codigo: (string) $f['codigo'],
            nombre: (string) $f['nombre'],
            categoria: Categoria::from($f['categoria']),
            precio: (string) $f['precio'],
            stock: (int) $f['stock'],
            socio: new Socio(
                (int) $f['socio_id'],
                (string) $f['socio_nombre'],
                (string) $f['socio_comunidad'],
                isset($f['socio_telefono'])
                    ? (string) $f['socio_telefono']
                    : null
            )
        );
    }
}
