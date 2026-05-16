//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot (v5)
 *
 * Excel import brings: location, row, column, total_rows, total_columns,
 *                      box_source_number, country_source
 *
 * v5 changes vs v2:
 *   - country_source is REQUIRED on every incoming sample.
 *   - A sample is only placed in a box whose `country_source` matches the
 *     sample's `country_source` (case-insensitive).
 *   - CASE 1 falls through to CASE 2 when the system-resolved box's
 *     country_source does not match.
 *   - findBoxByBoxeNumber collects all candidates (->get()) and filters by
 *     country instead of returning the first hit.
 *   - createBox writes country_source on the new Box entity.
 *   - Position still comes from the manifest (Row alphabetic → numeric,
 *     Column numeric). Auto-scan stays as a safety net for occupied slots.
 */

use App\Common;
use App\DidataPackages\DidataCache;

// ─────────────────────────────────────────────
// Field names
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
    'box_package_id'    => 'box_source_number',
    'shipping_status'   => 'Shipping_Status',
    'manifest_row'      => 'Row',
    'manifest_column'   => 'Column',
    'box_source_number'       => 'box_source_number',
    'country_source'    => 'country_source',
];

// Excel manifest sends `boxe_number` (and also has `box_package_ID`).
$boxeNumberKeys = [
    'boxe_number',
    'box_package_ID',
];

$countrySourceKeys = [
    'country_source'
];

// ─────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────

$boxEntityTypeId            = 7;
$sampleEntityTypeId         = 3;
$defaultBoxRows             = 9;
$defaultBoxColumns          = 9;
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

$this->addMethod('firstNonEmpty', function (array $data, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $val = trim((string)$data[$key]);
            if ($val !== '') return $val;
        }
    }
    return '';
});

// country_source is a Choice field — values are integer IDs (e.g. 5 = USA).
// Compare as integers, not strings.
$this->addMethod('normalizeCountry', function ($value) {
    if ($value === null || $value === '' || $value === false) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    if (ctype_digit($s)) return (int)$s;
    return strtolower($s); // string fallback (shouldn't happen for Choice IDs)
});

$this->addMethod('countryMatches', function ($boxCountry, $sampleCountry): bool {
    $a = $this->normalizeCountry($boxCountry);
    $b = $this->normalizeCountry($sampleCountry);
    if ($a === null || $b === null) return false;
    return $a === $b;
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
// Box lookup / creation — country-aware
// ─────────────────────────────────────────────

/**
 * Find boxes that match the box_source_number AND the sample's country_source.
 * Returns the first country-matching box. Lookup order:
 *   box_number_  →  NAME  →  BARCODE
 * Within each search, all hits are pulled (->get()) and filtered by country.
 */
$this->addMethod('findBoxByBoxeNumber', function (string $boxeNumber, $sampleCountry)
    use ($fields, $boxEntityTypeId): ?array
{
    $boxeNumber = trim($boxeNumber);
    if ($boxeNumber === '') return null;

    // Search only NAME / BARCODE — V5 writes the box_source_number into those.
    // boxe_number and box_number_ are left null on the Box, so don't query them.
    $selectFields = [
        'id', 'entitytype_id',
        $fields['name'], $fields['barcode'],
        $fields['number_rows'], $fields['number_columns'],
        $fields['shipping_status'], $fields['country_source'],
    ];

    $searchFields = [$fields['name'], $fields['barcode']];

    foreach ($searchFields as $searchField) {
        $candidates = eqb()
            ->select($selectFields)
            ->where('entitytype_id', '=', $boxEntityTypeId)
            ->where($searchField, '=', $boxeNumber)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->countryMatches($candidate[$fields['country_source']] ?? '', $sampleCountry)) {
                \Log::debug('findBoxByBoxeNumber: country-matching box found', [
                    'box_id'         => (int)$candidate['id'],
                    'searched_field' => $searchField,
                    'country'        => $sampleCountry,
                ]);
                return $candidate;
            }
        }
    }

    \Log::debug('findBoxByBoxeNumber: no country-matching box', [
        'box_source_number'    => $boxeNumber,
        'sample_country' => $sampleCountry,
    ]);
    return null;
});

/**
 * Create a new box. Country is stamped on the box entity itself.
 * Parent storage stays as the temporary storage (45859) — same as v2.
 */
