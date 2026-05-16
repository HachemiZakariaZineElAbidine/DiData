//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot (v4)
 *
 * Excel import brings: location, row, column, total_rows, total_columns,
 *                      boxe_number, country_source
 *
 * Flow:
 *   1. Location resolved by system to a valid box ID before reaching this calculation
 *      → if box accepts the sample's country, store at manifest row/column there
 *   2. Location NOT resolved (no valid storage)
 *      → find or create a box that accepts this country, by boxe_number
 *   3. If the target position is occupied → find next free position in the same box
 *   4. If the box is completely full → create a new box, use the manifest position
 *
 * Country matching (strict):
 *   - `country_source` lives ONLY on the Sample entity (NOT on Box).
 *   - A box's "country" is inferred from the country_source of samples already inside it:
 *       • Empty box           → accepts any country
 *       • One country inside  → only that country
 *       • Mixed (shouldn't happen) → rejects new samples
 *   - The country is encoded into the new box NAME for traceability only
 *     (e.g. "USA-BOX123"). Never read/queried on the box entity.
 *
 * On update: same logic — row/column from manifest are still used.
 *
 * RULE: Never assign a sample to an already occupied position.
 *
 * v4 changes vs v2:
 *   - Adds strict country_source matching (sample-only field — never touches the box schema).
 *   - findBoxByBoxeNumber now filters reuse by boxAcceptsCountry.
 *   - createBox encodes the country into the NAME prefix (e.g. "USA-...").
 *   - Only assigns 3 fields back to $this->data — like v2 — so no risk of
 *     re-introducing "Field X does not exist" errors.
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

// Candidate keys for the boxe_number column (manifest casings).
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

// Candidate keys for the country_source column (manifest casings).
$countrySourceKeys = [
    'country_source',
    'Country_Source',
    'COUNTRY_SOURCE',
    'country',
    'Country',
    'COUNTRY',
];

// Known valid countries for the pilot — used only for normalization /
// validation of the inbound value, NOT to constrain box reuse.
$knownCountries = ['bolivia', 'usa', 'peru', 'colombia'];

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
    if (ctype_digit($alphaRow)) return (int)$alphaRow;
    return ord(strtolower($alphaRow)) - ord('a') + 1;
});

$this->addMethod('firstNonEmpty', function (array $data, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $val = trim((string)$data[$key]);
            if ($val !== '') return $val;
        }
    }
    return '';
});

$this->addMethod('isPositionOccupied', function (int $storageId, int $row, int $col) use ($fields): bool {
    $entity = eqb()
        ->where($fields['storage'], '=', $storageId)
        ->where($fields['position_row'], '=', $row)
        ->where($fields['position_column'], '=', $col)
        ->first('id');

    return $entity !== null && $entity !== false;
});

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
// Country matching — sample-only, inferred from box contents
// ─────────────────────────────────────────────

/**
 * Return the distinct country_source values of samples currently stored in
 * the given box. Lowercased + trimmed. Empty array if the box is empty.
 */
$this->addMethod('getBoxSampleCountries', function (int $boxId) use ($fields, $sampleEntityTypeId): array {
    $rows = eqb()
        ->select([$fields['country_source']])
        ->where('entitytype_id', '=', $sampleEntityTypeId)
        ->where($fields['storage'], '=', $boxId)
        ->get();

    $countries = [];
    foreach ($rows as $row) {
        $c = strtolower(trim((string)($row[$fields['country_source']] ?? '')));
        if ($c !== '') $countries[$c] = true;
    }
    return array_keys($countries);
});

/**
 * A box accepts a country if:
 *   - it is empty, OR
 *   - all of its existing samples share that single country.
 */
$this->addMethod('boxAcceptsCountry', function (int $boxId, string $countrySource): bool {
    $countrySource = strtolower(trim($countrySource));
    if ($countrySource === '') return false;

    $countries = $this->getBoxSampleCountries($boxId);
    if (empty($countries)) return true;
    return count($countries) === 1 && $countries[0] === $countrySource;
});

