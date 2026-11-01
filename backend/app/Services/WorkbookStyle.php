<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared look for every generated workbook: Arial, DepEd blue header bands, thin borders.
 */
final class WorkbookStyle
{
    public const HEADER_FILL = 'DCE6F2';

    public const TITLE_FILL = '1F4E79';

    public const INPUT_FILL = 'FFF9DB';

    public static function workbook(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $spreadsheet->getProperties()->setCreator('IRIS')->setLastModifiedBy('IRIS');
        $spreadsheet->removeSheetByIndex(0);

        return $spreadsheet;
    }

    public static function sheet(Spreadsheet $spreadsheet, string $title, bool $landscape = false): Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize($landscape ? PageSetup::PAPERSIZE_LEGAL : PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);

        return $sheet;
    }

    /**
     * @param  array<string, float>  $widths  column => width
     */
    public static function columns(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    public static function banner(Worksheet $sheet, string $range, string $text, int $size = 14): void
    {
        self::merged($sheet, $range, $text);
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->setSize($size)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TITLE_FILL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension((int) preg_replace('/\D/', '', explode(':', $range)[0]))->setRowHeight($size * 2);
    }

    public static function title(Worksheet $sheet, string $range, string $text, int $size = 12, bool $center = false): void
    {
        self::merged($sheet, $range, $text);
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize($size);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal($center ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT)
            ->setWrapText(true);
    }

    public static function text(Worksheet $sheet, string $range, string $text, bool $italic = false): void
    {
        self::merged($sheet, $range, $text);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getFont()->setItalic($italic);
    }

    public static function header(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        self::borders($sheet, $range);
    }

    public static function borders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    public static function bold(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
    }

    public static function center(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    }

    public static function money(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    public static function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    /**
     * A name over a signature line, with its caption underneath.
     */
    public static function signature(Worksheet $sheet, string $nameRange, string $captionRange, ?string $name, string $caption): void
    {
        self::merged($sheet, $nameRange, $name === null ? null : mb_strtoupper($name));
        self::merged($sheet, $captionRange, $caption);
        $sheet->getStyle($nameRange)->getFont()->setBold(true);
        $sheet->getStyle($nameRange)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        self::center($sheet, $nameRange);
        self::center($sheet, $captionRange);
        $sheet->getStyle($captionRange)->getFont()->setSize(9)->setItalic(true);
    }

    /**
     * Writes paragraphs (definitions, notes) one per row, merged across the range's columns.
     *
     * @param  list<string|array{0: string, 1: int}>  $lines  text, or [text, indent level]
     */
    public static function paragraphs(Worksheet $sheet, int $row, string $fromColumn, string $toColumn, array $lines, int $charsPerLine = 110): int
    {
        foreach ($lines as $line) {
            [$text, $indent] = is_array($line) ? $line : [$line, 0];
            self::merged($sheet, "{$fromColumn}{$row}:{$toColumn}{$row}", $text);
            $alignment = $sheet->getStyle("{$fromColumn}{$row}")->getAlignment();
            $alignment->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP)->setIndent($indent * 2);
            $sheet->getRowDimension($row)->setRowHeight(max(1, (int) ceil(mb_strlen($text) / max(20, $charsPerLine - $indent * 6))) * 13.5);
            $row++;
        }

        return $row;
    }

    public static function save(Spreadsheet $spreadsheet): string
    {
        $spreadsheet->setActiveSheetIndex(0);
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private static function merged(Worksheet $sheet, string $range, ?string $text): void
    {
        $first = explode(':', $range)[0];
        $sheet->setCellValue($first, $text);

        if (str_contains($range, ':')) {
            $sheet->mergeCells($range);
        }
    }
}
