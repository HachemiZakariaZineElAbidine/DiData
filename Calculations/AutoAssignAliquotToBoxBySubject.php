//<?php
/**
 * Auto-Assign Aliquot to Box (by SUBJECT + smpl_sample_type)
 *
 * Trigger: Aliquot (entitytype_id = 3, Is_Aliquot = true) is created without
 *          a STORAGE.
 *
 * Logic:
 *   n = number of existing aliquots with the same SUBJECT and same
 *       smpl_sample_type (Is_Aliquot = true).
 *   targetBoxName = "Box_{sampleTypeName}_{n+1}"   e.g. "Box_Serum_11"
 *
 *   1. Look for the box named targetBoxName carrying that smpl_sample_type.
 *   2. If found and has a free slot → place there.
 *   3. Otherwise (missing or full) create a new 9x9 box with that name
 *      and place at the first free slot (= 1,1).
 *   4. Write STORAGE, POSITION_ROW, POSITION_COLUMN, Storage_Path on the aliquot.
 */

use App\Common;

\Log::info('AutoAssignAliquotToBoxBySubject: SCRIPT LOADED', [
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
    'subject'         => 'SUBJECT',
    'is_aliquot'      => 'Is_Aliquot',
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

// Count existing aliquots with the same SUBJECT + smpl_sample_type
// AND Is_Aliquot = true. Is_Aliquot is a Checkbox — eqb does not support '='
// on it, so we fetch the rows and filter in PHP.
$this->addMethod('countAliquotsForSubjectAndType', function ($subjectId, $sampleTypeId)
    use ($fields, $aliquotEntityTypeId): int
{
    $rows = eqb()
        ->select(['id', $fields['is_aliquot']])
        ->where('entitytype_id', '=', $aliquotEntityTypeId)
        ->where($fields['subject'], '=', $subjectId)
        ->where($fields['sample_type'], '=', $sampleTypeId)
        ->get();

    $count = 0;
    $debugRows = [];
    foreach ($rows as $r) {
        $val = $r[$fields['is_aliquot']] ?? null;
        $debugRows[] = [
            'id'    => $r['id'] ?? null,
            'raw'   => $val,
            'type'  => gettype($val),
            'kept'  => $val === true || $val === 1 || $val === '1',
        ];
        // Only treat strict truthy values as "is aliquot".
        // Excludes null, 0, "0", "", and false.
        if ($val === true || $val === 1 || $val === '1') {
            $count++;
        }
    }

    \Log::info('AutoAssignAliquotToBoxBySubject: count debug', [
        'subject'         => $subjectId,
        'sample_type_id'  => $sampleTypeId,
        'total_rows'      => count($rows),
        'is_aliquot_true' => $count,
        'rows'            => $debugRows,
    ]);

    return $count;
});

// "Blood (whole)" → "Blood_whole"
// 'Urine random ("spot")' → "Urine_random_spot"
// Keep only [A-Za-z0-9], collapse runs of separators into a single underscore.
$this->addMethod('normalizeSampleTypeName', function (string $name): string {
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
    return trim($name, '_');
});

// smpl_sample_type is a Choice field — resolve via Choice::find($id)->value.
$this->addMethod('getSampleTypeName', function ($sampleTypeId): string {
    if (empty($sampleTypeId)) return 'UNKNOWN';

    $choice = \Didata\Entities\Repositories\Models\Fields\Choice::find((int)$sampleTypeId);
    if (!$choice) {
        \Log::warning('AutoAssignAliquotToBoxBySubject: choice not found', [
            'sample_type_id' => $sampleTypeId,
        ]);
        return 'UNKNOWN';
    }

    $name = (string)($choice->value ?? '');
    return $name !== '' ? $name : 'UNKNOWN';
});

// Find the specific target box by name + sample type.
$this->addMethod('findBoxByName', function (string $boxName, $sampleTypeId)
    use ($fields, $boxEntityTypeId): ?array
{
    $box = eqb()
        ->select(['id', 'entitytype_id', $fields['name'], $fields['sample_type']])
        ->where('entitytype_id', '=', $boxEntityTypeId)
        ->where($fields['name'], '=', $boxName)
        ->where($fields['sample_type'], '=', $sampleTypeId)
        ->first();
    return $box ?: null;
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

    \Log::warning('AutoAssignAliquotToBoxBySubject: createBox', [
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
            \Log::error('AutoAssignAliquotToBoxBySubject: createBox returned no ID');
            return null;
        }

        \Log::info('AutoAssignAliquotToBoxBySubject: createBox SUCCESS', ['box_id' => $newBoxId]);
        return (int)$newBoxId;

    } catch (\Exception $e) {
        \Log::error('AutoAssignAliquotToBoxBySubject: createBox FAILED', ['error' => $e->getMessage()]);
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

if (empty($this->data[$fields['is_aliquot']])) {
    \Log::debug('AutoAssignAliquotToBoxBySubject: Is_Aliquot not true, skipping');
    return;
}

if (!empty($this->data[$fields['storage']])) {
    \Log::debug('AutoAssignAliquotToBoxBySubject: STORAGE already set, skipping');
    return;
}

$subjectId    = $this->data[$fields['subject']] ?? null;
$sampleTypeId = $this->data[$fields['sample_type']] ?? null;

if (empty($subjectId) || empty($sampleTypeId)) {
    throwValidationException([
        "Aliquot must have both 'SUBJECT' and 'smpl_sample_type' set "
        . "before it can be auto-assigned to a box.",
    ]);
}

// ── Compute target box name ─────────────────────────────────────────────

$n              = $this->countAliquotsForSubjectAndType($subjectId, $sampleTypeId);
$sampleTypeRaw  = $this->getSampleTypeName($sampleTypeId);
$sampleTypeNorm = $this->normalizeSampleTypeName($sampleTypeRaw);
$targetBoxName  = "Box_{$sampleTypeNorm}_" . ($n + 1);

\Log::info('AutoAssignAliquotToBoxBySubject: START', [
    'subject'         => $subjectId,
    'sample_type_id'  => $sampleTypeId,
    'existing_count'  => $n,
    'target_box_name' => $targetBoxName,
]);

// ── Step 1: Try the target box if it exists ─────────────────────────────

$placedBox = null;
$placedPos = null;

$existingBox = $this->findBoxByName($targetBoxName, $sampleTypeId);

if ($existingBox) {
    $pos = $this->findNextFreePosition((int)$existingBox['id'], $boxRows, $boxColumns);
    if ($pos) {
        $placedBox = $existingBox;
        $placedPos = $pos;
        \Log::info('AutoAssignAliquotToBoxBySubject: placed in existing target box', [
            'box_id'   => (int)$existingBox['id'],
            'position' => "{$pos['row']}:{$pos['column']}",
        ]);
    } else {
        \Log::info('AutoAssignAliquotToBoxBySubject: target box full, will create new', [
            'box_id' => (int)$existingBox['id'],
        ]);
    }
}

// ── Step 2: Create the target box if missing or full ────────────────────

if (!$placedBox) {
    // If target name is already taken by a full box, bump the sequence
    // until we find a free name.
    $candidateName = $targetBoxName;
    $bump          = $n + 1;
    while ($this->findBoxByName($candidateName, $sampleTypeId) !== null) {
        $bump++;
        $candidateName = "Box_{$sampleTypeNorm}_{$bump}";
    }

    $newBoxId = $this->createBox($candidateName, $boxRows, $boxColumns, $sampleTypeId);

    if (!$newBoxId) {
        throwValidationException(["Failed to auto-create box '{$candidateName}'."]);
    }

    $placedBox = ['id' => $newBoxId, $fields['name'] => $candidateName];
    $placedPos = $this->findNextFreePosition($newBoxId, $boxRows, $boxColumns)
        ?? ['row' => 1, 'column' => 1];

    \Log::info('AutoAssignAliquotToBoxBySubject: created new box', [
        'box_id' => $newBoxId,
        'name'   => $candidateName,
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

\Log::info('AutoAssignAliquotToBoxBySubject: DONE', [
    'box_id'       => (int)$placedBox['id'],
    'storage_path' => $storagePath,
]);
