<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Translatable fields live in a sibling "<Model>Translation" table (locale, name, description).
 *
 * A value is resolved per field as: requested locale -> default locale (config app.locale) -> any
 * available translation. $model->name / $model->description use the current app locale.
 */
trait HasTranslations
{
    public function translations(): HasMany
    {
        return $this->hasMany(static::class.'Translation');
    }

    /** The translation row to show for a locale, with fallback. */
    public function translationFor(?string $locale = null): ?Model
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.locale'))
            ?? $this->translations->first();
    }

    /**
     * One translated field. Falls back per field: an empty value in the requested language
     * (e.g. a missing English description) uses the default language, then any other translation.
     */
    public function translate(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        foreach ([$locale, config('app.locale')] as $candidate) {
            $value = $this->translations->firstWhere('locale', $candidate)?->{$field};

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $this->translations->pluck($field)->first(fn ($value) => $value !== null && $value !== '');
    }

    /** Create or update the translation for one locale. */
    public function setTranslation(string $locale, array $attributes): static
    {
        if (! in_array($locale, config('app.available_locales'), true)) {
            throw new InvalidArgumentException("Unsupported locale [$locale].");
        }

        $this->translations()->updateOrCreate(['locale' => $locale], $attributes);
        $this->unsetRelation('translations');

        return $this;
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->translate('name') ?? $this->slug);
    }

    protected function description(): Attribute
    {
        return Attribute::get(fn () => $this->translate('description'));
    }
}
