<?php
// verificar.php
// Comprueba el Laboratorio 10:
// password_hash, sesiones, roles, CSRF, intentos y cabeceras.
//
// Uso: php verificar.php
// Requiere MySQL en marcha: docker compose up -d
//
// IMPORTANTE:
// Este verificador restaura las tablas y los datos de la base configurada.
// Ejecutarlo únicamente sobre la base de pruebas.

declare(strict_types=1);

require is_file(__DIR__ . '/Verificador.php')
    ? __DIR__ . '/Verificador.php'
    : __DIR__ . '/../../comun/Verificador.php';

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    exit(
        "❌ Falta vendor/autoload.php. Primero ejecuta: composer dump-autoload"
        . PHP_EOL
    );
}

require __DIR__ . '/vendor/autoload.php';

use Apicultores\BaseDatos\Conexion;
use Apicultores\Modelo\Rol;

try {
    $bd = Conexion::crear(require __DIR__ . '/config/bd.php');
} catch (PDOException $e) {
    exit(
        "❌ No hay conexión con MySQL ({$e->getMessage()})."
        . PHP_EOL
        . "   Inicia la base de datos: docker compose up -d"
        . PHP_EOL
    );
}

/**
 * Restaura las tablas y los datos de prueba.
 */
function restaurar(PDO $bd): void
{
    foreach (
        [
            '01-esquema.sql',
            '02-datos.sql',
            '03-mas-datos.sql',
            '04-usuarios.sql'
        ] as $archivo
    ) {
        $ruta = __DIR__ . "/sql/$archivo";

        if (!is_file($ruta)) {
            throw new RuntimeException(
                "No se encontró el archivo SQL: $ruta"
            );
        }

        $bd->exec((string) file_get_contents($ruta));
    }
}

/**
 * Obtiene el hash de la contraseña de un usuario.
 */
function hashDe(PDO $bd, string $correo): string
{
    $consulta = $bd->prepare(
        'SELECT clave_hash FROM usuarios WHERE correo = ?'
    );

    $consulta->execute([$correo]);

    return (string) $consulta->fetchColumn();
}

/**
 * Extrae el token CSRF del HTML.
 */
function token(string $html): ?string
{
    return preg_match(
        '/name="_csrf" value="([a-f0-9]{64})"/',
        $html,
        $coincidencias
    )
        ? $coincidencias[1]
        : null;
}

/**
 * Extrae el identificador de sesión de las cabeceras.
 */
function idSesion(string $cabeceras): ?string
{
    return preg_match(
        '/Set-Cookie: APISESION=([^;]+)/i',
        $cabeceras,
        $coincidencias
    ) && $coincidencias[1] !== 'deleted'
        ? $coincidencias[1]
        : null;
}

/**
 * Abre el formulario de ingreso y envía las credenciales.
 *
 * Devuelve la respuesta y el identificador de sesión anterior.
 */
function entrar(
    Verificador $v,
    string $correo,
    string $clave
): array {
    $v->olvidarCookies();

    $formulario = $v->pedir('/entrar');

    $respuesta = $v->pedir(
        '/entrar',
        'POST',
        [
            '_csrf' => token($formulario['cuerpo']),
            'correo' => $correo,
            'clave' => $clave
        ]
    );

    return [
        $respuesta,
        idSesion($formulario['cabeceras'])
    ];
}

/**
 * Envía una petición POST usando el token CSRF
 * obtenido de la página indicada.
 */
function postConToken(
    Verificador $v,
    string $pagina,
    string $ruta,
    array $datos = []
): array {
    $respuestaPagina = $v->pedir($pagina);

    return $v->pedir(
        $ruta,
        'POST',
        [
            '_csrf' => token($respuestaPagina['cuerpo'])
        ] + $datos
    );
}

// Restaurar la base de pruebas antes de iniciar.
restaurar($bd);

$v = new Verificador();

// --------------------------------------------------
// 1. Contraseñas protegidas mediante password_hash
// --------------------------------------------------

$v->revisar(
    'Contraseñas guardadas con password_hash (bcrypt), nunca en texto',
    fn() =>
        str_starts_with(
            hashDe($bd, 'admin@florchiquitania.bo'),
            '$2y$12$'
        )
        && password_verify(
            'Panal-2026!',
            hashDe($bd, 'admin@florchiquitania.bo')
        )
        && (int) $bd->query(
            "SELECT COUNT(*) FROM usuarios
             WHERE clave_hash LIKE '%2026!%'"
        )->fetchColumn() === 0
);

// --------------------------------------------------
// 2. Matriz de permisos
// --------------------------------------------------

$v->revisar(
    'Matriz de permisos: operador edita pero no elimina; socio solo ve; acción desconocida → no',
    fn() =>
        Rol::Operador->puede('productos.editar')
        && !Rol::Operador->puede('productos.eliminar')
        && Rol::Admin->puede('productos.eliminar')
        && Rol::Socio->puede('productos.ver')
        && !Rol::Socio->puede('productos.crear')
        && !Rol::Admin->puede('sistema.borrar_todo')
);