// ─────────────────────────────────────────────
// Box lookup / creation
// ─────────────────────────────────────────────

/**
 * Find an existing box by boxe_number AND country compatibility.
 * Searches: box_number_ → NAME → BARCODE, then filters by boxAcceptsCountry.
 */
$this->addMethod('findBoxByBoxeNumber', function (string $boxeNumber, string $countrySource) use ($fields, $boxEntityTypeId): ?array {
    $boxeNumber = trim($boxeNumber);
    if ($boxeNumber === '') return null;

    $selectFields = [
        'id', 'entitytype_id',
        $fields['box_package_id'], $fields['name'], $fields['barcode'],
        $fields['number_rows'], $fields['number_columns'],
        $fields['shipping_status'],
    ];

    $candidates = [];

    $box = eqb()
        ->select($selectFields)
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['box_package_id'], '=', $boxeNumber)
        ->get();
    if (!empty($box)) $candidates = array_merge($candidates, $box);

    if (empty($candidates)) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($fields['name'], '=', $boxeNumber)
            ->get();
        if (!empty($box)) $candidates = array_merge($candidates, $box);
    }

    if (empty($candidates)) {
        $box = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($fields['barcode'], '=', $boxeNumber)
            ->get();
        if (!empty($box)) $candidates = array_merge($candidates, $box);
    }

    foreach ($candidates as $candidate) {
        if ($this->boxAcceptsCountry((int)$candidate['id'], $countrySource)) {
            \Log::debug('findBoxByBoxeNumber: matched', [
                'searched'       => $boxeNumber,
                'country_source' => $countrySource,
                'box_id'         => (int)$candidate['id'],
            ]);
            return $candidate;
        }
    }

    \Log::debug('findBoxByBoxeNumber: no country-compatible match', [
        'searched'        => $boxeNumber,
        'country_source'  => $countrySource,
        'candidate_count' => count($candidates),
    ]);

    return null;
});

/**
 * Create a new box in temporary storage.
 * Country is encoded in the NAME prefix for traceability only — NOT a
 * stored attribute on the box entity (the box has no country_source field).
 */
