//<?php
/**
 * Auto Box Creation — Johns Hopkins Pilot (v4)
 *
 * Excel import brings: location, row, column, total_rows, total_columns,
 * country_source.
 *
 * IMPORTANT: country_source is a field on Sample only — NOT on Box.
 * So box "country" is inferred from the samples already inside it:
 *   - An empty box is open to any country.
 *   - A box whose existing samples are all country X "belongs" to X.
 *
 * Box selection (strict): a sample only lands in a box whose existing
 * samples share the same country_source (or in an empty box).
 *
 * Flow:
 *   1. System resolved the location to a valid box
 *      → if that box is empty OR its samples all share the sample's country,
 *        store at manifest row/column there.
 *      → else fall through to CASE 2.
 *   2. No valid storage / country conflict / box full
 *      → find an existing box that matches by country and has the wanted
 *        position free (else any free slot).
 *      → if none, create a new box and place the sample at the wanted
 *        position. The new box's NAME encodes the country for traceability.
 *   3. If the wanted position is occupied in the chosen box → next free.
 *
 * RULES:
 *   - Never assign a sample to an occupied position.
 *   - A sample only lands in an empty box or one whose samples share its country.
 *   - The manifest MUST provide a country_source.
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
    'shipping_status'   => 'Shipping_Status',
    'manifest_row'      => 'Row',
    'manifest_column'   => 'Column',
    'country_source'    => 'country_source', // SAMPLE field only — never on box
];

// Candidate keys for country_source — defensive against header casing.
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

// Note: do NOT select country_source here — not a box field.
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

// Excel rows can be a/b/c... — convert to 1/2/3... "c"→3, "B"→2, "1"→1.
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

// Strict: both sides must be non-empty and equal (case-insensitive after trim).
$this->addMethod('countryMatches', function ($a, $b): bool {
    $a = strtolower(trim((string)$a));
    $b = strtolower(trim((string)$b));
    if ($a === '' || $b === '') return false;
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
        return ['row' => $wantedRow, 'column' => $wantedCol];
    }

    return $this->findNextFreePosition($storage);
});

// ─────────────────────────────────────────────
// Country lookup via existing samples in a box
// ─────────────────────────────────────────────

/**
 * Returns the country_source of samples in this box.
 *   - []        → box is empty (open to any country)
 *   - [string]  → all samples share one country (box "belongs" to it)
 *   - [a,b,...] → mixed (shouldn't happen, but handled)
 */
$this->addMethod('getBoxSampleCountries', function (int $boxId) use ($fields, $sampleEntityTypeId): array {
    $rows = eqb()
        ->select([$fields['country_source']])
        ->where('entitytype_id', '=', $sampleEntityTypeId)
        ->where($fields['storage'], '=', $boxId)
        ->get();

    if (empty($rows)) return [];

    $countries = [];
    foreach ($rows as $r) {
        $c = strtolower(trim((string)($r[$fields['country_source']] ?? '')));
        if ($c !== '' && !in_array($c, $countries, true)) $countries[] = $c;
    }
    return $countries;
});

/**
 * Is this box compatible with the given sample country?
 *   - Empty box (no samples) → yes.
 *   - Box's existing samples all share the same country and it matches → yes.
 *   - Otherwise → no.
 */
$this->addMethod('boxAcceptsCountry', function (int $boxId, string $countrySource): bool {
    $countrySource = strtolower(trim($countrySource));
    if ($countrySource === '') return false;

    $countries = $this->getBoxSampleCountries($boxId);
    if (empty($countries)) return true;
    if (count($countries) === 1 && $countries[0] === $countrySource) return true;
    return false;
});

// ─────────────────────────────────────────────
// Box lookup / creation — by inferred country
// ─────────────────────────────────────────────

/**
 * Find a box compatible with this sample's country.
 * Preference order:
 *   1. A compatible box where the wanted position is free.
 *   2. A compatible box with ANY free slot (oldest first).
 * Returns ['box' => array, 'position' => ['row'=>int,'column'=>int]] or null.
 */
$this->addMethod('findBoxForCountry', function (
    string $countrySource,
    int    $wantedRow,
    int    $wantedCol
) use ($fields, $boxEntityTypeId): ?array {

    $countrySource = trim($countrySource);
    if ($countrySource === '') return null;

    $boxes = eqb()
        ->select([
            'id', 'entitytype_id',
            $fields['name'], $fields['barcode'],
            $fields['number_rows'], $fields['number_columns'],
            $fields['shipping_status'],
        ])
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->orderBy('id', 'ASC')
        ->get();

    if (empty($boxes)) return null;

    // Pass 1 — compatible box with the exact wanted slot free.
    foreach ($boxes as $box) {
        if (!$this->hasValidDimensions($box)) continue;
        if (!$this->boxAcceptsCountry((int)$box['id'], $countrySource)) continue;
        if (!$this->isPositionOccupied((int)$box['id'], $wantedRow, $wantedCol)) {
            return [
                'box'      => $box,
                'position' => ['row' => $wantedRow, 'column' => $wantedCol],
            ];
        }
    }

    // Pass 2 — any free slot in any compatible box.
    foreach ($boxes as $box) {
        if (!$this->boxAcceptsCountry((int)$box['id'], $countrySource)) continue;
        $pos = $this->findNextFreePosition($box);
        if ($pos) {
            return ['box' => $box, 'position' => $pos];
        }
    }

    return null;
});

