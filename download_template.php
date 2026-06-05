<?php
/**
 * UFAA - Download Excel/CSV Upload Template
 */

// Force download as CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=ufaa_upload_template.csv');

// Open the output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel to display encoding and columns correctly
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Columns as specified by user:
// Owner Name, ID/Passport No, Date of Birth, Account Number, Last Transaction Date/Time, Due Amount, Compilation Date
fputcsv($output, [
    'Owner Name',
    'ID/Passport No',
    'Date of Birth',
    'Account Number',
    'Last Transaction Date/Time',
    'Due Amount',
    'Compilation Date'
]);

// Include a sample row to show formats
fputcsv($output, [
    'John Doe',
    'A1234567',
    '15/08/1985',
    '01102938475',
    '23/04/2026 14:30:00',
    '15000.00',
    '30/05/2026'
]);

fclose($output);
exit;
