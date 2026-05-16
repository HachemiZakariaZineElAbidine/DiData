//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot (v3)
 *
 * Excel import brings: location, row, column, total_rows, total_columns,
 * boxe_number, country_source.
 *
 * Flow:
 *   1. Location resolved by system to a valid box ID before reaching this calculation
 *      → if the resolved box's country_source matches the sample (or either side is empty),
 *        store sample at manifest row/column in that box.
 *      → if it conflicts, fall through to CASE 2 (country-aware lookup/create).
 *   2. Location NOT resolved (or country conflict)
 *      → find a box by boxe_number + country_source (soft preference: a country-matching
 *        box wins; otherwise any box with the right boxe_number is reused).
 *      → if none found, create a new box and inherit the sample's country_source.
 *   3. If the target position is occupied → find next free position in the same box.
 *   4. If the box is completely full → create a new box (carrying country_source).
 *
 * On update: same logic — row/column from manifest are still used.
 *
 * RULE: Never assign a sample to an already occupied position.
 *
 * v3 changes vs v2:
 *   - country_source is now considered when matching a sample to a box.
 *     It is a soft preference: a box with the same country_source as the sample
 *     is preferred, but a box with the same boxe_number and no/different
 *     country_source is still reused as a fallback.
 *   - CASE 1 now validates that the system-resolved storage has a compatible
 *     country_source. On conflict it falls through to CASE 2 instead of mixing
 *     samples from different countries into the same box.
 *   - findBoxByBoxeNumber accepts a country_source hint and returns a
 *     country-matching box first, then any matching box as a fallback.
 *   - New boxes created here inherit the sample's country_source.
 */

use App\Common;
use App\DidataPackages\DidataCache;

// ─────────────────────────────────────────────
// Field names — single source of truth
// ─────────────────────────────────────────────

$fields = [
    'name'              => 'NAME',
    'number_rows'       => Common::STORAGE_2D_NB_ROWS_FIELD_NAME,
    'number_columns'    => Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME,
    'barcode'           => 'BARCODE',
    'storage'           => Common::ENTITY_FK_STORAGE_FIELD_NAME,
    'position_row'      => Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME,
    'position_column'   => Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME,
    'id'                => Common::ID_FIELD_NAME,
    'box_package_id'    => 'box_number_',
    'shipping_status'   => 'Shipping_Status',
    'manifest_row'      => 'Row',
    'manifest_column'   => 'Column',
    'boxe_number'       => 'box_source_number',
    'country_source'    => 'country_source',
];

// Candidate keys we will try when reading the boxe_number from the manifest.
// Order matters — first non-empty match wins.
$boxeNumberKeys = [
    'box_source_number',
    'Box_Source_Number',
    'BOX_SOURCE_NUMBER',
    'box_source_Number',
    'boxe_number',
    'Boxe_Number',
    'BOXE_NUMBER',
    'box_number',
    'Box_Number',
    'BOX_NUMBER',
];

// Candidate keys for country_source — same defensive pattern.
$countrySourceKeys = [
    'country_source',
    'Country_Source',
    'COUNTRY_SOURCE',
    'country',
    'Country',
    'COUNTRY',
];

// ─────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────

$boxEntityTypeId    = 7;
$sampleEntityTypeId = 3;
$defaultBoxRows     = 9;
$defaultBoxColumns  = 9;
$notificationOperationId    = 20;
$shippingStatusShippedToUSA = 24;
$temporaryStorageId         = 45859;

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

$this->addMethod('isValidPositiveInt', function ($value): bool {
    return ctype_digit((string)$value) && (int)$value > 0;
});

$this->addMethod('getStorageEntityTypeIds', function (): array {
    $ctx = DidataCache::getContextByName(Common::STORAGE_CONTEXT_NAME);
    return array_map('intval', array_column($ctx['_entity_types'] ?? [], 'id'));
});

