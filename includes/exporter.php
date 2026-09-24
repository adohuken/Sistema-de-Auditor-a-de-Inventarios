<?php
/**
 * Motor de Exportación Avanzado a Excel Formateado (.xls) y CSV (.csv)
 * Sistema de Auditoría de Inventarios
 */

/**
 * Genera y descarga una hoja de cálculo formateada en Excel (.xls) con estilos profesionales,
 * colores de estado, formato de moneda, números formateados y fila de totales.
 *
 * @param string $filename Nombre sugerido para el archivo (ej: conciliacion_auditoria.xls)
 * @param string $titulo Título principal del reporte (ej: REPORTE DE CONCILIACIÓN DE INVENTARIO)
 * @param string $subtitulo Subtítulo o metadata (ej: Evento: Auditoría Física Q3 2026 | Bodega Principal)
 * @param array $columnas Nombres de los encabezados de columna
 * @param array $filas Datos de las filas. Cada celda puede ser un valor simple o una estructura:
 *                      ['val' => '75010001', 'type' => 'sku|currency|num|center|status|text']
 * @param array $totales Fila opcional de totales al final del reporte
 */
function exportarExcelFormateado($filename, $titulo, $subtitulo, $columnas, $filas, $totales = []) {
    if (ob_get_length()) ob_clean();

    if (!str_ends_with(strtolower($filename), '.xls') && !str_ends_with(strtolower($filename), '.xlsx')) {
        $filename .= '.xls';
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    header('Pragma: public');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8">';
    echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Reporte Auditoría</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>';
    echo 'body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; background-color: #ffffff; color: #0f172a; margin: 15px; }';
    echo 'table { border-collapse: collapse; width: 100%; margin-top: 10px; }';
    echo 'th { background-color: #4f46e5; color: #ffffff; font-weight: bold; font-size: 11pt; border: 1px solid #3730a3; padding: 10px 14px; text-align: center; vertical-align: middle; }';
    echo 'td { border: 1px solid #cbd5e1; padding: 7px 12px; font-size: 10.5pt; vertical-align: middle; }';
    echo 'tr:nth-child(even) td { background-color: #f8fafc; }';
    echo '.title-header { font-size: 16pt; font-weight: bold; color: #1e1b4b; margin-bottom: 4px; }';
    echo '.subtitle-header { font-size: 10pt; color: #475569; margin-bottom: 16px; border-bottom: 2px solid #4f46e5; padding-bottom: 8px; }';
    echo '.sku { font-family: "Consolas", "Courier New", monospace; background-color: #f1f5f9; text-align: center; font-weight: bold; }';
    echo '.num { text-align: right; mso-number-format:"\#\,\#\#0\.00"; }';
    echo '.currency { text-align: right; mso-number-format:"\"$\"\#\,\#\#0\.00"; font-weight: 500; }';
    echo '.currency-negative { text-align: right; mso-number-format:"\"$\"\#\,\#\#0\.00"; color: #b91c1c; font-weight: bold; }';
    echo '.currency-positive { text-align: right; mso-number-format:"\"$\"\#\,\#\#0\.00"; color: #047857; font-weight: bold; }';
    echo '.center { text-align: center; }';
    echo '.status-coincide { background-color: #d1fae5; color: #047857; font-weight: bold; text-align: center; }';
    echo '.status-faltante { background-color: #fee2e2; color: #b91c1c; font-weight: bold; text-align: center; }';
    echo '.status-sobrante { background-color: #fef3c7; color: #b45309; font-weight: bold; text-align: center; }';
    echo '.status-pendiente { background-color: #f1f5f9; color: #475569; font-weight: 500; text-align: center; }';
    echo '.total-row td { background-color: #e2e8f0; font-weight: bold; font-size: 11pt; border-top: 2.5px solid #0f172a; border-bottom: 2.5px solid #0f172a; }';
    echo '</style></head><body>';

    echo '<div class="title-header">' . htmlspecialchars($titulo) . '</div>';
    echo '<div class="subtitle-header">' . htmlspecialchars($subtitulo) . ' &bull; Generado el: ' . date('d/m/Y H:i:s') . '</div>';

    echo '<table>';
    echo '<thead><tr>';
    foreach ($columnas as $col) {
        echo '<th>' . htmlspecialchars($col) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($filas as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            $val = is_array($cell) ? ($cell['val'] ?? '') : $cell;
            $type = is_array($cell) ? ($cell['type'] ?? 'text') : 'text';

            $class = '';
            if ($type === 'sku') $class = 'sku';
            elseif ($type === 'num') $class = 'num';
            elseif ($type === 'currency') {
                $numVal = floatval(str_replace(['$', ',', ' '], '', $val));
                if ($numVal < 0) $class = 'currency-negative';
                elseif ($numVal > 0) $class = 'currency-positive';
                else $class = 'currency';
            }
            elseif ($type === 'center') $class = 'center';
            elseif ($type === 'status') {
                $st = strtolower(trim($val));
                if (strpos($st, 'coincide') !== false) $class = 'status-coincide';
                elseif (strpos($st, 'faltante') !== false) $class = 'status-faltante';
                elseif (strpos($st, 'sobrante') !== false) $class = 'status-sobrante';
                else $class = 'status-pendiente';
            }

            echo '<td class="' . $class . '">' . htmlspecialchars($val) . '</td>';
        }
        echo '</tr>';
    }

    if (!empty($totales)) {
        echo '<tr class="total-row">';
        foreach ($totales as $tot) {
            $val = is_array($tot) ? ($tot['val'] ?? '') : $tot;
            $type = is_array($tot) ? ($tot['type'] ?? 'text') : 'text';
            $class = ($type === 'currency') ? 'currency' : (($type === 'num') ? 'num' : (($type === 'center') ? 'center' : ''));
            echo '<td class="' . $class . '">' . htmlspecialchars($val) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

/**
 * Genera y descarga un archivo CSV estructurado con delimitador de punto y coma (;)
 * y BOM UTF-8 para apertura perfecta en Excel en Español
 */
function exportarCSVEstandar($filename, $columnas, $filas, $totales = []) {
    if (ob_get_length()) ob_clean();

    if (!str_ends_with(strtolower($filename), '.csv')) {
        $filename .= '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, $columnas, ';');

    foreach ($filas as $row) {
        $cleanRow = [];
        foreach ($row as $cell) {
            $cleanRow[] = is_array($cell) ? ($cell['val'] ?? '') : $cell;
        }
        fputcsv($out, $cleanRow, ';');
    }

    if (!empty($totales)) {
        $cleanTot = [];
        foreach ($totales as $tot) {
            $cleanTot[] = is_array($tot) ? ($tot['val'] ?? '') : $tot;
        }
        fputcsv($out, $cleanTot, ';');
    }

    fclose($out);
    exit;
}
