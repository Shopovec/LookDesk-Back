<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

class Schemas
{
    /* ========== USER ========== */
    #[OA\Schema(
        schema: "User",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "name", type: "string"),
            new OA\Property(property: "email", type: "string"),
            new OA\Property(property: "role_id", type: "integer"),
            new OA\Property(property: "role", ref: "#/components/schemas/Role"),
            new OA\Property(property: "created_at", type: "string", format: "date-time"),
            new OA\Property(property: "updated_at", type: "string", format: "date-time"),
        ]
    )]
    public static function User() {}


    /* ========== ROLE ========== */
    #[OA\Schema(
        schema: "Role",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "name", type: "string"),
        ]
    )]
    public static function Role() {}


    /* ========== CATEGORY ========== */
    #[OA\Schema(
        schema: "Category",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "title", type: "string"),
            new OA\Property(property: "description", type: "string"),
            new OA\Property(property: "parent_id", type: "integer", nullable: true),
            new OA\Property(property: "translations", type: "array",
                items: new OA\Items(ref: "#/components/schemas/CategoryTranslation")
            ),
            new OA\Property(property: "created_at", type: "string", format: "date-time"),
            new OA\Property(property: "updated_at", type: "string", format: "date-time"),
        ]
    )]
    public static function Category() {}


    /* ========== CATEGORY TRANSLATION ========== */
    #[OA\Schema(
        schema: "CategoryTranslation",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "category_id", type: "integer"),
            new OA\Property(property: "lang", type: "string"),
            new OA\Property(property: "title", type: "string"),
            new OA\Property(property: "description", type: "string"),
        ]
    )]
    public static function CategoryTranslation() {}


    /* ========== DOCUMENT ========== */
    #[OA\Schema(
        schema: "Document",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "title", type: "string"),
            new OA\Property(property: "content", type: "string"),
            new OA\Property(property: "category_id", type: "integer"),
            new OA\Property(property: "file_path", type: "string"),
            new OA\Property(property: "translations", type: "array",
                items: new OA\Items(ref: "#/components/schemas/DocumentTranslation")
            ),
            new OA\Property(property: "created_at", type: "string", format: "date-time"),
            new OA\Property(property: "updated_at", type: "string", format: "date-time"),
        ]
    )]
    public static function Document() {}


    /* ========== DOCUMENT TRANSLATION ========== */
    #[OA\Schema(
        schema: "DocumentTranslation",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "document_id", type: "integer"),
            new OA\Property(property: "lang", type: "string"),
            new OA\Property(property: "title", type: "string"),
            new OA\Property(property: "content", type: "string"),
        ]
    )]
    public static function DocumentTranslation() {}


    /* ========== OCR SCAN ========== */
    #[OA\Schema(
        schema: "OcrScan",
        properties: [
            new OA\Property(property: "id", type: "integer"),
            new OA\Property(property: "user_id", type: "integer"),
            new OA\Property(property: "image_path", type: "string"),
            new OA\Property(property: "extracted_text", type: "string"),
            new OA\Property(
                property: "meta",
                type: "object",
                example: ["lang" => "en", "confidence" => 0.97]
            ),
            new OA\Property(property: "created_at", type: "string", format: "date-time"),
        ]
    )]
    public static function OcrScan() {}


    /* ========== AUTH RESPONSE ========== */
    #[OA\Schema(
        schema: "AuthResponse",
        properties: [
            new OA\Property(property: "token", type: "string"),
            new OA\Property(property: "user", ref: "#/components/schemas/User"),
        ]
    )]
    public static function AuthResponse() {}


    /* ========== DASHBOARD ITEM SCHEMA ========== */
    #[OA\Schema(
        schema: "DashboardStatsItem",
        properties: [
            new OA\Property(property: "date", type: "string", example: "2025-01-10"),
            new OA\Property(property: "count", type: "integer", example: 12),
        ]
    )]
    public static function DashboardStatsItem() {}


    /* ========== DASHBOARD DATA ========== */
    #[OA\Schema(
        schema: "DashboardData",
        properties: [
            new OA\Property(property: "documents_total", type: "integer"),
            new OA\Property(property: "categories_total", type: "integer"),
            new OA\Property(property: "translations_total", type: "integer"),
            new OA\Property(property: "ocr_total", type: "integer"),
            new OA\Property(property: "my_ocr_total", type: "integer"),
            new OA\Property(property: "latest_documents", type: "array", items: new OA\Items(ref: "#/components/schemas/Document")),
            new OA\Property(property: "latest_ocr", type: "array", items: new OA\Items(ref: "#/components/schemas/OcrScan")),
            new OA\Property(property: "documents_per_day", type: "array", items: new OA\Items(ref: "#/components/schemas/DashboardStatsItem")),
            new OA\Property(property: "categories_usage", type: "array", items: new OA\Items(ref: "#/components/schemas/Category")),
        ]
    )]
    public static function DashboardData() {}
}
