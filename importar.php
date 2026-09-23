<?php
/**
 * Módulo 1: Carga de Existencias Teóricas (Importador Multiformato: XLSX, XLS, CSV)
 * Soporta archivos Excel Nativos (.xlsx), Excel HTML (.xls), CSV con comas/semicolons y ERP
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir rol de Administrador
exigirRol(['admin']);

$eventoActual = obtenerEventoActivo();
if (!$eventoActual) {
    try {
        $pdo = getPDOConnection();
        $pdo->exec("INSERT INTO eventos_auditoria (nombre_evento, bodega_sucursal, fecha_inicio, estado, usuario_creador) VALUES ('Auditoría Física " . date('d/m/Y') . "', 'Bodega Principal', NOW(), 'activa', 'Admin')");
        $nuevoId = $pdo->lastInsertId();
        $_SESSION['evento_id'] = $nuevoId;
        $eventoActual = obtenerEventoActivo();
    } catch (Exception $e) {}
}

$resultado = null;
$error = null;

// Helper para remover BOM UTF-8 y UTF-16
function removerBOM($str) {
    if (substr($str, 0, 3) === "\xEF\xBB\xBF") {
        return substr($str, 3);
    }
    if (substr($str, 0, 2) === "\xFE\xFF" || substr($str, 0, 2) === "\xFF\xFE") {
        return substr($str, 2);
    }
    return $str;
}

// Helper para normalizar cadenas UTF-8 sin entidades HTML
function normalizarTextoUTF8($str) {
    $str = removerBOM(trim((string)$str));
    return mb_strtolower(strip_tags(html_entity_decode($str, ENT_QUOTES, 'UTF-8')), 'UTF-8');
}

// Helper para limpiar montos numéricos (remueve $, C$, comas de millares, soporta comas decimales)
function limpiarNumero($val) {
    if (is_numeric($val)) return floatval($val);
    $val = trim((string)$val);
    $val = str_replace(['$', 'C$', ' '], '', $val);
    if ($val === '') return 0.0;

    $hasDot = strpos($val, '.') !== false;
    $hasComma = strpos($val, ',') !== false;

    if ($hasDot && $hasComma) {
        $lastDot = strrpos($val, '.');
        $lastComma = strrpos($val, ',');
        if ($lastComma > $lastDot) {
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    } elseif ($hasComma && !$hasDot) {
        $parts = explode(',', $val);
        if (count($parts) === 2 && strlen($parts[1]) <= 2) {
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    }
    return floatval($val);
}

// Helper 1: Extraer filas desde archivos Excel Nativos XLSX (.xlsx / ZIP XML)
function extraerFilasDeXlsx($filePath) {
    if (!class_exists('ZipArchive')) return false;

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return false;
    }

    // 1. Leer Shared Strings
    $sharedStrings = [];
    $sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXmlContent !== false) {
        $xml = @simplexml_load_string($sharedXmlContent);
        if ($xml) {
            foreach ($xml->si as $val) {
                if (isset($val->t)) {
                    $sharedStrings[] = (string)$val->t;
                } else {
                    $text = '';
                    foreach ($val->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // 2. Leer Hoja Principal (sheet1.xml)
    $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXmlContent === false) {
        $zip->close();
        return false;
    }

    $xmlSheet = @simplexml_load_string($sheetXmlContent);
    $zip->close();

    if (!$xmlSheet) return false;

    $filas = [];
    foreach ($xmlSheet->sheetData->row as $row) {
        $fila = [];
        foreach ($row->c as $cell) {
            $attr = $cell->attributes();
            $type = isset($attr['t']) ? (string)$attr['t'] : '';
            $val  = isset($cell->v) ? (string)$cell->v : '';

            if ($type === 's' && isset($sharedStrings[intval($val)])) {
                $val = $sharedStrings[intval($val)];
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $val = (string)$cell->is->t;
            }

            $fila[] = trim($val);
        }
        if (!empty($fila)) {
            $filas[] = implode(';', $fila);
        }
    }

    return $filas;
}

// Helper 2: Extraer filas desde contenido HTML <table> (.xls)
function extraerFilasDeHtml($content) {
    $filas = [];
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches);
    foreach ($trMatches[1] as $trContent) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $trContent, $tdMatches);
        if (!empty($tdMatches[1])) {
            $fila = array_map(function($val) {
                return trim(html_entity_decode(strip_tags($val), ENT_QUOTES, 'UTF-8'));
            }, $tdMatches[1]);
            $filas[] = implode(';', $fila);
        }
    }
    return $filas;
}

// Acción: Descargar Plantillas (Excel Nativo .xls o CSV con 4 columnas exactas)
if (isset($_GET['action']) && $_GET['action'] === 'download_sample') {
    $format = $_GET['format'] ?? 'excel';
    
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla_toma_inventario.xls"');
        
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><style>th{background-color:#4f46e5;color:#ffffff;font-weight:bold;} td,th{padding:8px;border:1px solid #cccccc;}</style></head>';
        echo '<body>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>BODEGA</th>';
        echo '<th>CÓDIGO</th>';
        echo '<th>PRODUCTO</th>';
        echo '<th>EXISTENCIA EN EL SISTEMA</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        $ejemplos = [
            ['01-Bodega Central', '0001', 'AUDIFONO CON MICROFONO JABRA EVOLVE 40 MONO 6393-823-109', '1.00'],
            ['01-Bodega Central', '0015', 'SCANNER HP SCANJET PRO 2000 S2 6FW06A#BGJ', '8.00'],
            ['01-Bodega Central', '0041', 'CAJA MODULAR 2 PUERTO NEXXT RJ45 CAT5e', '1.00'],
            ['01-Bodega Central', '0042', 'CAJA MODULAR 2 PUERTO QUEST (SIN JACK)', '2.00'],
            ['01-Bodega Central', '0068', 'BOTELLA DE TINTA EPSON 544 T544120 NEGRO', '27.00'],
            ['01-Bodega Central', '0070', 'CAMARA WEB LOGITECH C920S HD PRO 960-001257', '10.00']
        ];
        
        foreach ($ejemplos as $row) {
            echo '<tr>';
            foreach ($row as $val) {
                echo '<td>' . htmlspecialchars($val) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</tbody></table></body></html>';
        exit;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla_toma_inventario.csv"');
        echo chr(0xEF).chr(0xBB).chr(0xBF);
        echo "sep=;\n";
        echo "BODEGA;CÓDIGO;PRODUCTO;EXISTENCIA EN EL SISTEMA\n";
        echo "01-Bodega Central;0001;AUDIFONO CON MICROFONO JABRA EVOLVE 40 MONO 6393-823-109;1.00\n";
        echo "01-Bodega Central;0015;SCANNER HP SCANJET PRO 2000 S2 6FW06A#BGJ;8.00\n";
        echo "01-Bodega Central;0041;CAJA MODULAR 2 PUERTO NEXXT RJ45 CAT5e;1.00\n";
        echo "01-Bodega Central;0042;CAJA MODULAR 2 PUERTO QUEST (SIN JACK);2.00\n";
        exit;
    }
}

// Acción: Limpiar Datos del Evento Activo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset'])) {
    try {
        $pdo = getPDOConnection();
        $eventoId = $eventoActual['id'];
        if ($_POST['action_reset'] === 'reset_conteos') {
            $stmt = $pdo->prepare("DELETE FROM conteos_fisicos WHERE evento_id = :evento_id");
            $stmt->execute([':evento_id' => $eventoId]);
            $_SESSION['flash_success'] = "Se han borrado los conteos físicos de la auditoría <b>" . htmlspecialchars($eventoActual['nombre_evento']) . "</b>.";
        } elseif ($_POST['action_reset'] === 'reset_productos') {
            $stmt1 = $pdo->prepare("DELETE FROM productos_sistema WHERE evento_id = :evento_id");
            $stmt1->execute([':evento_id' => $eventoId]);
            $stmt2 = $pdo->prepare("DELETE FROM conteos_fisicos WHERE evento_id = :evento_id");
            $stmt2->execute([':evento_id' => $eventoId]);
            $_SESSION['flash_success'] = "Se han reiniciado los datos de la auditoría <b>" . htmlspecialchars($eventoActual['nombre_evento']) . "</b>.";
        }
        header("Location: importar.php");
        exit;
    } catch (Exception $e) {
        $error = "Error al reiniciar datos: " . $e->getMessage();
    }
}

// Procesar Formulario de Importación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar'])) {
    if ($eventoActual && $eventoActual['estado'] === 'cerrada') {
        $error = "La auditoría seleccionada [{$eventoActual['nombre_evento']}] está CERRADA. No puedes cargar existencias a una auditoría finalizada.";
    } else {
        $modo = $_POST['modo_importacion'] ?? 'archivo';
        $lineas = [];

        if ($modo === 'archivo' && isset($_FILES['archivo_csv']) && $_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
            $tmpName   = $_FILES['archivo_csv']['tmp_name'];
            $fileName  = $_FILES['archivo_csv']['name'];
            $fileExt   = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileHeader = file_get_contents($tmpName, false, null, 0, 4);

            // 1. Si es un archivo .xlsx (ZIP PK)
            if ($fileExt === 'xlsx' || $fileHeader === "PK\x03\x04") {
                $parsedXlsx = extraerFilasDeXlsx($tmpName);
                if ($parsedXlsx !== false && !empty($parsedXlsx)) {
                    $lineas = $parsedXlsx;
                } else {
                    $error = "No se pudo leer el archivo Excel (.xlsx). Asegúrate de guardarlo como Excel (.xlsx, .xls) o CSV.";
                }
            } else {
                $content = file_get_contents($tmpName);
                // 2. Si es un archivo HTML / XML .xls
                if (strpos($content, '<tr') !== false || strpos($content, '<table') !== false) {
                    $lineas = extraerFilasDeHtml($content);
                } else {
                    // 3. Si es un archivo CSV / TSV plano
                    if (!mb_check_encoding($content, 'UTF-8')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1, Windows-1252');
                    }
                    $content = str_replace(["\r\n", "\r"], "\n", $content);
                    $lineas = explode("\n", trim($content));
                }
            }
        } elseif ($modo === 'texto' && !empty($_POST['texto_csv'])) {
            $content = trim($_POST['texto_csv']);
            if (strpos($content, '<tr') !== false || strpos($content, '<table') !== false) {
                $lineas = extraerFilasDeHtml($content);
            } else {
                $content = str_replace(["\r\n", "\r"], "\n", $content);
                $lineas = explode("\n", $content);
            }
        } else {
            $error = "Por favor selecciona un archivo de Excel/CSV válido o pega un texto estructurado.";
        }

        if (!empty($lineas) && $error === null) {
            try {
                $pdo = getPDOConnection();
                $pdo->beginTransaction();

                // Limpiar basura anterior corrompida si existía
                try {
                    $pdo->exec("DELETE FROM productos_sistema WHERE evento_id = " . intval($eventoActual['id']) . " AND (codigo_producto LIKE '%<%' OR codigo_producto LIKE '%>%' OR codigo_producto LIKE '%&%' OR codigo_producto LIKE '%;%')");
                } catch (Exception $ex) {}

                $insertados = 0;
                $actualizados = 0;
                $erroresLin = 0;
                $totalProcesados = 0;
                $eventoId = $eventoActual['id'];

                // 1. Detectar Delimitador Inteligente (evaluando candidatos en las primeras 40 filas)
                $delimitadores = [';', ',', "\t", '|'];
                $conteoDelim = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
                
                $lineasMuestra = array_slice($lineas, 0, 40);
                foreach ($lineasMuestra as $l) {
                    $l = trim($l);
                    if (empty($l) || strpos(strtolower($l), 'sep=') === 0) continue;
                    foreach ($delimitadores as $d) {
                        $cols = str_getcsv($l, $d);
                        if (count($cols) > 1) {
                            $conteoDelim[$d] += count($cols);
                        }
                    }
                }
                arsort($conteoDelim);
                $delimitador = key($conteoDelim);
                if ($conteoDelim[$delimitador] === 0) {
                    $delimitador = ';';
                }

                // 2. Buscar Fila de Encabezados y Mapear Columnas
                $colMap = [
                    'bodega' => null,
                    'codigo' => null,
                    'producto' => null,
                    'categoria' => null,
                    'tipo' => null,
                    'marca' => null,
                    'existencias' => null,
                    'costo' => null,
                    'precio' => null
                ];
                $filaEncabezadoIdx = -1;
                $maxMatches = 0;

                foreach ($lineas as $idx => $linea) {
                    if ($idx > 25) break;
                    $lineaClean = removerBOM(trim($linea));
                    if (empty($lineaClean) || strpos(strtolower($lineaClean), 'sep=') === 0) continue;

                    $datos = str_getcsv($lineaClean, $delimitador);
                    if (count($datos) < 2) continue;

                    $coincidencias = 0;
                    $tempMap = [];

                    foreach ($datos as $colIdx => $valRaw) {
                        $val = normalizarTextoUTF8($valRaw);
                        if (empty($val)) continue;

                        if (preg_match('/^(bodega|sucursal|ubicaci[oó]n)$/u', $val)) {
                            $tempMap['bodega'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(c[oó]digo|sku|item|barras|cod_prod|c[oó]digo_producto|c[oó]digo del producto)$/u', $val)) {
                            $tempMap['codigo'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(producto|descripcion|descripci[oó]n|nombre|articulo|art[ií]culo|detalle)$/u', $val)) {
                            $tempMap['producto'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(existencia|existencias|existencias_sistema|existencia en el sistema|stock|cantidad|cant|teorico|te[oó]rico)$/u', $val)) {
                            $tempMap['existencias'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(categoria|categor[ií]a|linea|l[ií]nea|rubro|familia)$/u', $val)) {
                            $tempMap['categoria'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(tipo|clase|grupo)$/u', $val)) {
                            $tempMap['tipo'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(marca|fabricante)$/u', $val)) {
                            $tempMap['marca'] = $colIdx;
                            $coincidencias++;
                        } elseif (preg_match('/^(costo|costo_promedio|costo promedio|costo total)$/u', $val)) {
                            if (!isset($tempMap['costo'])) {
                                $tempMap['costo'] = $colIdx;
                                $coincidencias++;
                            }
                        } elseif (preg_match('/^(precio|precio_producto|precio producto|pvp|precio venta)$/u', $val)) {
                            $tempMap['precio'] = $colIdx;
                            $coincidencias++;
                        }
                    }

                    if ($coincidencias >= 2 && isset($tempMap['codigo']) && isset($tempMap['producto']) && $coincidencias > $maxMatches) {
                        $maxMatches = $coincidencias;
                        $colMap = array_merge($colMap, $tempMap);
                        $filaEncabezadoIdx = $idx;
                    }
                }

                // 3. Fallback de Mapa de Columnas si no se detectó encabezado explícito
                if ($colMap['codigo'] === null || $colMap['producto'] === null) {
                    $primeraFilaData = [];
                    foreach ($lineas as $l) {
                        $l = removerBOM(trim($l));
                        if (empty($l) || strpos(strtolower($l), 'sep=') === 0) continue;
                        $d = str_getcsv($l, $delimitador);
                        if (count($d) >= 2) {
                            $firstVal = normalizarTextoUTF8($d[0]);
                            if (strpos($firstVal, 'vargas') !== false || strpos($firstVal, 'informe') !== false || strpos($firstVal, 'puente') !== false || strpos($firstVal, 'j051') !== false) {
                                continue;
                            }
                            $primeraFilaData = $d;
                            break;
                        }
                    }

                    $numCols = count($primeraFilaData);
                    if ($numCols == 2) {
                        $colMap['codigo'] = 0; $colMap['producto'] = 1;
                    } elseif ($numCols == 3) {
                        $colMap['codigo'] = 0; $colMap['producto'] = 1; $colMap['existencias'] = 2;
                    } elseif ($numCols == 4) {
                        $col0Val = normalizarTextoUTF8($primeraFilaData[0] ?? '');
                        if (strpos($col0Val, 'bodega') !== false || strpos($col0Val, 'sucursal') !== false) {
                            $colMap['bodega'] = 0; $colMap['codigo'] = 1; $colMap['producto'] = 2; $colMap['existencias'] = 3;
                        } else {
                            $colMap['codigo'] = 0; $colMap['producto'] = 1; $colMap['categoria'] = 2; $colMap['existencias'] = 3;
                        }
                    } elseif ($numCols == 5) {
                        $col0Val = normalizarTextoUTF8($primeraFilaData[0] ?? '');
                        if (strpos($col0Val, 'bodega') !== false || strpos($col0Val, 'sucursal') !== false) {
                            $colMap['bodega'] = 0; $colMap['codigo'] = 1; $colMap['producto'] = 2; $colMap['existencias'] = 3;
                        } else {
                            $colMap['codigo'] = 0; $colMap['producto'] = 1; $colMap['existencias'] = 2; $colMap['costo'] = 3; $colMap['precio'] = 4;
                        }
                    } elseif ($numCols == 8) {
                        $colMap['codigo'] = 0; $colMap['producto'] = 1; $colMap['categoria'] = 2; $colMap['tipo'] = 3; $colMap['marca'] = 4; $colMap['existencias'] = 5; $colMap['costo'] = 6; $colMap['precio'] = 7;
                    } elseif ($numCols >= 9) {
                        $col0Val = normalizarTextoUTF8($primeraFilaData[0] ?? '');
                        if (strpos($col0Val, 'bodega') !== false) {
                            $colMap['bodega'] = 0; $colMap['codigo'] = 1; $colMap['producto'] = 2; $colMap['categoria'] = 3; $colMap['marca'] = 4; $colMap['existencias'] = 5; $colMap['costo'] = 6; $colMap['precio'] = 8;
                        } else {
                            $colMap['codigo'] = 0; $colMap['producto'] = 1; $colMap['categoria'] = 2; $colMap['tipo'] = 3; $colMap['marca'] = 4; $colMap['existencias'] = 5; $colMap['costo'] = 6; $colMap['precio'] = 8;
                        }
                    } else {
                        $colMap['codigo'] = 0; $colMap['producto'] = 1;
                    }
                }

                $sql = "INSERT INTO productos_sistema 
                        (evento_id, bodega, codigo_producto, producto, categoria, tipo, marca, existencias_sistema, costo_promedio, precio_producto)
                        VALUES (:evento_id, :bodega, :codigo, :producto, :cat, :tipo, :marca, :existencias, :costo, :precio)
                        ON DUPLICATE KEY UPDATE
                        bodega = VALUES(bodega),
                        producto = VALUES(producto),
                        existencias_sistema = VALUES(existencias_sistema)";
                
                $stmt = $pdo->prepare($sql);

                foreach ($lineas as $idx => $linea) {
                    if ($idx === $filaEncabezadoIdx) continue; // Saltar la fila de encabezados

                    $lineaClean = removerBOM(trim($linea));
                    if (empty($lineaClean) || strpos(strtolower($lineaClean), 'sep=') === 0) continue;

                    $datos = str_getcsv($lineaClean, $delimitador);
                    if (count($datos) < 2) continue;

                    $codigoRaw = trim($datos[$colMap['codigo']] ?? '');
                    $codigo = trim(strip_tags(html_entity_decode(removerBOM($codigoRaw), ENT_QUOTES, 'UTF-8')));
                    $codigo = trim(preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $codigo));
                    $codigoLower = normalizarTextoUTF8($codigo);

                    // Omitir filas vacías, encabezados redundantes o metadatos de título
                    if (
                        empty($codigo) ||
                        preg_match('/^(c[oó]digo|sku|item|barras|cod_prod|c[oó]digo_producto|c[oó]digo del producto|toma de inventario|vargas|informe|puente)$/u', $codigoLower) ||
                        strpos($codigoLower, 'vargas y compa') !== false ||
                        strpos($codigoLower, 'informe de existencias') !== false ||
                        strpos($codigoLower, 'toma de inventario') !== false ||
                        strpos($codigoLower, 'puente la reynaga') !== false ||
                        strpos($codigoLower, 'j051000') !== false
                    ) {
                        continue;
                    }

                    $bodegaIdx    = $colMap['bodega'];
                    $bodegaRaw    = ($bodegaIdx !== null && isset($datos[$bodegaIdx])) ? trim($datos[$bodegaIdx]) : '';
                    $bodega       = trim(strip_tags(html_entity_decode(removerBOM($bodegaRaw), ENT_QUOTES, 'UTF-8')));
                    if (empty($bodega)) {
                        $bodega = $eventoActual['bodega_sucursal'] ?? 'Bodega Principal';
                    }

                    $productoRaw  = trim($datos[$colMap['producto']] ?? 'Sin Descripción');
                    $producto     = trim(strip_tags(html_entity_decode(removerBOM($productoRaw), ENT_QUOTES, 'UTF-8')));
                    
                    $catIdx       = $colMap['categoria'];
                    $categoria    = ($catIdx !== null && isset($datos[$catIdx])) ? trim(strip_tags(html_entity_decode($datos[$catIdx], ENT_QUOTES, 'UTF-8'))) : 'General';
                    
                    $tipoIdx      = $colMap['tipo'];
                    $tipo         = ($tipoIdx !== null && isset($datos[$tipoIdx])) ? trim(strip_tags(html_entity_decode($datos[$tipoIdx], ENT_QUOTES, 'UTF-8'))) : 'Artículo';
                    
                    $marcaIdx     = $colMap['marca'];
                    $marca        = ($marcaIdx !== null && isset($datos[$marcaIdx])) ? trim(strip_tags(html_entity_decode($datos[$marcaIdx], ENT_QUOTES, 'UTF-8'))) : 'Sin Marca';

                    $exisIdx      = $colMap['existencias'];
                    $existencias  = ($exisIdx !== null && isset($datos[$exisIdx])) ? limpiarNumero($datos[$exisIdx]) : 0.0;

                    $costoIdx     = $colMap['costo'];
                    $costo        = ($costoIdx !== null && isset($datos[$costoIdx])) ? limpiarNumero($datos[$costoIdx]) : 0.0;

                    $precioIdx    = $colMap['precio'];
                    $precio       = ($precioIdx !== null && isset($datos[$precioIdx])) ? limpiarNumero($datos[$precioIdx]) : 0.0;

                    $stmt->execute([
                        ':evento_id'   => $eventoId,
                        ':bodega'      => $bodega,
                        ':codigo'      => $codigo,
                        ':producto'    => $producto,
                        ':cat'         => $categoria,
                        ':tipo'        => $tipo,
                        ':marca'       => $marca,
                        ':existencias' => $existencias,
                        ':costo'       => $costo,
                        ':precio'      => $precio
                    ]);

                    if ($stmt->rowCount() == 1) {
                        $insertados++;
                    } elseif ($stmt->rowCount() == 2) {
                        $actualizados++;
                    } else {
                        $insertados++;
                    }
                    $totalProcesados++;
                }

                $pdo->commit();

                $_SESSION['flash_success'] = "¡Carga Exitosa para <b>" . htmlspecialchars($eventoActual['nombre_evento']) . "</b>! Se procesaron <b>{$totalProcesados}</b> productos correctamente en el sistema. <a href='eventos.php#seccionCatalogoProductos' class='btn btn-light btn-sm fw-bold border ms-2'><i class='bi bi-boxes me-1 text-primary'></i>Ver Catálogo Cargado en Auditorías</a>";

            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error crítico durante la importación: " . $e->getMessage();
            }
        }
    }
}

// Obtener métricas y catálogo de productos cargados en esta auditoría
$totalProductosDb = 0;
$totalExistenciasTeoricas = 0;
$productosCargados = [];

try {
    $pdo = getPDOConnection();
    if ($eventoActual) {
        // Limpiar basura anterior que contenga entidades HTML
        try {
            $pdo->exec("DELETE FROM productos_sistema WHERE evento_id = " . intval($eventoActual['id']) . " AND (codigo_producto LIKE '%<%' OR codigo_producto LIKE '%>%' OR codigo_producto LIKE '%&%' OR codigo_producto LIKE '%;%')");
        } catch (Exception $ex) {}

        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM productos_sistema WHERE evento_id = :ev");
        $stmtP->execute([':ev' => $eventoActual['id']]);
        $totalProductosDb = $stmtP->fetchColumn();

        $stmtS = $pdo->prepare("SELECT COALESCE(SUM(existencias_sistema), 0) FROM productos_sistema WHERE evento_id = :ev");
        $stmtS->execute([':ev' => $eventoActual['id']]);
        $totalExistenciasTeoricas = $stmtS->fetchColumn();

        // Obtener la lista completa de productos cargados
        $stmtList = $pdo->prepare("SELECT bodega, codigo_producto, producto, existencias_sistema FROM productos_sistema WHERE evento_id = :ev ORDER BY bodega ASC, producto ASC");
        $stmtList->execute([':ev' => $eventoActual['id']]);
        $productosCargados = $stmtList->fetchAll();

        // Obtener bodegas únicas para el filtro
        $stmtBod = $pdo->prepare("SELECT DISTINCT COALESCE(bodega, 'Bodega Principal') AS bodega FROM productos_sistema WHERE evento_id = :ev ORDER BY bodega ASC");
        $stmtBod->execute([':ev' => $eventoActual['id']]);
        $bodegasUnicas = $stmtBod->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet-fill text-primary me-2"></i>Carga de Existencias Teóricas ERP</h3>
        <p class="text-muted mb-0">Asigna la plantilla base a la auditoría activa: <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna') ?></b>.</p>
    </div>
    <div class="col-md-6 text-md-end mt-3 mt-md-0">
        <a href="importar.php?action=download_sample&format=excel" class="btn btn-success btn-sm rounded-pill me-2 fw-bold shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Descargar Plantilla Excel (.xls)
        </a>
        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#modalReset">
            <i class="bi bi-trash3 me-1"></i> Reiniciar Evento
        </button>
    </div>
</div>

<!-- Métricas del Evento de Auditoría Activo -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="bi bi-boxes"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalProductosDb) ?></div>
                <div class="stat-label">SKUs en esta Auditoría</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon success">
                <i class="bi bi-calculator"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalExistenciasTeoricas, 2) ?></div>
                <div class="stat-label">Stock Teórico del ERP</div>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Formulario Carga CSV / XLS -->
    <div class="col-lg-8 mb-4">
        <div class="card card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cloud-upload me-2 text-primary"></i>Cargar Baseline de Stock a Auditoría</span>
                <span class="badge bg-primary text-white fw-bold"><i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Evento') ?></span>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="importar.php" enctype="multipart/form-data">
                    <input type="hidden" name="importar" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Método de Carga:</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="modo_importacion" id="modoArchivo" value="archivo" checked onchange="toggleModo('archivo')">
                                <label class="form-check-label fw-medium" for="modoArchivo">
                                    Subir archivo Excel (.xlsx, .xls) o CSV (.csv)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="modo_importacion" id="modoTexto" value="texto" onchange="toggleModo('texto')">
                                <label class="form-check-label fw-medium" for="modoTexto">
                                    Pegar datos en texto plano
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Bloque Archivo CSV/XLS -->
                    <div id="bloqueArchivo" class="mb-4">
                        <label for="archivo_csv" class="form-label fw-semibold">Seleccionar Archivo de Existencias ERP:</label>
                        <input type="file" class="form-control form-control-lg" id="archivo_csv" name="archivo_csv" accept=".xlsx, .xls, .csv, .txt">
                        <div class="form-text mt-2">
                            <i class="bi bi-check-circle-fill text-success me-1"></i> Compatible con archivos <b>.xlsx</b> (Excel 2007+), <b>.xls</b> (Excel 97-2003) y <b>.csv</b>. Se asignará a <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Activo') ?></b>.
                        </div>
                    </div>

                    <!-- Bloque Texto CSV -->
                    <div id="bloqueTexto" class="mb-4" style="display: none;">
                        <label for="texto_csv" class="form-label fw-semibold">Pegar Contenido de Excel / CSV:</label>
                        <textarea class="form-control font-monospace fs-7" id="texto_csv" name="texto_csv" rows="8" placeholder="codigo_producto;producto;categoria;tipo;marca;existencias_sistema;costo_promedio;precio_producto&#10;75010001;Arroz Extra Flor 1kg;Abarrotes;Granos;Verde;150.00;18.50;24.00"></textarea>
                    </div>

                    <div class="d-grid d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary px-4 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2" style="background-color: var(--primary-color);">
                            <i class="bi bi-box-arrow-in-down fs-5"></i> CARGAR INVENTARIO TEÓRICO A ESTA AUDITORÍA
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Guía de Formato -->
    <div class="col-lg-4">
        <div class="card card-custom bg-light border">
            <div class="card-header-custom">
                <i class="bi bi-info-circle-fill me-2 text-primary"></i>Información de Evento
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Soporta la lectura nativa de libros Excel (<code>.xlsx</code>) e informes del ERP sin corromper etiquetas ni caracteres especiales.
                </p>

                <div class="alert alert-info small mb-0">
                    <i class="bi bi-shield-check me-1"></i> Al crear una nueva auditoría, podrás subir un nuevo archivo de existencias sin afectar el histórico de auditorías anteriores.
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Modal Confirmación de Reinicio -->
<div class="modal fade" id="modalReset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reiniciar Datos de la Auditoría</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-3">Selecciona la limpieza para <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></b>:</p>
                <form method="POST" action="importar.php">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="action_reset" id="resetConteos" value="reset_conteos" checked>
                        <label class="form-check-label fw-bold" for="resetConteos">
                            Borrar únicamente Conteos Físicos de esta auditoría
                        </label>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="radio" name="action_reset" id="resetTodo" value="reset_productos">
                        <label class="form-check-label fw-bold text-danger" for="resetTodo">
                            Borrar Productos y Conteos de esta auditoría
                        </label>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Confirmar Limpieza</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleModo(modo) {
    if (modo === 'archivo') {
        document.getElementById('bloqueArchivo').style.display = 'block';
        document.getElementById('bloqueTexto').style.display = 'none';
    } else {
        document.getElementById('bloqueArchivo').style.display = 'none';
        document.getElementById('bloqueTexto').style.display = 'block';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
