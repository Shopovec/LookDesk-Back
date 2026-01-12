<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Document;
use App\Models\Category;
use App\Models\OcrScan;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index()
    {
        return $this->success([
            'documents_total'  => Document::count(),
            'categories_total' => Category::count(),
            'last_scans'       => OcrScan::latest()->take(5)->get(),
        ]);
    }
}
