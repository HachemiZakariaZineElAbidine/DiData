/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A SMPL_CREATION entity is created.
  *
  * ## Behavior:
  *
  * Generates a barcode in the format:
  *   {smpl_id}-{smpl_label}{tube_counter}
  *   e.g. 0001-URINE1
  *
  * - smpl_id      : patient ID from SMPL_SUBJECT (via smpl_subject_fk on SMPL_CREATION)
  * - smpl_label   : line reference from SMPL_WORKFLOW_LINE
  * - tube_counter : count of existing SMPL_CREATION for the same workflow line + 1
  *
  * Skips if barcode is already set or smpl_barcodes is manually provided.
  ***/

use Didata\Entities\Repositories\Models\EntityType;

if ($this->getCurrentMode() !== 'create') {
    return;
}

if (!empty($this->data['BARCODE'])) {
    return;
}

// Skip if barcodes were manually provided
if (!empty($this->data['smpl_barcodes'])) {
    return;
}

$typeName = EntityType::find($this->data['entitytype_id'])?->name;
if ($typeName !== 'SMPL_CREATION') {
    return;
}

$sample = $this->data;

$workflowLineId = $sample['smpl_workflow_line_fk'] ?? null;
$subjectId      = $sample['smpl_subject_fk']       ?? null;

if (!$workflowLineId) {
    \Log::error('[smplSampleBarcode] Cannot generate barcode: smpl_workflow_line_fk is null.', $sample);
    return;
}

if (!$subjectId) {
    \Log::error('[smplSampleBarcode] Cannot generate barcode: smpl_subject_fk is null.', $sample);
    return;
}

// 1. Get patient ID from SMPL_SUBJECT
$subject = eqb()
    ->entityType('SMPL_SUBJECT')
    ->select(['smpl_id'])
    ->where('id', '=', $subjectId)
    ->first();

$patientId = $subject['smpl_id'] ?? null;

if (!$patientId) {
    \Log::error('[smplSampleBarcode] Cannot generate barcode: smpl_id is null on SMPL_SUBJECT id=' . $subjectId, $sample);
    return;
}

// 2. Get line label and ID generation config from SMPL_WORKFLOW_LINE
$workflowLine = eqb()
    ->entityType('SMPL_WORKFLOW_LINE')
    ->select(['smpl_label', 'smpl_id_gen_fk'])
    ->where('id', '=', $workflowLineId)
    ->first();

// Skip if the line uses the smpl_generate_id route (new ID generation system)
if (!empty($workflowLine['smpl_id_gen_fk'])) {
    return;
}

$lineLabel = $workflowLine['smpl_label'] ?? '';

if (!$lineLabel) {
    \Log::error('[smplSampleBarcode] Cannot generate barcode: smpl_label is null on SMPL_WORKFLOW_LINE id=' . $workflowLineId, $sample);
    return;
}

// 3. Tube counter: number of existing SMPL_SAMPLE for this workflow line
//    (SMPL_CREATION tickets are deleted after newSpecimens() processes them)
$tubeCount = eqb()
    ->entityType('SMPL_SAMPLE')
    ->where('smpl_workflow_line_fk', '=', $workflowLineId)
    ->count();

// smpl_order is the 0-based index within a multi-tube batch (set by newSpecimens)
$tubeIndex = ($sample['smpl_order'] ?? 0) + $tubeCount + 1;

$this->data['BARCODE'] = $patientId . '-' . $lineLabel . $tubeIndex;