// Iniciar el servidor de pruebas.
$v->servidor(
    __DIR__ . '/public',
    __DIR__ . '/public/index.php'
);

// --------------------------------------------------
// 3. Acceso a productos sin iniciar sesión
// --------------------------------------------------

$anonimo = $v->pedir('/productos?pagina=2');

$v->revisar(
    'Sin sesión: /productos → 302 a /entrar?volver=… (recuerda adónde ibas)',
    fn() =>
        $anonimo['estado'] === 302
        && str_contains(
            $anonimo['cabeceras'],
            'Location: /entrar?volver=%2Fproductos%3Fpagina%3D2'
        )
);

// --------------------------------------------------
// 4. Cabeceras de seguridad
// --------------------------------------------------

$v->olvidarCookies();

$cabecerasEntrada = $v->pedir('/entrar');

$v->revisar(
    'Cabeceras de seguridad: CSP, nosniff, Referrer-Policy, sin X-Powered-By',
    fn() =>
        str_contains(
            $cabecerasEntrada['cabeceras'],
            "Content-Security-Policy: default-src 'self'; frame-ancestors 'none'"
        )
        && stripos(
            $cabecerasEntrada['cabeceras'],
            'X-Content-Type-Options: nosniff'
        ) !== false
        && str_contains(
            $cabecerasEntrada['cabeceras'],
            'Referrer-Policy:'
        )
        && stripos(
            $cabecerasEntrada['cabeceras'],
            'X-Powered-By'
        ) === false
);

// --------------------------------------------------
// 5. Seguridad de la cookie de sesión
// --------------------------------------------------

$v->revisar(
    'Cookie de sesión: nombre propio, HttpOnly y SameSite=Lax',
    fn() =>
        preg_match(
            '/Set-Cookie: APISESION=[^\r\n]*HttpOnly[^\r\n]*SameSite=Lax/i',
            $cabecerasEntrada['cabeceras']
        ) === 1
);

// --------------------------------------------------
// 6. Mensaje uniforme al fallar el ingreso
// --------------------------------------------------

[$malUno] = entrar(
    $v,
    'admin@florchiquitania.bo',
    'clave-equivocada'
);

[$malDos] = entrar(
    $v,
    'nadie@florchiquitania.bo',
    'clave-equivocada'
);

$v->revisar(
    'Contraseña errónea y correo inexistente → 401 con EL MISMO mensaje',
    fn() =>
        $malUno['estado'] === 401
        && $malDos['estado'] === 401
        && str_contains(
            $malUno['cuerpo'],
            'Correo o contraseña incorrectos.'
        )
        && str_contains(
            $malDos['cuerpo'],
            'Correo o contraseña incorrectos.'
        )
);

// --------------------------------------------------
// 7. Protección CSRF en el formulario de ingreso
// --------------------------------------------------

$v->revisar(
    'Iniciar sesión sin token CSRF → 403',
    function () use ($v) {
        $v->olvidarCookies();

        $v->pedir('/entrar');

        $respuesta = $v->pedir(
            '/entrar',
            'POST',
            [
                'correo' => 'admin@florchiquitania.bo',
                'clave' => 'Panal-2026!'
            ]
        );

        return $respuesta['estado'] === 403;
    }
);

// --------------------------------------------------
// 8. Regeneración del identificador de sesión
// --------------------------------------------------

[$ingresoCorrecto, $idAnterior] = entrar(
    $v,
    'admin@florchiquitania.bo',
    'Panal-2026!'
);

$v->revisar(
    'Ingreso correcto → 303 y ID de sesión NUEVO (session_regenerate_id)',
    fn() =>
        $ingresoCorrecto['estado'] === 303
        && $idAnterior !== null
        && idSesion($ingresoCorrecto['cabeceras']) !== null
        && idSesion($ingresoCorrecto['cabeceras']) !== $idAnterior
);

// --------------------------------------------------
// 9. Eliminación permitida para administrador y CSRF
// --------------------------------------------------

$v->revisar(
    'Admin: elimina con token (303); sin token o con token falso → 403',
    function () use ($v, $bd) {
        $sinToken = $v->pedir(
            '/productos/CAR-MIE/eliminar',
            'POST',
            []
        );

        $tokenFalso = $v->pedir(
            '/productos/CAR-MIE/eliminar',
            'POST',
            [
                '_csrf' => str_repeat('a', 64)
            ]
        );

        $conToken = postConToken(
            $v,
            '/productos',
            '/productos/CAR-MIE/eliminar'
        );

        $cantidad = (int) $bd->query(
            "SELECT COUNT(*) FROM productos
             WHERE codigo = 'CAR-MIE'"
        )->fetchColumn();

        return $sinToken['estado'] === 403
            && $tokenFalso['estado'] === 403
            && $conToken['estado'] === 303
            && $cantidad === 0;
    }
);

