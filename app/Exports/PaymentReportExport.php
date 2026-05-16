<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class PaymentReportExport implements WithMultipleSheets
{
    protected $rows;
    protected $summary;

    public function __construct(array $rows, array $summary)
    {
        $this->rows    = $rows;
        $this->summary = $summary;
    }

    public function sheets(): array
    {
        return [
            new PaymentReportDetailSheet($this->rows),
            new PaymentReportSummarySheet($this->summary),
        ];
    }
}

class PaymentReportDetailSheet implements FromArray, WithHeadings, WithTitle
{
    protected $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function title(): string
    {
        return 'Payment Report';
    }

    public function headings(): array
    {
        return ['ID', 'Date', 'Department', 'Application No', 'Service', 'Order ID', 'GRN No', 'Payment Status', 'Amount'];
    }

    public function array(): array
    {
        return array_map(fn($row) => [
            $row['id'],
            $row['date'],
            $row['department'],
            $row['application_no'],
            $row['service'],
            $row['order_id'],
            $row['grn_no'],
            $row['payment_status'],
            $row['amount'],
        ], $this->rows);
    }
}

class PaymentReportSummarySheet implements FromArray, WithHeadings, WithTitle
{
    protected $summary;

    public function __construct(array $summary)
    {
        $this->summary = $summary;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return ['Department', 'Total Transactions', 'Paid', 'Pending', 'Failed', 'Total Amount'];
    }

    public function array(): array
    {
        return array_map(fn($row) => [
            $row['department'],
            $row['total_transactions'],
            $row['paid'],
            $row['pending'],
            $row['failed'],
            $row['total_amount'],
        ], $this->summary);
    }
}
