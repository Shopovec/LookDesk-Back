<?php

namespace App\Services;

use App\Models\OcrScan;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrService
{
    /**
     * Scan image using Tesseract OCR
     */
    public function scan($image, $userId)
    {
        // save original image
        $path = $image->store('ocr_uploads', 'public');

        // run tesseract
        $text = (new TesseractOCR(storage_path("app/public/{$path}")))
            ->lang('eng', 'ukr', 'rus', 'pol') // поддержка языков
            ->run();

        // store result in database
        $scan = OcrScan::create([
            'user_id'       => $userId,
            'image_path'    => $path,
            'extracted_text'=> $text,
            'meta'          => [
                'image_size' => $image->getSize(),
                'original_name' => $image->getClientOriginalName()
            ]
        ]);

        return [
            'text' => $text,
            'scan' => $scan
        ];
    }
}
