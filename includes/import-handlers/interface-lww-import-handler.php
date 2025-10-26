<?php
/**
 * Interface LWW_Import_Handler_Interface
 * Definiert den "Vertrag", den jede Import-Handler-Klasse einhalten muss.
 */
if (!defined('ABSPATH')) exit;

interface LWW_Import_Handler_Interface {
    
    /**
     * Verarbeitet eine einzelne Zeile aus einer CSV-Datei.
     *
     * @param int $job_id ID des Job-Posts für Logging.
     * @param array $row_data Das numerische Array der CSV-Zeile von fgetcsv().
     * @param array $header_map Das assoziative Array [ 'spaltenname' => index ].
     * @return void
     */
    public function process_row($job_id, $row_data, $header_map);

}
