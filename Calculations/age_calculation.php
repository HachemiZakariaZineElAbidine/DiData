/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A SMPL_SUBJECT entity is created or updated.
  *
  * ## Behavior:
  *
  * Automatically calculates the age in years based on the
  * date of birth field (smpl_subject_dob) and writes it
  * to the age field (sample_subject_age).
  *
  * Date format expected: DD-MM-YYYY
  * Skips if smpl_subject_dob is empty.
  ***/

// Skip if no date of birth provided
if (empty($this->data['smpl_subject_dob'])) {
    return;
}

$dob = $this->data['smpl_subject_dob'];

// Parse DD-MM-YYYY
$parts = explode('-', $dob);

if (count($parts) !== 3) {
    return;
}

[$day, $month, $year] = $parts;

// Validate parts are numeric
if (!is_numeric($day) || !is_numeric($month) || !is_numeric($year)) {
    return;
}

$dobDate  = new \DateTime($year . '-' . $month . '-' . $day);
$today    = new \DateTime('today');
$age      = $today->diff($dobDate)->y;

$this->data['sample_subject_age'] = $age;