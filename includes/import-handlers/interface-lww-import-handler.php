<?php
/**
 * Interface LWW_Import_Handler_Interface (v16.0)
 * Definiert den "Vertrag", den jede Import-Handler-Klasse einhalten muss.
 */
if (!defined('ABSPATH')) exit;

interface LWW_Import_Handler_Interface {
    
    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile einer Datei verarbeitet wird.
     * @param int $job_id ID des Job-Posts.
     */
    public function start_job($job_id);

    /**
     * Verarbeitet eine einzelne Zeile aus einer CSV-Datei oder einem API-Ergebnis.
     *
     * @param int $job_id ID des Job-Posts für Logging.
     * @param array $row_data Das numerische Array der CSV-Zeile von fgetcsv() oder ein assoziatives Array von der API.
     * @param array $header_map Das assoziative Array [ 'spaltenname' => index ], bei API-Daten leer.
     * @return void
     */
    public function process_row($job_id, $row_data, $header_map);

    /**
     * Wird vom Importer aufgerufen, NACHDEM alle Zeilen einer Datei verarbeitet wurden.
     * @param int $job_id ID des Job-Posts.
     */
    public function finish_job($job_id);

}