$this->addMethod('createBox', function (
    string $boxeNumber,
    int    $rows,
    int    $cols,
    string $countrySource
) use ($fields, $boxEntityTypeId, $shippingStatusShippedToUSA, $temporaryStorageId): ?int {

    $boxeNumber    = trim($boxeNumber);
    $countryPrefix = strtoupper(trim($countrySource));
    $boxName       = $countryPrefix !== ''
        ? $countryPrefix . '-' . $boxeNumber
        : $boxeNumber;

    $boxData = [
        'entitytype_id'              => $boxEntityTypeId,
        $fields['box_package_id']    => $boxeNumber,
        $fields['name']              => $boxName,
        $fields['barcode']           => $boxName,
        $fields['number_rows']       => $rows,
        $fields['number_columns']    => $cols,
        $fields['storage']           => $temporaryStorageId,
        $fields['shipping_status']   => $shippingStatusShippedToUSA,
    ];

    \Log::warning('createBox', [
        'boxe_number'    => $boxeNumber,
        'country_source' => $countrySource,
        'box_name'       => $boxName,
        'dimensions'     => "{$rows}x{$cols}",
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

        \Log::info('createBox: SUCCESS', ['box_id' => $newBoxId, 'name' => $boxName]);
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
    \Log::debug('Auto box creation v4: SKIPPED');
    return;
}

$data = $this->data;
if ($this->getCurrentMode() !== 'create') {
    $data = array_merge($this->getOldData(), $this->data);
}

// ── Extract manifest values ──────────────────────────────────────────

$storageId  = $data[$fields['storage']] ?? null;

$boxeNumber = $this->firstNonEmpty($data, $boxeNumberKeys);
if ($boxeNumber === '') {
    $boxeNumber = $this->firstNonEmpty($data, [$fields['barcode'], $fields['name']]);
    if ($boxeNumber !== '') {
        \Log::warning('Auto box creation v4: boxe_number missing — falling back to BARCODE/NAME', [
            'fallback_value' => $boxeNumber,
        ]);
    }
}

$countrySource = strtolower($this->firstNonEmpty($data, $countrySourceKeys));

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

\Log::info('Auto box creation v4: START', [
    'mode'             => $this->getCurrentMode(),
    'storage_id'       => $storageId,
    'storage_resolved' => $resolvedStorage !== null,
    'boxe_number'      => $boxeNumber,
    'country_source'   => $countrySource,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// country_source is required for strict matching
if ($countrySource === '') {
    \Log::error('Auto box creation v4: country_source missing', [
        'tried_keys'     => $countrySourceKeys,
        'available_keys' => array_keys($data),
    ]);
    throwValidationException([
        "country_source is required for this sample. "
        . "Please make sure the import file has a 'country_source' column "
        . "(expected values: " . implode(', ', $knownCountries) . ").",
    ]);
}

if (!in_array($countrySource, $knownCountries, true)) {
    \Log::warning('Auto box creation v4: unknown country_source value', [
        'value'   => $countrySource,
        'known'   => $knownCountries,
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box
//         → reuse only if box accepts this country
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    \Log::info('Auto box creation v4: CASE 1 — storage resolved by system');

    $resolvedBoxId = (int)$resolvedStorage['id'];
    $accepts       = $this->boxAcceptsCountry($resolvedBoxId, $countrySource);

    if (!$accepts) {
        \Log::warning('Auto box creation v4: CASE 1 — resolved box rejects country, falling through to CASE 2', [
            'box_id'         => $resolvedBoxId,
            'country_source' => $countrySource,
        ]);
        // Fall through to CASE 2 to find/create a country-compatible box
    } elseif (!$this->hasValidDimensions($resolvedStorage)) {
        $this->data[$fields['position_row']]    = null;
        $this->data[$fields['position_column']] = null;
        \Log::info('Auto box creation v4: CASE 1 — no grid, stored without position');
        return;
    } else {
        $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v4: CASE 1 COMPLETE', [
                'storage_id' => $resolvedBoxId,
                'position'   => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v4: CASE 1 — box full, falling through to create');
    }
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage, country mismatch, or box was full
// ─────────────────────────────────────────────────────────────────────
if ($boxeNumber === '') {
    \Log::error('Auto box creation v4: cannot resolve box', [
        'reason'             => 'No valid country-compatible storage and no boxe_number column in manifest',
        'storage_id_in_data' => $storageId,
        'country_source'     => $countrySource,
        'tried_keys'         => $boxeNumberKeys,
        'available_keys'     => array_keys($data),
    ]);
    throwValidationException([
        "No valid country-compatible storage and no boxe_number in manifest. "
        . "Cannot assign box. Please make sure the import file has a "
        . "'box_source_number' column (or a STORAGE column that resolves to "
        . "an existing box of the same country).",
    ]);
}

\Log::info('Auto box creation v4: CASE 2 — manifest-driven', [
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
            \Log::info('Auto box creation v4: CASE 2 — placed in existing box', [
                'box_id'   => $boxId,
                'position' => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v4: CASE 2 — existing box also full', ['box_id' => $boxId]);
    }
}

// Step B: Create a new box for this country → sample gets the manifest position.
$newBoxId = $this->createBox($boxeNumber, $totalRows, $totalCols, $countrySource);

if (!$newBoxId) {
    throwValidationException(["Failed to auto-create box '{$boxeNumber}'."]);
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$countryPrefix = strtoupper($countrySource);
$createdName   = $countryPrefix !== '' ? "{$countryPrefix}-{$boxeNumber}" : $boxeNumber;
$this->sendBoxCreatedNotification($newBoxId, $createdName);

\Log::info('Auto box creation v4: CASE 2 — created new box', [
    'box_id'   => $newBoxId,
    'country'  => $countrySource,
    'position' => "{$wantedRow}:{$wantedCol}",
]);