/**
 * Create a new box in temporary storage.
 * The country is encoded in the NAME for traceability. country_source is
 * NOT written — it isn't a real field on the box entity.
 */
$this->addMethod('createBox', function (
    int    $rows,
    int    $cols,
    string $countrySource
) use ($fields, $boxEntityTypeId, $shippingStatusShippedToUSA, $temporaryStorageId): ?int {

    $countrySource = trim($countrySource);
    $generatedName = strtoupper($countrySource) . '-' . date('Ymd-His') . '-' . substr((string)mt_rand(1000, 9999), 0, 4);

    $boxData = [
        'entitytype_id'              => $boxEntityTypeId,
        $fields['name']              => $generatedName,
        $fields['barcode']           => $generatedName,
        $fields['number_rows']       => $rows,
        $fields['number_columns']    => $cols,
        $fields['storage']           => $temporaryStorageId,
        $fields['shipping_status']   => $shippingStatusShippedToUSA,
    ];

    \Log::warning('createBox', [
        'name'           => $generatedName,
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

        \Log::info('createBox: SUCCESS', ['box_id' => $newBoxId, 'name' => $generatedName]);
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

$storageId     = $data[$fields['storage']] ?? null;
$countrySource = $this->firstNonEmpty($data, $countrySourceKeys);

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
    'country_source'   => $countrySource,
    'wanted_position'  => "{$alphaRow}({$wantedRow}):{$wantedCol}",
]);

// ─────────────────────────────────────────────────────────────────────
// CASE 1: System resolved the location to a valid box.
//         Store at manifest row/column IF the box accepts this country.
// ─────────────────────────────────────────────────────────────────────
if ($resolvedStorage) {
    $boxId    = (int)$resolvedStorage['id'];
    $countryOk = $this->boxAcceptsCountry($boxId, $countrySource);

    if (!$countryOk) {
        \Log::info('Auto box creation v4: CASE 1 — country mismatch, falling through', [
            'sample_country' => $countrySource,
            'box_id'         => $boxId,
        ]);
        $resolvedStorage = null;
    } else {
        \Log::info('Auto box creation v4: CASE 1 — storage accepts country');

        if (!$this->hasValidDimensions($resolvedStorage)) {
            $this->data[$fields['position_row']]    = null;
            $this->data[$fields['position_column']] = null;
            return;
        }

        $pos = $this->resolvePosition($resolvedStorage, $wantedRow, $wantedCol);

        if ($pos) {
            $this->data[$fields['position_row']]    = $pos['row'];
            $this->data[$fields['position_column']] = $pos['column'];
            \Log::info('Auto box creation v4: CASE 1 COMPLETE', [
                'storage_id' => $boxId,
                'position'   => "{$pos['row']}:{$pos['column']}",
            ]);
            return;
        }

        \Log::info('Auto box creation v4: CASE 1 — box full, falling through');
    }
}

// ─────────────────────────────────────────────────────────────────────
// CASE 2: No valid storage / country mismatch / box full.
//         Find a compatible existing box, or create a new one.
// ─────────────────────────────────────────────────────────────────────
if ($countrySource === '') {
    \Log::error('Auto box creation v4: missing country_source', [
        'tried_keys'     => $countrySourceKeys,
        'available_keys' => array_keys($data),
    ]);
    throwValidationException([
        "Missing 'country_source'. Samples must carry a country_source so they "
        . "are only placed in a box of the same country. Please add a "
        . "'country_source' column to the manifest.",
    ]);
}

\Log::info('Auto box creation v4: CASE 2 — country-driven', [
    'country_source' => $countrySource,
]);

$match = $this->findBoxForCountry($countrySource, $wantedRow, $wantedCol);

if ($match) {
    $boxId = (int)$match['box']['id'];
    $pos   = $match['position'];

    $this->data[$fields['storage']]         = $boxId;
    $this->data[$fields['position_row']]    = $pos['row'];
    $this->data[$fields['position_column']] = $pos['column'];

    \Log::info('Auto box creation v4: CASE 2 — placed in existing compatible box', [
        'box_id'   => $boxId,
        'country'  => $countrySource,
        'position' => "{$pos['row']}:{$pos['column']}",
    ]);
    return;
}

// No compatible box has room → create a new one.
$newBoxId = $this->createBox($totalRows, $totalCols, $countrySource);

if (!$newBoxId) {
    throwValidationException(["Failed to auto-create box for country '{$countrySource}'."]);
}

$this->data[$fields['storage']]         = $newBoxId;
$this->data[$fields['position_row']]    = $wantedRow;
$this->data[$fields['position_column']] = $wantedCol;

$this->sendBoxCreatedNotification($newBoxId, strtoupper($countrySource));

\Log::info('Auto box creation v4: CASE 2 — created new box', [
    'box_id'         => $newBoxId,
    'position'       => "{$wantedRow}:{$wantedCol}",
    'country_source' => $countrySource,
]);