$this->addMethod('getStorageById', function ($id) use ($fields): ?array {
    if (!$this->isValidPositiveInt($id)) return null;
    $storage = eqb()
        ->select(['id', 'entitytype_id',
                  $fields['number_rows'], $fields['number_columns'],
                  $fields['country_source']])
        ->where($fields['id'], '=', (int)$id)
        ->whereIn('entitytype_id', $this->getStorageEntityTypeIds())
        ->first();
    return $storage ?: null;
});

$this->addMethod('hasValidDimensions', function (?array $storage) use ($fields): bool {
    if (is_null($storage)) return false;
    return isset($storage[$fields['number_rows']], $storage[$fields['number_columns']])
        && $this->isValidPositiveInt($storage[$fields['number_rows']])
        && $this->isValidPositiveInt($storage[$fields['number_columns']]);
});

$this->addMethod('manifestRowToInt', function ($alphaRow): int {
    $alphaRow = trim((string)$alphaRow);
    if ($alphaRow === '') return 1;
    if (ctype_digit($alphaRow)) return (int)$alphaRow;
    return ord(strtolower($alphaRow)) - ord('a') + 1;
});

/**
 * Pick the first non-empty value across a list of candidate keys.
 */
$this->addMethod('firstNonEmpty', function (array $data, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $val = trim((string)$data[$key]);
            if ($val !== '') return $val;
        }
    }
    return '';
});

/**
 * Compare two country_source values. Empty strings are treated as a wildcard
 * (compatible with anything), so we only flag a real mismatch when both sides
 * are populated and differ. Comparison is case-insensitive after trimming.
 */
$this->addMethod('countryMatches', function ($a, $b): bool {
    $a = strtolower(trim((string)$a));
    $b = strtolower(trim((string)$b));
    if ($a === '' || $b === '') return true;
    return $a === $b;
});

/**
 * Check if a specific position is occupied.
 * Same query pattern as the platform validation rule.
 */
$this->addMethod('isPositionOccupied', function (int $storageId, int $row, int $col) use ($fields): bool {
    $entity = eqb()
        ->where($fields['storage'], '=', $storageId)
        ->where($fields['position_row'], '=', $row)
        ->where($fields['position_column'], '=', $col)
        ->first('id');

    return $entity !== null && $entity !== false;
});

/**
 * Find next free position scanning row-major from [1,1].
 */
$this->addMethod('findNextFreePosition', function (array $storage) use ($fields): ?array {
    if (!$this->hasValidDimensions($storage)) return null;

    $rows      = (int)$storage[$fields['number_rows']];
    $cols      = (int)$storage[$fields['number_columns']];
    $storageId = (int)$storage['id'];

    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            if (!$this->isPositionOccupied($storageId, $r, $c)) {
                return ['row' => $r, 'column' => $c];
            }
        }
    }
    return null;
});

/**
 * Try to place at a specific position. If occupied, find next free.
 * Returns the position to use, or null if box is completely full.
 */
$this->addMethod('resolvePosition', function (array $storage, int $wantedRow, int $wantedCol) use ($fields): ?array {
    $storageId = (int)$storage['id'];

    if (!$this->isPositionOccupied($storageId, $wantedRow, $wantedCol)) {
        \Log::info('resolvePosition: wanted position is free', [
            'storage_id' => $storageId,
            'position'   => "{$wantedRow}:{$wantedCol}",
        ]);
        return ['row' => $wantedRow, 'column' => $wantedCol];
    }

    \Log::info('resolvePosition: position occupied, scanning for next free', [
        'storage_id' => $storageId,
        'occupied'   => "{$wantedRow}:{$wantedCol}",
    ]);

    return $this->findNextFreePosition($storage);
});

// ─────────────────────────────────────────────
// Box lookup / creation
// ─────────────────────────────────────────────

