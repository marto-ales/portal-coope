<?php
// src/Controller/ReceiptController.php
namespace App\Controller;

use App\Service\GoogleDriveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReceiptController extends AbstractController
{
    private $googleDriveService;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
    }

    /**
     * @Route("/", name="payment_receipt", methods={"GET", "POST"})
     */
    public function index(Request $request): Response
    {
        $transferReceipts = [];
        $boxReceipts = [];

        if ($request->isMethod('POST')) {
            $idNumber = $request->request->get('id_number');
            $otherIdentifier = $request->request->get('other_identifier');
            $transferReceipts = $this->getTransferReceipts($idNumber, $otherIdentifier);
            $boxReceipts = $this->getBoxReceipts($idNumber, $otherIdentifier);
        }

        return $this->render('receipt/index.html.twig', [
            'transfer' => $transferReceipts,
            'box' => $boxReceipts,
        ]);
    }

    public function getTransferReceipts($idNumber, $otherIdentifier) {
        $data = [];
        $spreadsheetId = '11JLIQGS8Xg38Opjk_ucjiQJh0-4R1gRVBqftiVJtneE';
        $range = "'Respuestas de formulario 1'!A:Q"; // Ajusta según la estructura de tu planilla

        $values = $this->googleDriveService->getSpreadsheetData($spreadsheetId, $range);

        foreach ($values as $row) {
            if (array_key_exists(2,$row)) {
                if ($row[2] && $row[2] == $idNumber ) { //&& $row[1] == $otherIdentifier) {
                    $data[] = [
                        'name' => $row[1],
                        'student' => $row[5],
                        'item' => $row[9],
                        'desc' => $row[10],
                        'receipt' => $row[16],
                    ];
                }
            }
        }
        return $data;
    }

    public function getBoxReceipts($idNumber, $otherIdentifier) {
        $monthRows = [
            10 => 'Marzo',
            12 => 'Abril',
            14 => 'Mayo',
            16 => 'Junio',
            18 => 'Julio',
            20 => 'Agosto',
            22 => 'Septiembre',
            24 => 'Octubre',
            26 => 'Noviembre',
            28 => 'Diciembre',
        ];
        $data = [];
        $spreadsheetId = '1-1D3F9ZKAQrZDprTw-7bBs2ZyzN-dOmPnmZxIl5X4RE';
        $range = "'Por curso'!A:AC"; // Ajusta según la estructura de tu planilla

        $values = $this->googleDriveService->getSpreadsheetData($spreadsheetId, $range);

        foreach ($values as $row) {
            if (array_key_exists(2,$row)) {
                $id_clean = preg_replace('/[^0-9]/', '', $row[2]);
                if ($id_clean && $id_clean == $idNumber ) { //&& $row[1] == $otherIdentifier) {
                    foreach ($monthRows as $key => $month) {
                        if (array_key_exists($key,$row) && $row[$key]) {
                            $data[] = [
                                'name' => $row[1],
                                'student' => $row[7],
                                'month' => $month,
                                'receipt' => $row[$key],
                            ];
                        }
                    }
                }
            }
        }
        return $data;
    }
}
