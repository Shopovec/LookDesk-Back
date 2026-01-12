<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LanguageFallbackService
{
    public function translate($model, $field, $lang)
    {
        $translation = $model->translations()
            ->where('lang', $lang)
            ->first();

        if ($translation && $translation->{$field}) {
            return $translation->{$field};
        }

        // Fallback → English
        $fallback = $model->translations()
            ->where('lang', 'en')
            ->first();

        if (!$fallback) {
            Log::warning("Missing translation fallback", [
                'model_id' => $model->id,
                'model_type' => get_class($model)
            ]);
        }

        return $fallback->{$field} ?? null;
    }
}