/**
 * Find an existing box by boxe_number, preferring one that matches
 * the sample's country_source.
 *
 * Soft preference:
 *   1. Try (box_number_ + country_source) — exact match.
 *   2. Try (NAME + country_source).
 *   3. Try (BARCODE + country_source).
 *   4. Fall back to the country-agnostic v2 lookup: box_number_ → NAME → BARCODE.
 *
 * Searches box_number_ (field 49) → NAME → BARCODE at each step.
 */
$this->addMethod('findBoxByBoxeNumber', function (string $boxeNumber, string $countrySource = '')
    use ($fields, $boxEntityTypeId): ?array
{
    $boxeNumber = trim($boxeNumber);
    if ($boxeNumber === '') return null;

    $selectFields = [
        'id', 'entitytype_id',
        $fields['box_package_id'], $fields['name'], $fields['barcode'],
        $fields['number_rows'], $fields['number_columns'],
        $fields['shipping_status'],
        $fields['country_source'],
    ];

    $identifierFields = [
        $fields['box_package_id'],
        $fields['name'],
        $fields['barcode'],
    ];

    // Pass 1 — country-matched (only if a country_source is provided).
    if ($countrySource !== '') {
        foreach ($identifierFields as $idField) {
            $box = eqb()
                ->select($selectFields)
                ->where('entitytype_id', '=', $boxEntityTypeId)
                ->where($idField, '=', $boxeNumber)
                ->where($fields['country_source'], '=', $countrySource)
                ->first();
            if ($box) {
                \Log::debug('findBoxByBoxeNumber: country-matched', [
                    'searched'       => $boxeNumber,
                    'country_source' => $countrySource,
                    'matched_on'     => $idField,
                    'box_id'         => (int)$box['id'],
                ]);
                return $box;
            }
        }
    }

    // Pass 2 — country-agnostic fallback (v2 behavior).
    foreach ($identifierFields as $idField) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($idField, '=', $boxeNumber)
            ->first();
        if ($box) {
            \Log::debug('findBoxByBoxeNumber: country-agnostic fallback', [
                'searched'         => $boxeNumber,
                'requested_country'=> $countrySource,
                'box_country'      => $box[$fields['country_source']] ?? null,
                'matched_on'       => $idField,
                'box_id'           => (int)$box['id'],
            ]);
            return $box;
        }
    }

    \Log::debug('findBoxByBoxeNumber: not found', [
        'searched'       => $boxeNumber,
        'country_source' => $countrySource,
    ]);

    return null;
});

/**
 * Create a new box in temporary storage.
 * The box inherits the sample's country_source so future lookups can match it.
 */
$this->addMethod('createBox', function (
    string $boxeNumber,
    int    $rows,
    int    $cols,
    string $countrySource = ''
) use ($fields, $boxEntityTypeId, $shippingStatusShippedToUSA, $temporaryStorageId): ?int {

    $boxeNumber = trim($boxeNumber);

    $boxData = [
        'entitytype_id'              => $boxEntityTypeId,
        $fields['box_package_id']    => $boxeNumber,
        $fields['name']              => $boxeNumber,
        $fields['barcode']           => $boxeNumber,
        $fields['number_rows']       => $rows,
        $fields['number_columns']    => $cols,
        $fields['storage']           => $temporaryStorageId,
        $fields['shipping_status']   => $shippingStatusShippedToUSA,
    ];

    if ($countrySource !== '') {
        $boxData[$fields['country_source']] = $countrySource;
    }

    \Log::warning('createBox', [
        'boxe_number'    => $boxeNumber,
        'dimensions'     => "{$rows}x{$cols}",
        'country_source' => $countrySource,
    ]);

    try {
        $repo = dac()->DBrepo('entity');
        $repo->setOptions(['with_calculation' => false, 'with_validation' => true]);
        $created  = $repo->create(dac()->getContext()->getProject()->id, $boxData);
        $newBoxId = is_object($created) ? $created->id : ($created['id'] ?? null);

        if (!$newBoxId) {
            \Log::error('createBox: no ID returned');
            return null;
        }

        \Log::info('createBox: SUCCESS', ['box_id' => $newBoxId]);
        return (int)$newBoxId;

    } catch (\Exception $e) {
        \Log::error('createBox: FAILED', ['error' => $e->getMessage()]);
        return null;
    }
});

