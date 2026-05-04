/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * An entity is created (create mode only).
  *
  * ## Behavior:
  *
  * Generates a sequential barcode as a zero-padded number.
  * - Sample entities (DNA type)     → {parent_barcode}D{n}  e.g. S00042D1, S00042D2...
  * - Sample entities (Library type) → {parent_barcode}L{n}  e.g. S00042D1L1, S00042D1L2...
  * - Sample entities (Merged type)  → S{sequence}ML         e.g. S00043ML
  * - Sample entities (other)        → S00001, S00002, S00003...
  * - DNA entities                   → {sample_barcode}D{n}  e.g. S00042D1...
  * - Freezer entities               → F00001, F00002...
  * - Shelf entities                 → SH00001, SH00002...
  * - Rack entities                  → R00001, R00002...
  * - Box entities                   → B00001, B00002...
  * - Other entities                 → 00001, 00002, 00003...
  *
  * Skips if barcode is already set.
  ***/

const SAMPLE_TYPE_DNA            = 3265;
const SAMPLE_TYPE_LIBRARY        = 3266;
const SAMPLE_TYPE_MERGED_LIBRARY = 3267;

const STORAGE_PREFIX_MAP = [
    'Freezer'  => 'F',
    'Shelf'    => 'SH',
    'Rack'     => 'R',
    'box_test' => 'B',
];

// Only run on create
if ($this->getCurrentMode() !== 'create') {
    return;
}

// Skip if barcode already set
if (!empty($this->data['BARCODE'])) {
    return;
}

// Resolve entity type name
$entityType = \EntityTypes::inCurrentProject()->find((int)($this->data['entitytype_id'] ?? 0));
$typeName = $entityType?->name();

// ── Storage types: F{n}, SH{n}, R{n}, B{n} ───────────────────────────
if (isset(STORAGE_PREFIX_MAP[$typeName])) {
    $prefix = STORAGE_PREFIX_MAP[$typeName];

    $count = dac()
        ->getQueryFromCurrentProject('entity')
        ->where('entitytype_id', '=', $this->data['entitytype_id'])
        ->where('BARCODE', 'has', null)
        ->count();

    $this->data['BARCODE'] = $prefix . str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT);
    return;
}

// ── DNA entity type: {sample_barcode}D{n} ─────────────────────────────
if ($typeName === 'DNA') {
    $parentId = $this->data['PARENT'] ?? null;

    if (!is_null($parentId)) {
        $parent = eqb()
            ->select(['id', 'BARCODE'])
            ->where('id', '=', $parentId)
            ->limit(1)
            ->page(1)
            ->get()[0] ?? null;

        $sampleBarcode = $parent['BARCODE'] ?? ('S' . str_pad((string)$parentId, 5, '0', STR_PAD_LEFT));

        $dnaTypeId = \EntityTypes::inCurrentProject()->getIdsFromNames('DNA')[0] ?? null;
        $siblingCount = dac()
            ->getQueryFromCurrentProject('entity')
            ->where('PARENT', '=', $parentId)
            ->where('entitytype_id', '=', $dnaTypeId)
            ->count();

        $this->data['BARCODE'] = $sampleBarcode . 'D' . ($siblingCount + 1);
        return;
    }
}

// ── Sample entity type ─────────────────────────────────────────────────
if ($typeName === 'Sample') {
    $sampleType     = (int)($this->data['sample_type_test'] ?? 0);
    $parentSampleId = $this->data['parent_sample'] ?? null;

    // DNA sample type (3265) → {parent_barcode}D{n}
    if ($sampleType === SAMPLE_TYPE_DNA) {
        $parent = !is_null($parentSampleId)
            ? eqb()
                ->select(['id', 'BARCODE'])
                ->where('id', '=', $parentSampleId)
                ->limit(1)
                ->page(1)
                ->get()[0] ?? null
            : null;

        $parentBarcode = $parent['BARCODE'] ?? ('S' . str_pad((string)$parentSampleId, 5, '0', STR_PAD_LEFT));

        $siblingCount = dac()
            ->getQueryFromCurrentProject('entity')
            ->where('sample_type_test', '=', SAMPLE_TYPE_DNA)
            ->where('parent_sample', '=', $parentSampleId)
            ->where('BARCODE', 'has', null)
            ->count();

        $this->data['BARCODE'] = $parentBarcode . 'D' . ($siblingCount + 1);
        return;
    }

    // Library sample type (3266) → {parent_barcode}L{n}
    if ($sampleType === SAMPLE_TYPE_LIBRARY) {
        $parent = !is_null($parentSampleId)
            ? eqb()
                ->select(['id', 'BARCODE'])
                ->where('id', '=', $parentSampleId)
                ->limit(1)
                ->page(1)
                ->get()[0] ?? null
            : null;

        $parentBarcode = $parent['BARCODE'] ?? ('S' . str_pad((string)$parentSampleId, 5, '0', STR_PAD_LEFT));

        $siblingCount = dac()
            ->getQueryFromCurrentProject('entity')
            ->where('sample_type_test', '=', SAMPLE_TYPE_LIBRARY)
            ->where('parent_sample', '=', $parentSampleId)
            ->where('BARCODE', 'has', null)
            ->count();

        $this->data['BARCODE'] = $parentBarcode . 'L' . ($siblingCount + 1);
        return;
    }

    // Merged Library sample type (3267) → S{sequence}ML
    if ($sampleType === SAMPLE_TYPE_MERGED_LIBRARY) {
        $count = dac()
            ->getQueryFromCurrentProject('entity')
            ->where('BARCODE', 'has', null)
            ->count();

        $this->data['BARCODE'] = 'S' . str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT) . 'ML';
        return;
    }

    // Default sample → S00001
    $count = dac()
        ->getQueryFromCurrentProject('entity')
        ->where('BARCODE', 'has', null)
        ->count();

    $this->data['BARCODE'] = 'S' . str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT);
    return;
}

// ── Default: {sequence} ───────────────────────────────────────────────
$count = dac()
    ->getQueryFromCurrentProject('entity')
    ->where('BARCODE', 'has', null)
    ->count();

$this->data['BARCODE'] = str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT);