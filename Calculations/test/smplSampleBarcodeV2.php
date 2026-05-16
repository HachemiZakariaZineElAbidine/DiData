/*** Powered by DiData
  *
  * ## Entity:
  *
  * SMPL_SAMPLE (entity type id: 21)
  *
  * ## Triggers when:
  *
  * A SMPL_SAMPLE entity is created.
  *
  * ## Behavior:
  *
  * Generates a barcode in the format:
  *   {patient_id}-V{visit_id}-{line_label}-{tube_n}
  *   e.g. 1978-V1979-protocol-1
  *
  * - patient_id  : entity ID of the linked SMPL_SUBJECT (smpl_subject_fk)
  * - visit_id    : entity ID of the linked SMPL_CASE    (smpl_case_fk), prefixed with V
  * - line_label  : smpl_label from SMPL_WORKFLOW_LINE
  * - tube_n      : smpl_order (0-based batch index set by newSpecimens) + 1
  *
  * Skips if BARCODE is already set.
  ***/

use Didata\Entities\Repositories\Models\EntityType;

if ($this->getCurrentMode() !== 'create') {
    return;
}

if (!empty($this->data['BARCODE'])) {
    return;
}

$typeName = EntityType::find($this->data['entitytype_id'])?->name;
if ($typeName !== 'SMPL_SAMPLE') {
    return;
}

$sample = $this->data;

$subjectId      = $sample['smpl_subject_fk']      ?? null;
$caseId         = $sample['smpl_case_fk']          ?? null;
$workflowLineId = $sample['smpl_workflow_line_fk'] ?? null;
$smplOrder      = (int)($sample['smpl_order']      ?? 0);

if (!$subjectId) {
    \Log::error('[smplSampleBarcodeV2] Cannot generate barcode: smpl_subject_fk is null.', $sample);
    return;
}

if (!$caseId) {
    \Log::error('[smplSampleBarcodeV2] Cannot generate barcode: smpl_case_fk is null.', $sample);
    return;
}

if (!$workflowLineId) {
    \Log::error('[smplSampleBarcodeV2] Cannot generate barcode: smpl_workflow_line_fk is null.', $sample);
    return;
}

// 1. Get line label from SMPL_WORKFLOW_LINE
$workflowLine = eqb()
    ->entityType('SMPL_WORKFLOW_LINE')
    ->select(['smpl_label'])
    ->where('id', '=', $workflowLineId)
    ->first();

$lineLabel = $workflowLine['smpl_label'] ?? null;

if (!$lineLabel) {
    \Log::error('[smplSampleBarcodeV2] Cannot generate barcode: smpl_label is null on SMPL_WORKFLOW_LINE id=' . $workflowLineId, $sample);
    return;
}

// 2. Tube sequential number
//    smpl_order is set by newSpecimens as the 0-based tube index within the batch
//    (e.g. quantity=2 → orders 0 and 1), so tube_n is simply smpl_order + 1.
$tubeN = $smplOrder + 1;

// 3. Build barcode  — patient_id and visit_id are the FK values (entity IDs) directly
$this->data['BARCODE'] = $subjectId
    . '-V' . $caseId
    . '-' . $lineLabel
    . '-' . $tubeN;