// ─────────────────────────────────────────────
// Notification
// ─────────────────────────────────────────────

$this->addMethod('sendBoxCreatedNotification', function (int $boxId, string $boxName) use ($notificationOperationId): void {
    $user   = getCurrentUser();
    $userId = $user['id'] ?? null;
    if (!$userId) return;

    $payload = [
        'title'       => "New Box Auto-Created: \"{$boxName}\"",
        'content'     => "Box #{$boxId} (\"{$boxName}\") was automatically created in temporary storage.\nPlease assign a final storage location once the shipment is received.",
        'php_script'  => null,
        'channels'    => ['in-app'],
        'user_ids'    => [$userId],
        'resource_id' => $boxId,
    ];

    try {
        $operation = \App\Models\Actions\Operation::find($notificationOperationId);
        if (!$operation) return;
        consumeService(new \App\Services\Actions\OperationsRunnerService($operation, $payload));
    } catch (\Exception $e) {
        \Log::error('sendBoxCreatedNotification: failed', ['error' => $e->getMessage()]);
    }
});

// ─────────────────────────────────────────────
// Should this calculation run?
// ─────────────────────────────────────────────

$this->addMethod('shouldSkip', function () use ($fields, $sampleEntityTypeId): bool {
    $rawData      = $this->data;
    $entityTypeId = (int)($rawData['entitytype_id'] ?? 0);

    if ($entityTypeId !== $sampleEntityTypeId) return true;

    if ($this->getCurrentMode() === 'create') return false;

    $oldData    = $this->getOldData();
    $oldStorage = $oldData[$fields['storage']] ?? null;
    $newStorage = $rawData[$fields['storage']] ?? null;

    if (array_key_exists($fields['storage'], $rawData) && $newStorage != $oldStorage) {
        if (empty($newStorage) && !empty($oldStorage)) return true;
        return false;
    }

    if (!empty($oldStorage)) return true;

    return false;
});

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  MAIN EXECUTION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

if ($this->shouldSkip()) {
    \Log::debug('Auto box creation v3: SKIPPED');
    return;
}

$data = $this->data;
if ($this->getCurrentMode() !== 'create') {
    $data = array_merge($this->getOldData(), $this->data);
}

// ── Extract manifest values ──────────────────────────────────────────

$storageId  = $data[$fields['storage']] ?? null;

// Try every spelling/casing for the boxe_number column.
$boxeNumber = $this->firstNonEmpty($data, $boxeNumberKeys);

// Try every spelling/casing for the country_source column.
$countrySource = $this->firstNonEmpty($data, $countrySourceKeys);

// Last-resort fallbacks: manifest BARCODE or NAME — only used if the
// dedicated column is missing entirely.
if ($boxeNumber === '') {
    $boxeNumber = $this->firstNonEmpty($data, [$fields['barcode'], $fields['name']]);
    if ($boxeNumber !== '') {
        \Log::warning('Auto box creation v3: boxe_number missing — falling back to BARCODE/NAME', [
            'fallback_value' => $boxeNumber,
        ]);
    }
}

$totalRows = (int)($data['total_rows']    ?? $data['Total_Rows']    ?? $defaultBoxRows);
$totalCols = (int)($data['total_columns'] ?? $data['Total_Columns'] ?? $defaultBoxColumns);
if ($totalRows <= 0) $totalRows = $defaultBoxRows;
if ($totalCols <= 0) $totalCols = $defaultBoxColumns;

