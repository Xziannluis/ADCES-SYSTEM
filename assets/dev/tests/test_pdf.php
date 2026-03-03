<?php
require_once('fpdf/fpdf.php');

// Create new PDF
$pdf = new FPDF();
$pdf->AddPage();

// Set font
$pdf->SetFont('Arial','B',16);

// Add content
$pdf->Cell(0,10,'FPDF Test Successful!',0,1,'C');
$pdf->Cell(0,10,'Your FPDF installation is working correctly.',0,1,'C');

// Output PDF
$pdf->Output('I', 'test.pdf');
?>