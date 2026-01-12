<?php

namespace App\Traits;

trait HasTranslations
{
    public function translate($field, $lang = null)
    {
        $lang = $lang ?? app()->getLocale();

        $translation = $this->translations()
            ->where('lang', $lang)
            ->first();

        // Fallback на английский
        if (!$translation) {
            $translation = $this->translations()
                ->where('lang', 'en')
                ->first();
        }

        return $translation->{$field} ?? null;
    }
}