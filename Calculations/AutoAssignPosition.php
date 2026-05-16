//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot
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

$this->addMethod('manifestRowToInt', function (string $alphaRow): int {
    return ord(strtolower(trim($alphaRow))) - ord('a') + 1;
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

    // Try the wanted position first
    if (!$this->isPositionOccupied($storageId, $wantedRow, $wantedCol)) {
        \Log::info('resolvePosition: wanted position is free', [
            'storage_id' => $storageId,
            'position'   => "{$wantedRow}:{$wantedCol}",
        ]);
        return ['row' => $wantedRow, 'column' => $wantedCol];
    }

    // Occupied → find next free from [1,1]
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

    // Try box_number_ field first
    $box = eqb()
        ->select($selectFields)
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['box_package_id'], '=', $boxeNumber)
        ->first();

    // Fallback: NAME
    if (!$box) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($fields['name'], '=', $boxeNumber)
            ->first();
    }

    // Fallback: BARCODE
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

    \Log::info('createBox', ['boxe_number' => $boxeNumber, 'dimensions' => "{$rows}x{$cols}"]);

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

    // Update mode
    $oldData    = $this->getOldData();
    $oldStorage = $oldData[$fields['storage']] ?? null;
    $newStorage = $rawData[$fields['storage']] ?? null;

    // Storage changing → process
    if (array_key_exists($fields['storage'], $rawData) && $newStorage != $oldStorage) {
        if (empty($newStorage) && !empty($oldStorage)) return true; // clearing
        return false;
    }

    // Already stored, not changing → skip
    if (!empty($oldStorage)) return true;

    // No storage → process
    return false;
});

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  MAIN EXECUTION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

if ($this->shouldSkip()) {
    \Log::debug('Auto box creation: SKIPPED');
    return;
}

// Merge old + new so we always have the full picture
$data = $this->data;
if ($this->getCurrentMode() !== 'create') {
    $data = array_merge($this->getOldData(), $this->data);
}

// ── Extract manifest values ──────────────────────────────────────────

$storageId   = $data[$fields['storage']] ?? null;
$boxeNumber  = trim((string)($data[$fields['boxe_number']] ?? ''));
$totalRows   = (int)($data['total_rows']    ?? $defaultBoxRows);
$totalCols   = (int)($data['total_columns'] ?? $defaultBoxColumns);

$alphaRow  = trim((string)($data['row'] ?? $data[$fields['manifest_row']] ?? 'a'));
$wantedRow = $this->manifestRowToInt($alphaRow);
$wantedCol = (int)($data['column'] ?? $data[$fields['manifest_column']] ?? 1);

$resolvedStorage = $this->getStorageById($storageId);

\Log::info('Auto box creation: START', [
    'mode'             => $this->getCurrentMode(),
    'storage_id'       => $storageId,
    'storage_resolved' => $resolvedStorage !== null,
    'boxe_number'      => $boxeNumber,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box
//         → store at manifest row/column (or next free if occupied)
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    \Log::info('Auto box creation: CASE 1 — storage resolved by system');

    if (!$this->hasValidDimensions($resolvedStorage)) {
        $this->data[$fields['position_row']]    = null;
        $this->data[$fields['position_column']] = null;
        \Log::info('Auto box creation: CASE 1 — no grid, stored without position');
        return;
    }

    $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

    if ($pos) {
        $this->data[$fields['position_row']]    = $pos['row'];
        $this->data[$fields['position_column']] = $pos['column'];
        \Log::info('Auto box creation: CASE 1 COMPLETE', [
            'storage_id' => $resolvedStorage['id'],
            'position'   => "{$pos['row']}:{$pos['column']}",
        ]);
        return;
    }

    // Box is full — fall through to CASE 2
    \Log::info('Auto box creation: CASE 1 — box full, falling through to create');
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage OR box was full
//         → find or create box by boxe_number
// ─────────────────────────────────────────────────────────────────────
if ($boxeNumber === '') {
    throw new \Exception('No valid storage and no boxe_number in manifest. Cannot assign box.');
}

\Log::info('Auto box creation: CASE 2 — manifest-driven', ['boxe_number' => $boxeNumber]);

// Step A: Find existing box by boxe_number
$existingBox = $this->findBoxByBoxeNumber($boxeNumber);

if ($existingBox) {
    $boxId = (int)$existingBox['id'];

    // Skip if this is the same box we already tried in CASE 1
    if (!$resolvedStorage || $boxId !== (int)$resolvedStorage['id']) {
        $pos = $this->resolvePosition($existingBox, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['storage']]         = $boxId;
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation: CASE 2 — placed in existing box', [
                'box_id'   => $boxId,
                'position' => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation: CASE 2 — existing box also full', ['box_id' => $boxId]);
    }
}

// Step B: Create new box → sample gets exact manifest position (empty box)
$newBoxId = $this->createBox($boxeNumber, $totalRows, $totalCols);

if (!$newBoxId) {
    throw new \Exception("Failed to auto-create box '{$boxeNumber}'.");
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$this->sendBoxCreatedNotification($newBoxId, $boxeNumber);

\Log::info('Auto box creation: CASE 2 — created new box', [
    'box_id'   => $newBoxId,
    'position' => "{$wantedRow}:{$wantedCol}",
]);