$this->addMethod('createBox', function (
    string $boxeNumber,
    int    $rows,
    int    $cols,
           $countrySource
) use ($fields, $boxEntityTypeId, $shippingStatusShippedToUSA, $temporaryStorageId): ?int {

    $boxeNumber    = trim($boxeNumber);
    // country_source is a Choice ID (integer). Don't string-mangle it.

    // Do NOT write boxe_number or box_number_ — leave them null on the Box.
    // The manifest's box_source_number is preserved as NAME / BARCODE only.
    $boxData = [
        'entitytype_id'              => $boxEntityTypeId,
        $fields['name']              => $boxeNumber,
        $fields['barcode']           => $boxeNumber,
        $fields['number_rows']       => $rows,
        $fields['number_columns']    => $cols,
        $fields['storage']           => $temporaryStorageId,
        $fields['shipping_status']   => $shippingStatusShippedToUSA,
        $fields['country_source']    => $countrySource,
    ];

    \Log::warning('createBox', [
        'box_source_number' => $boxeNumber,
        'dimensions'  => "{$rows}x{$cols}",
        'country'     => $countrySource,
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

$this->addMethod('sendBoxCreatedNotification', function (int $boxId, string $boxName, $country)
    use ($notificationOperationId): void
{
    $user   = getCurrentUser();
    $userId = $user['id'] ?? null;
    if (!$userId) return;

    $countryLabel = (string)$country;
    $payload = [
        'title'       => "New Box Auto-Created: \"{$boxName}\" ({$countryLabel})",
        'content'     => "Box #{$boxId} (\"{$boxName}\") for country '{$countryLabel}' "
                       . "was automatically created in temporary storage.\n"
                       . "Please assign a final storage location once the shipment is received.",
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
    \Log::debug('Auto box creation v5: SKIPPED');
    return;
}

$data = $this->data;
if ($this->getCurrentMode() !== 'create') {
    $data = array_merge($this->getOldData(), $this->data);
}

// ── Extract manifest values ──────────────────────────────────────────

$storageId     = $data[$fields['storage']] ?? null;
$boxeNumber    = $this->firstNonEmpty($data, $boxeNumberKeys);
// country_source is a Choice ID (integer like 5). Keep as-is; normalize on compare.
$countrySource = $this->normalizeCountry($this->firstNonEmpty($data, $countrySourceKeys));

if ($boxeNumber === '') {
    $boxeNumber = $this->firstNonEmpty($data, [$fields['barcode'], $fields['name']]);
    if ($boxeNumber !== '') {
        \Log::warning('Auto box creation v5: box_source_number missing — falling back to BARCODE/NAME', [
            'fallback_value' => $boxeNumber,
        ]);
    }
}

// country_source is REQUIRED
if ($countrySource === null) {
    \Log::error('Auto box creation v5: country_source missing', [
        'tried_keys'     => $countrySourceKeys,
        'available_keys' => array_keys($data),
    ]);
    throwValidationException([
        "country_source is required for this sample. "
        . "Please make sure the import file has a 'country_source' column.",
    ]);
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

\Log::info('Auto box creation v5: START', [
    'mode'             => $this->getCurrentMode(),
    'storage_id'       => $storageId,
    'storage_resolved' => $resolvedStorage !== null,
    'box_source_number'      => $boxeNumber,
    'country_source'   => $countrySource,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    $resolvedCountry = $resolvedStorage[$fields['country_source']] ?? '';
    $countryOk       = $this->countryMatches($resolvedCountry, $countrySource);

    if (!$countryOk) {
        \Log::info('Auto box creation v5: CASE 1 — resolved box country does not match, falling through', [
            'box_id'         => (int)$resolvedStorage['id'],
            'box_country'    => $resolvedCountry,
            'sample_country' => $countrySource,
        ]);
        // Fall through to CASE 2
    } elseif (!$this->hasValidDimensions($resolvedStorage)) {
        $this->data[$fields['position_row']]    = null;
        $this->data[$fields['position_column']] = null;
        \Log::info('Auto box creation v5: CASE 1 — no grid, stored without position');
        return;
    } else {
        $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v5: CASE 1 COMPLETE', [
                'storage_id' => $resolvedStorage['id'],
                'position'   => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v5: CASE 1 — box full, falling through to create');
    }
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage, country mismatch, or box was full
// ─────────────────────────────────────────────────────────────────────
if ($boxeNumber === '') {
    \Log::error('Auto box creation v5: cannot resolve box', [
        'reason'             => 'No valid storage and no box_source_number-like column in manifest',
        'storage_id_in_data' => $storageId,
        'tried_keys'         => $boxeNumberKeys,
        'available_keys'     => array_keys($data),
    ]);
    throwValidationException([
        "No valid storage and no box_source_number in manifest. Cannot assign box. "
        . "Please make sure the import file has a 'box_source_number' column "
        . "(or a STORAGE column that resolves to an existing box).",
    ]);
}

\Log::info('Auto box creation v5: CASE 2 — manifest-driven', [
    'box_source_number'    => $boxeNumber,
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
            \Log::info('Auto box creation v5: CASE 2 — placed in existing box', [
                'box_id'   => $boxId,
                'country'  => $countrySource,
                'position' => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v5: CASE 2 — existing box also full', ['box_id' => $boxId]);
    }
}

// Before creating: check if a box with this NAME/BARCODE already exists.
$collidingBox = eqb()
    ->select(['id', $fields['name'], $fields['country_source'],
              $fields['number_rows'], $fields['number_columns']])
    ->where('entitytype_id', '=', $boxEntityTypeId)
    ->where($fields['name'], '=', $boxeNumber)
    ->first();
if (!$collidingBox) {
    $collidingBox = eqb()
        ->select(['id', $fields['barcode'], $fields['country_source'],
                  $fields['number_rows'], $fields['number_columns']])
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['barcode'], '=', $boxeNumber)
        ->first();
}

if ($collidingBox) {
    $existingCountry = $this->normalizeCountry($collidingBox[$fields['country_source']] ?? null);

    if ($existingCountry === null) {
        // Box exists but has no country yet — claim it for this sample's country.
        $existingBoxId = (int)$collidingBox['id'];
        \Log::warning('Auto box creation v5: adopting existing box (no country) for this country', [
            'box_id'         => $existingBoxId,
            'boxe_number'    => $boxeNumber,
            'sample_country' => $countrySource,
        ]);

        try {
            $repo = dac()->DBrepo('entity');
            $repo->setOptions(['with_calculation' => false, 'with_validation' => true]);
            $repo->update(
                \Didata\Entities\Repositories\Models\Entity::find($existingBoxId),
                [$fields['country_source'] => $countrySource]
            );
        } catch (\Exception $e) {
            \Log::error('Auto box creation v5: failed to stamp country on existing box', [
                'box_id' => $existingBoxId,
                'error'  => $e->getMessage(),
            ]);
            throwValidationException([
                "Could not assign country to existing box '{$boxeNumber}': " . $e->getMessage(),
            ]);
        }

        $pos = $this->resolvePosition($collidingBox, $wantedRow, $wantedCol);
        if (!$pos) {
            throwValidationException([
                "Existing box '{$boxeNumber}' is full. Cannot place sample.",
            ]);
        }

        $this->data[$fields['storage']]         = $existingBoxId;
        $this->data[$fields['position_row']]    = $pos['row'];
        $this->data[$fields['position_column']] = $pos['column'];

        \Log::info('Auto box creation v5: CASE 2 — adopted existing box', [
            'box_id'   => $existingBoxId,
            'country'  => $countrySource,
            'position' => "{$pos['row']}:{$pos['column']}",
        ]);
        return;
    }

    // Existing box has a country, and it's different from the sample's
    // (otherwise findBoxByBoxeNumber would have already returned it).
    \Log::error('Auto box creation v5: NAME/BARCODE collision with different country', [
        'boxe_number'           => $boxeNumber,
        'sample_country'        => $countrySource,
        'existing_box_id'       => (int)$collidingBox['id'],
        'existing_box_country'  => $existingCountry,
    ]);
    throwValidationException([
        "Box '{$boxeNumber}' already exists for country '{$existingCountry}' "
        . "but this sample's country is '{$countrySource}'. "
        . "Cannot create a duplicate box with the same number.",
    ]);
}

// Step B: Create new box with country_source — sample gets manifest position
$newBoxId = $this->createBox($boxeNumber, $totalRows, $totalCols, $countrySource);

if (!$newBoxId) {
    throwValidationException(["Failed to auto-create box '{$boxeNumber}' ({$countrySource})."]);
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$this->sendBoxCreatedNotification($newBoxId, $boxeNumber, $countrySource);

\Log::info('Auto box creation v5: CASE 2 — created new box', [
    'box_id'   => $newBoxId,
    'country'  => $countrySource,
    'position' => "{$wantedRow}:{$wantedCol}",
]);
