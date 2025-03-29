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
    private $receiptsFolderId;
    private $trfSpreadsheetId;
    private $trfSpreadsheetRange;
    private $boxSpreadsheetId;
    private $boxSpreadsheetRange;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
        $this->receiptsFolderId = $_ENV['GOOGLE_REC_FOLDER_ID'];
        $this->trfSpreadsheetId = $_ENV['GOOGLE_TRF_SHEET_ID'];
        $this->trfSpreadsheetRange = $_ENV['GOOGLE_TRF_SHEET_RANGE'];
        $this->boxSpreadsheetId = $_ENV['GOOGLE_BOX_SHEET_ID'];
        $this->boxSpreadsheetRange = $_ENV['GOOGLE_BOX_SHEET_RANGE'];
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
            $email = $request->request->get('email');
            $transferReceipts = $this->getTransferReceipts($idNumber, $email);
            $boxReceipts = $this->getBoxReceipts($idNumber, $email);
        }

        return $this->render('receipt/index.html.twig', [
            'transfer' => $transferReceipts,
            'box' => $boxReceipts,
        ]);
    }

    public function getTransferReceipts($idNumber, $email) {
        $data = [];
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($email);
            $values = $this->googleDriveService->getSpreadsheetData($this->trfSpreadsheetId, $this->trfSpreadsheetRange);
            foreach ($values as $row) {
                if (array_key_exists(2,$row)) {
                    if ($row[2] && $row[2] == $idNumber && strtolower(trim($row[4])) == $email) {
                        if (array_key_exists(16,$row) && $row[16]) {
                            $receiptsFound = $this->googleDriveService->getFilesList($this->receiptsFolderId, $row[16]);
                            $receipt = null;
                            if ($receiptsFound) {
                                $receipt = $receiptsFound[0];
                                $data[] = [
                                    'name' => $row[1],
                                    'student' => $row[5],
                                    'item' => $row[9],
                                    'desc' => $row[10],
                                    'receiptFile' => $receipt->id,
                                ];
                            }
                        }
                    }
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

        $values = $this->googleDriveService->getSpreadsheetData($this->boxSpreadsheetId, $this->boxSpreadsheetRange);

        foreach ($values as $row) {
            if (array_key_exists(2,$row)) {
                $id_clean = preg_replace('/[^0-9]/', '', $row[2]);
                if ($id_clean && $id_clean == $idNumber ) { //&& $row[1] == $otherIdentifier) {
                    foreach ($monthRows as $key => $month) {
                        if (array_key_exists($key,$row) && $row[$key]) {
                            $receiptsFound = $this->googleDriveService->getFilesList($this->receiptsFolderId, $row[$key]);
                            $receipt = null;
                            if ($receiptsFound) {
                                $receipt = $receiptsFound[0];
                                $data[] = [
                                    'name' => $row[1],
                                    'student' => $row[7],
                                    'month' => $month,
                                    'receiptFile' => $receipt->id,
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $data;
    }
}
