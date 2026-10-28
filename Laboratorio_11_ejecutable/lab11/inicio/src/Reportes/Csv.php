<?php
// src/Reportes/Csv.php — Exportación a CSV que Excel en español abre bien y que no ejecuta fórmulas.
declare(strict_types=1);

namespace Apicultores\Reportes;

final class Csv
{
    /** Genera el contenido CSV (separador ";" y coma decimal, como espera Excel configurado en español). */
    public static function generar(array $encabezados, array $filas): string
    {
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, "\xEF\xBB\xBF");                       // BOM: Excel reconoce UTF-8 (tildes y ñ)
        fputcsv($flujo, $encabezados, ';', '"', '');
        foreach ($filas as $fila) {
            // Escapamos las celdas antes de pasarlas a fputcsv
            fputcsv($flujo, array_map(self::celdaSegura(...), array_values($fila)), ';', '"', '');
        }
        rewind($flujo);
        return (string) stream_get_contents($flujo);
    }

    /**
     * Inyección CSV: un cliente llamado "=HYPERLINK(...)" se convertiría en FÓRMULA al abrir el archivo en Excel.
     * Se antepone un apóstrofo a los textos que empiezan con = + - @ (o tabulador / retorno).
     */
    public static function celdaSegura(mixed $valor): string
    {
        // 1. Manejo de números decimales (flotantes o strings tipo "123.45")
        if (is_float($valor) || (is_string($valor) && preg_match('/^-?\d+\.\d+$/', $valor))) {
            return str_replace('.', ',', (string) $valor); // 45.50 → 45,50
        }
        
        // 2. Manejo de números enteros (para que no se conviertan en "3,00")
        if (is_int($valor) || (is_string($valor) && preg_match('/^-?\d+$/', $valor))) {
            return (string) $valor;
        }

        // 3. TODO 6 resuelto: Neutralizar fórmulas (incluye espacios o tabuladores al inicio)
        $texto = (string) $valor;
        // Expresión regular que busca =, +, -, @, tabulador (\t) o retorno de carro (\r) al inicio
        if (preg_match('/^[=+\-@\t\r]/', $texto)) {
            return "'" . $texto;
        }

        return $texto;
    }
}