/*** Powered by DiData ***/
$this->addMethod('calculateShipmentDetails', function() {
    // Ensure the date field is set and not empty
    if (isset($this->data['Shipment_Full_Date_']) && !empty($this->data['Shipment_Full_Date_'])) {
        try {
            // Parse the date (explicit format for mm dd yyyy)
            $shipmentDate = DateTime::createFromFormat('m-d-Y', $this->data['Shipment_Full_Date_']);
            
            if ($shipmentDate !== false) {
                // Populate dependent fields with string values (for short text fields)
                $this->data['shipment_date'] = $shipmentDate->format('d'); // Day (e.g., "05")
                $this->data['shipment_year'] = $shipmentDate->format('Y'); // Year (e.g., "2025")
                $this->data['shipment_month'] = $shipmentDate->format('F'); // Full month name (e.g., "January")
            } else {
                // Handle invalid date format
                throw new Exception('Invalid date format');
            }
        } catch (Exception $e) {
            // Clear fields on error
            $this->data['shipment_date'] = null;  
            $this->data['shipment_year'] = null;
            $this->data['shipment_month'] = null;

            // Log the error for debugging
            error_log('Error parsing Shipment_Full_Date_: ' . $e->getMessage());
        }
    } else {
        // Clear the fields if Shipment_Full_Date_ is not set or empty
        $this->data['shipment_date'] = null;
        $this->data['shipment_year'] = null;
        $this->data['shipment_month'] = null;
    }
});

// Execute the calculation before persistence
if ($this->getCurrentMode() === 'create' || $this->getCurrentMode() === 'update') {
    $this->calculateShipmentDetails();
}