$alphaRow  = trim((string)($data['row'] ?? $data[$fields['manifest_row']] ?? 'a'));
$wantedRow = $this->manifestRowToInt($alphaRow);
$wantedCol = (int)($data['column'] ?? $data[$fields['manifest_column']] ?? 1);
if ($wantedRow <= 0) $wantedRow = 1;
if ($wantedCol <= 0) $wantedCol = 1;

$resolvedStorage = $this->getStorageById($storageId);

\Log::info('Auto box creation v3: START', [
    'mode'             => $this->getCurrentMode(),
    'storage_id'       => $storageId,
    'storage_resolved' => $resolvedStorage !== null,
    'boxe_number'      => $boxeNumber,
    'country_source'   => $countrySource,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box
//         → store at manifest row/column IF country_source is compatible.
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    $resolvedCountry = (string)($resolvedStorage[$fields['country_source']] ?? '');
    $countryOk       = $this->countryMatches($countrySource, $resolvedCountry);

    if (!$countryOk) {
        \Log::info('Auto box creation v3: CASE 1 — country mismatch, falling through', [
            'sample_country' => $countrySource,
            'box_country'    => $resolvedCountry,
            'storage_id'     => $resolvedStorage['id'],
        ]);
        // Force CASE 2 to look for a country-matching box.
        $resolvedStorage = null;
    } else {
        \Log::info('Auto box creation v3: CASE 1 — storage resolved by system', [
            'box_country' => $resolvedCountry,
        ]);

        if (!$this->hasValidDimensions($resolvedStorage)) {
            $this->data[$fields['position_row']]    = null;
            $this->data[$fields['position_column']] = null;
            \Log::info('Auto box creation v3: CASE 1 — no grid, stored without position');
            return;
        }

        $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v3: CASE 1 COMPLETE', [
                'storage_id' => $resolvedStorage['id'],
                'position'   => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v3: CASE 1 — box full, falling through to create');
    }
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage, country mismatch, or box was full
//         → find or create box by boxe_number (preferring country_source match).
// ─────────────────────────────────────────────────────────────────────
if ($boxeNumber === '') {
    \Log::error('Auto box creation v3: cannot resolve box', [
        'reason'             => 'No valid storage and no boxe_number-like column in manifest',
        'storage_id_in_data' => $storageId,
        'tried_keys'         => $boxeNumberKeys,
        'available_keys'     => array_keys($data),
    ]);
    throwValidationException([
        "No valid storage and no boxe_number in manifest. Cannot assign box. "
        . "Please make sure the import file has a 'box_source_number' column "
        . "(or a STORAGE column that resolves to an existing box).",
    ]);
}

\Log::info('Auto box creation v3: CASE 2 — manifest-driven', [
    'boxe_number'    => $boxeNumber,
    'country_source' => $countrySource,
]);

$existingBox = $this->findBoxByBoxeNumber($boxeNumber, $countrySource);

if ($existingBox) {
    $boxId = (int)$existingBox['id'];

    if (!$resolvedStorage || $boxId !== (int)$resolvedStorage['id']) {
        $pos = $this->resolvePosition($existingBox, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['storage']]         = $boxId;
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v3: CASE 2 — placed in existing box', [
                'box_id'      => $boxId,
                'box_country' => $existingBox[$fields['country_source']] ?? null,
                'position'    => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v3: CASE 2 — existing box also full', ['box_id' => $boxId]);
    }
}

// Step B: Create new box → sample gets exact manifest position (empty box).
//         New box inherits the sample's country_source.
$newBoxId = $this->createBox($boxeNumber, $totalRows, $totalCols, $countrySource);

if (!$newBoxId) {
    throwValidationException(["Failed to auto-create box '{$boxeNumber}'."]);
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$this->sendBoxCreatedNotification($newBoxId, $boxeNumber);

\Log::info('Auto box creation v3: CASE 2 — created new box', [
    'box_id'         => $newBoxId,
    'position'       => "{$wantedRow}:{$wantedCol}",
    'country_source' => $countrySource,
]);