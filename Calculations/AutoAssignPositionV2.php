//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot (v2)
 *
 * Excel import brings: location, row, column, total_rows, total_columns, boxe_number
 *
 * Flow:
 *   1. Location resolved by system to a valid box ID before reaching this calculation
 *      → store sample at manifest row/column in that box
 *   2. Location NOT resolved (no valid storage)
 *      → find or create box by boxe_number, store in temporary storage
 *   3. If the target position is occupied → find next free position in the same box
 *   4. If the box is completely full → create a new box, use the manifest position
 *
 * On update: same logic — row/column from manifest are still used
 *
 * RULE: Never assign a sample to an already occupied position
 *
 * v2 changes vs v1:
 *   - Tries several casings/spellings for the boxe_number column instead of
 *     a single hard-coded key (the v1 throw on line 373 fired because the
 *     manifest column did not match 'box_source_number' exactly).
 *   - Logs the keys present in $data right before throwing so the actual
 *     manifest column name is visible in the log.
 *   - Uses throwValidationException so the user sees a clean message
 *     instead of an "unexpected error" wrapper.
 *   - Falls back to manifest BARCODE / NAME as a box identifier of last
 *     resort before giving up.
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
                  $fields['number_rows'], $fields['number_columns']])
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
    // Already numeric — return as-is
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
 * Find an existing box by boxe_number.
 * Searches: box_number_ (field 49) → NAME → BARCODE
 */
$this->addMethod('findBoxByBoxeNumber', function (string $boxeNumber) use ($fields, $boxEntityTypeId): ?array {
    $boxeNumber = trim($boxeNumber);
    if ($boxeNumber === '') return null;

    $selectFields = [
        'id', 'entitytype_id',
        $fields['box_package_id'], $fields['name'], $fields['barcode'],
        $fields['number_rows'], $fields['number_columns'],
        $fields['shipping_status'],
    ];

    $box = eqb()
        ->select($selectFields)
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['box_package_id'], '=', $boxeNumber)
        ->first();

    if (!$box) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($fields['name'], '=', $boxeNumber)
            ->first();
    }

    if (!$box) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($fields['barcode'], '=', $boxeNumber)
            ->first();
    }

    \Log::debug('findBoxByBoxeNumber', [
        'searched' => $boxeNumber,
        'found'    => $box ? (int)$box['id'] : null,
    ]);

    return $box ?: null;
});

/**
 * Create a new box in temporary storage.
 */
$this->addMethod('createBox', function (
    string $boxeNumber,
    int    $rows,
    int    $cols
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

    \Log::warning('createBox', ['boxe_number' => $boxeNumber, 'dimensions' => "{$rows}x{$cols}"]);

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
    \Log::debug('Auto box creation v2: SKIPPED');
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

// Last-resort fallbacks: manifest BARCODE or NAME — only used if the
// dedicated column is missing entirely.
if ($boxeNumber === '') {
    $boxeNumber = $this->firstNonEmpty($data, [$fields['barcode'], $fields['name']]);
    if ($boxeNumber !== '') {
        \Log::warning('Auto box creation v2: boxe_number missing — falling back to BARCODE/NAME', [
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

\Log::info('Auto box creation v2: START', [
    'mode'             => $this->getCurrentMode(),
    'storage_id'       => $storageId,
    'storage_resolved' => $resolvedStorage !== null,
    'boxe_number'      => $boxeNumber,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    \Log::info('Auto box creation v2: CASE 1 — storage resolved by system');

    if (!$this->hasValidDimensions($resolvedStorage)) {
        $this->data[$fields['position_row']]    = null;
        $this->data[$fields['position_column']] = null;
        \Log::info('Auto box creation v2: CASE 1 — no grid, stored without position');
        return;
    }

    $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

    if ($pos) {
        $this->data[$fields['position_row']]    = $pos['row'];
        $this->data[$fields['position_column']] = $pos['column'];
        \Log::info('Auto box creation v2: CASE 1 COMPLETE', [
            'storage_id' => $resolvedStorage['id'],
            'position'   => "{$pos['row']}:{$pos['column']}",
        ]);
        return;
    }

    \Log::info('Auto box creation v2: CASE 1 — box full, falling through to create');
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage OR box was full
// ─────────────────────────────────────────────────────────────────────
if ($boxeNumber === '') {
    // Dump the keys we actually received so the missing column can be
    // identified from the log.
    \Log::error('Auto box creation v2: cannot resolve box', [
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

\Log::info('Auto box creation v2: CASE 2 — manifest-driven', ['boxe_number' => $boxeNumber]);

$existingBox = $this->findBoxByBoxeNumber($boxeNumber);

if ($existingBox) {
    $boxId = (int)$existingBox['id'];

    if (!$resolvedStorage || $boxId !== (int)$resolvedStorage['id']) {
        $pos = $this->resolvePosition($existingBox, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['storage']]         = $boxId;
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v2: CASE 2 — placed in existing box', [
                'box_id'   => $boxId,
                'position' => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v2: CASE 2 — existing box also full', ['box_id' => $boxId]);
    }
}

// Step B: Create new box → sample gets exact manifest position (empty box)
$newBoxId = $this->createBox($boxeNumber, $totalRows, $totalCols);

if (!$newBoxId) {
    throwValidationException(["Failed to auto-create box '{$boxeNumber}'."]);
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$this->sendBoxCreatedNotification($newBoxId, $boxeNumber);

\Log::info('Auto box creation v2: CASE 2 — created new box', [
    'box_id'   => $newBoxId,
    'position' => "{$wantedRow}:{$wantedCol}",
]);