// --------------------------------------------------
// 10. Cierre de sesión
// --------------------------------------------------

$v->revisar(
    'Cerrar sesión (POST con token) → luego /productos vuelve a pedir ingreso',
    fn() =>
        postConToken(
            $v,
            '/productos',
            '/salir'
        )['estado'] === 303
        && $v->pedir('/productos')['estado'] === 302
);

// --------------------------------------------------
// 11. Actualización del coste del hash de contraseña
// --------------------------------------------------

$hashViejo = hashDe(
    $bd,
    'operador@florchiquitania.bo'
);

entrar(
    $v,
    'operador@florchiquitania.bo',
    'Colmena-2026!'
);

$v->revisar(
    'Operador: el hash cost 10 se actualiza a cost 12 al ingresar (password_needs_rehash)',
    fn() =>
        str_starts_with($hashViejo, '$2y$10$')
        && str_starts_with(
            hashDe($bd, 'operador@florchiquitania.bo'),
            '$2y$12$'
        )
);

// --------------------------------------------------
// 12. Operador puede editar, pero no eliminar
// --------------------------------------------------

$v->revisar(
    'Operador: edita (303) pero eliminar → 403 y no ve el botón Eliminar',
    function () use ($v) {
        // Obtener la lista antes de realizar la edición.
        $lista = $v->pedir('/productos');

        // Intentar editar un producto.
        $edicion = postConToken(
            $v,
            '/productos/MIE-500/editar',
            '/productos/MIE-500',
            [
                'nombre' => 'Miel multifloral 500 g',
                'categoria' => 'Miel',
                'precio' => '46',
                'stock' => '32',
                'socio_id' => '1'
            ]
        );

        // Intentar eliminar el mismo producto.
        $eliminacion = postConToken(
            $v,
            '/productos',
            '/productos/MIE-500/eliminar'
        );

        // Verificar que el botón no esté presente.
        $sinBotonEliminar = !str_contains(
            $lista['cuerpo'],
            '>Eliminar<'
        );

        // Mantener las tres condiciones de la prueba.
        return $edicion['estado'] === 303
            && $eliminacion['estado'] === 403
            && $sinBotonEliminar;
    }
);

// --------------------------------------------------
// 13. Restricciones para el socio
// --------------------------------------------------

entrar(
    $v,
    'elena@florchiquitania.bo',
    'Polen-2026!'
);

$socio = $v->pedir('/productos');

$v->revisar(
    'Socio (Elena): ve SOLO sus 4 productos; registrar y ver socios → 403',
    fn() =>
        str_contains(
            $socio['cuerpo'],
            'Mostrando 1–4 de 4'
        )
        && $v->pedir('/productos/nuevo')['estado'] === 403
        && $v->pedir('/socios')['estado'] === 403
        && !str_contains(
            $socio['cuerpo'],
            'Registrar producto'
        )
);

// --------------------------------------------------
// 14. Bloqueo después de intentos fallidos
// --------------------------------------------------

$v->revisar(
    '5 intentos fallidos bloquean la cuenta: el 6.º, aun correcto, → 429 con Retry-After',
    function () use ($v) {
        for ($i = 0; $i < 5; $i++) {
            entrar(
                $v,
                'elena@florchiquitania.bo',
                "mal-$i"
            );
        }

        [$respuesta] = entrar(
            $v,
            'elena@florchiquitania.bo',
            'Polen-2026!'
        );

        return $respuesta['estado'] === 429
            && preg_match(
                '/Retry-After: (\d+)/',
                $respuesta['cabeceras'],
                $coincidencias
            ) === 1
            && (int) $coincidencias[1] > 800
            && str_contains(
                $respuesta['cuerpo'],
                'Demasiados intentos'
            );
    }
);

// --------------------------------------------------
// 15. Protección contra redirecciones abiertas
// --------------------------------------------------

$v->revisar(
    'Redirección abierta bloqueada: volver=//sitio-malo.com → /productos',
    function () use ($v) {
        $v->olvidarCookies();

        $formulario = $v->pedir(
            '/entrar?volver=' . rawurlencode('//sitio-malo.com')
        );

        $respuesta = $v->pedir(
            '/entrar',
            'POST',
            [
                '_csrf' => token($formulario['cuerpo']),
                'correo' => 'admin@florchiquitania.bo',
                'clave' => 'Panal-2026!',
                'volver' => '//sitio-malo.com'
            ]
        );

        return $respuesta['estado'] === 303
            && str_contains(
                $respuesta['cabeceras'],
                'Location: /productos'
            );
    }
);

// Eliminar el registro de errores de prueba, si existe.
@unlink(__DIR__ . '/datos/errores.log');

// Restaurar los datos originales de la base de pruebas.
restaurar($bd);

// Mostrar el resultado final.
exit($v->fin());

