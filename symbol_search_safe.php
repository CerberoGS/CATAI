<?php
// /bolsa/api/symbol_search_safe.php
// Búsqueda de símbolos con autocompletado

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
json_header();

try {
    $query = trim($_GET['q'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 10), 50);

    if (strlen($query) < 2) {
        json_out([
            'ok' => true,
            'results' => []
        ]);
    }

    // Cargar universo de símbolos
    $universePath = __DIR__ . '/../data/universe.json';
    if (!file_exists($universePath)) {
        json_error('universe_not_found', 404, 'Archivo de universo no encontrado');
    }

    $universe = json_decode(file_get_contents($universePath), true) ?? [];
    $queryUpper = strtoupper($query);

    // Filtrar símbolos
    $results = array_filter($universe, function($item) use ($queryUpper) {
        return strpos($item['symbol'], $queryUpper) === 0 || 
               stripos($item['name'], $queryUpper) !== false;
    });

    // Ordenar por relevancia (símbolo exacto primero, luego por nombre)
    usort($results, function($a, $b) use ($queryUpper) {
        $aSymbol = $a['symbol'];
        $bSymbol = $b['symbol'];
        
        // Priorizar coincidencias exactas en símbolo
        if ($aSymbol === $queryUpper && $bSymbol !== $queryUpper) return -1;
        if ($bSymbol === $queryUpper && $aSymbol !== $queryUpper) return 1;
        
        // Luego por coincidencia al inicio del símbolo
        $aStartsWith = strpos($aSymbol, $queryUpper) === 0;
        $bStartsWith = strpos($bSymbol, $queryUpper) === 0;
        
        if ($aStartsWith && !$bStartsWith) return -1;
        if ($bStartsWith && !$aStartsWith) return 1;
        
        // Finalmente alfabéticamente
        return strcmp($aSymbol, $bSymbol);
    });

    // Limitar resultados
    $results = array_slice($results, 0, $limit);

    json_out([
        'ok' => true,
        'query' => $query,
        'results' => array_values($results)
    ]);

} catch (Throwable $e) {
    json_error('server_error', 500, 'Error en búsqueda de símbolos');
}
