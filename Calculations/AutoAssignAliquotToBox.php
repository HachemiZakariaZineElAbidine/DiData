//<?php
/**
 * Auto-Assign Aliquot to Box (by smpl_sample_type)
 *
 * Trigger: Aliquot (entitytype_id = 3) is created without a STORAGE.
 *
 * Matching: boxes (entitytype 7) and aliquots both carry smpl_sample_type.
 *   Find boxes with the same smpl_sample_type as the aliquot, newest first,
 *   and place the aliquot in the first free slot. If no box has a free slot,
 *   create a new 9x9 box with the same smpl_sample_type and place at (1,1).
 */

use App\Common;

\Log::info('AutoAssignAliquotToBox: SCRIPT LOADED', [
    'mode'          => $this->getCurrentMode(),
    'entitytype_id' => $this->data['entitytype_id'] ?? null,
]);

// ─────────────────────────────────────────────
// Field names
// ─────────────────────────────────────────────

$fields = [
    'name'            => 'NAME',
    'barcode'         => 'BARCODE',
    'id'              => 'id',
    'storage'         => 'STORAGE',
    'position_row'    => Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME,
    'position_column' => Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME,
    'number_rows'     => Common::STORAGE_2D_NB_ROWS_FIELD_NAME,
    'number_columns'  => Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME,
    'sample_type'     => 'smpl_sample_type',
    'storage_path'    => 'Storage_Path',
];

// ─────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────

$aliquotEntityTypeId = 3;
$boxEntityTypeId     = 7;
$boxRows             = 9;
$boxColumns          = 9;

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

$this->addMethod('isPositionOccupied', function (int $boxId, int $row, int $col) use ($fields): bool {
    $entity = eqb()
        ->where($fields['storage'], '=', $boxId)
        ->where($fields['position_row'], '=', $row)
        ->where($fields['position_column'], '=', $col)
        ->first('id');

    return $entity !== null && $entity !== false;
});

$this->addMethod('findNextFreePosition', function (int $boxId, int $rows, int $cols): ?array {
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            if (!$this->isPositionOccupied($boxId, $r, $c)) {
                return ['row' => $r, 'column' => $c];
            }
        }
    }
    return null;
});

// Boxes whose smpl_sample_type equals the aliquot's sample type.
$this->addMethod('findMatchingBoxes', function ($sampleTypeId)
    use ($fields, $boxEntityTypeId): array
{
    return eqb()
        ->select(['id', 'entitytype_id', $fields['name'], $fields['sample_type']])
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['sample_type'], '=', $sampleTypeId)
        ->orderBy('id', 'DESC')
        ->get();
});

// Count of existing boxes with this sample type — used as the new box's sequence.
$this->addMethod('countBoxesForSampleType', function ($sampleTypeId)
    use ($fields, $boxEntityTypeId): int
{
    return (int)eqb()
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['sample_type'], '=', $sampleTypeId)
        ->count();
});

$this->addMethod('createBox', function (string $boxName, int $rows, int $cols, $sampleTypeId)
    use ($fields, $boxEntityTypeId): ?int
{
    $boxData = [
        'entitytype_id'           => $boxEntityTypeId,
        $fields['name']           => $boxName,
        $fields['barcode']        => $boxName,
        $fields['number_rows']    => $rows,
        $fields['number_columns'] => $cols,
        $fields['sample_type']    => $sampleTypeId,
    ];

    \Log::warning('AutoAssignAliquotToBox: createBox', [
        'name'        => $boxName,
        'dimensions'  => "{$rows}x{$cols}",
        'sample_type' => $sampleTypeId,
    ]);

    try {
        $repo = dac()->DBrepo('entity');
        $repo->setOptions(['with_calculation' => false, 'with_validation' => true]);
        $created  = $repo->create(dac()->getContext()->getProject()->id, $boxData);
        $newBoxId = is_object($created) ? $created->id : ($created['id'] ?? null);

        if (!$newBoxId) {
            \Log::error('AutoAssignAliquotToBox: createBox returned no ID');
            return null;
        }

        \Log::info('AutoAssignAliquotToBox: createBox SUCCESS', ['box_id' => $newBoxId]);
        return (int)$newBoxId;

    } catch (\Exception $e) {
        \Log::error('AutoAssignAliquotToBox: createBox FAILED', ['error' => $e->getMessage()]);
        return null;
    }
});

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  MAIN EXECUTION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

if ($this->getCurrentMode() !== 'create') {
    return;
}

if ((int)($this->data['entitytype_id'] ?? 0) !== $aliquotEntityTypeId) {
    return;
}

if (empty($this->data['Is_Aliquot'])) {
    \Log::debug('AutoAssignAliquotToBox: Is_Aliquot not true, skipping');
    return;
}

if (!empty($this->data[$fields['storage']])) {
    \Log::debug('AutoAssignAliquotToBox: STORAGE already set, skipping');
    return;
}

$sampleTypeId = $this->data[$fields['sample_type']] ?? null;

if (empty($sampleTypeId)) {
    throwValidationException([
        "Aliquot must have 'smpl_sample_type' set before it can be auto-assigned to a box.",
    ]);
}

\Log::info('AutoAssignAliquotToBox: START', [
    'sample_type_id' => $sampleTypeId,
]);

// ── Step 1: Try existing boxes with the same sample type ────────────────

$boxes     = $this->findMatchingBoxes($sampleTypeId);
$placedBox = null;
$placedPos = null;

foreach ($boxes as $box) {
    $pos = $this->findNextFreePosition((int)$box['id'], $boxRows, $boxColumns);
    if ($pos) {
        $placedBox = $box;
        $placedPos = $pos;
        break;
    }
}

// ── Step 2: No free slot — create a new box ─────────────────────────────

if (!$placedBox) {
    $seq     = $this->countBoxesForSampleType($sampleTypeId) + 1;
    $boxName = "Box_{$sampleTypeId}_{$seq}";

    $newBoxId = $this->createBox($boxName, $boxRows, $boxColumns, $sampleTypeId);

    if (!$newBoxId) {
        throwValidationException(["Failed to auto-create box '{$boxName}'."]);
    }

    $placedBox = ['id' => $newBoxId, $fields['name'] => $boxName];
    $placedPos = ['row' => 1, 'column' => 1];

    \Log::info('AutoAssignAliquotToBox: created new box', [
        'box_id' => $newBoxId,
        'name'   => $boxName,
    ]);
}

// ── Step 3: Write fields onto the aliquot ───────────────────────────────

$boxName     = (string)$placedBox[$fields['name']];
$row         = (int)$placedPos['row'];
$col         = (int)$placedPos['column'];
$storagePath = "{$boxName} [{$row}, {$col}]";

$this->data[$fields['storage']]         = (int)$placedBox['id'];
$this->data[$fields['position_row']]    = $row;
$this->data[$fields['position_column']] = $col;
$this->data[$fields['storage_path']]    = $storagePath;

\Log::info('AutoAssignAliquotToBox: DONE', [
    'box_id'       => (int)$placedBox['id'],
    'storage_path' => $storagePath,
]);
