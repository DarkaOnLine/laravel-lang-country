<?php

namespace InvolvedGroup\LaravelLangCountry\Services;

use Jenssegers\Date\Date;

/**
 * Class PreferedLanguage.
 */
class PreferedLanguage
{
    /**
     * @var \Illuminate\Support\Collection
     */
    protected $client_prefered;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $allowed;

    /**
     * HTTP_ACCEPT_LANGUAGE string.
     * @var
     */
    protected $prefered_languages;

    /**
     * @var string
     */
    protected $fallback;

    /**
     * calculated lang_country result.
     * @var string
     */
    public $lang_country;

    /**
     * Calculated locale result.
     * @var string
     */
    public $locale;

    /**
     * Calculated locale for Jenssegers\Date package.
     * @var string
     */
    public $locale_for_date;

    /**
     * PreferedLanguage constructor.
     * @param $prefered_languages
     */
    public function __construct($prefered_languages)
    {
        $this->prefered_languages = $prefered_languages;
        $this->fallback = config('lang-country.fallback');
        $this->allowed = collect(config('lang-country.allowed'));
        $this->client_prefered = $this->clientPreferedLanguages();
        $this->lang_country = $this->getLangCountry();
        $this->locale = $this->getLocale();
        $this->locale_for_date = $this->getLocaleForDate();
    }

    /**
     * It will return a list of prefered languages of the browser in order of preference.
     *
     * @return \Illuminate\Support\Collection
     */
    public function clientPreferedLanguages()
    {
        // regex inspired from @GabrielAnderson on http://stackoverflow.com/questions/6038236/http-accept-language
        preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})*)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $this->prefered_languages,
            $lang_parse);

        $langs = $lang_parse[1];

        // Make sure the country chars (when available) are uppercase.
        $langs = collect($langs)->map(function ($lang) {
            if (5 == strlen($lang)) {
                $lang = explode('-', $lang);
                $lang = implode('-', [$lang[0], strtoupper($lang[1])]);
            }

            return $lang;
        })->toArray();

        $ranks = $lang_parse[4];

        // (create an associative array 'language' => 'preference')
        $lang2pref = [];
        for ($i = 0; $i < count($langs); $i++) {
            $lang2pref[$langs[$i]] = (float) (! empty($ranks[$i]) ? $ranks[$i] : 1);
        }

        // (comparison function for uksort)
        $cmpLangs = function ($a, $b) use ($lang2pref) {
            if ($lang2pref[$a] > $lang2pref[$b]) {
                return -1;
            } elseif ($lang2pref[$a] < $lang2pref[$b]) {
                return 1;
            } elseif (strlen($a) > strlen($b)) {
                return -1;
            } elseif (strlen($a) < strlen($b)) {
                return 1;
            } else {
                return 0;
            }
        };

        // sort the languages by prefered language and by the most specific region
        uksort($lang2pref, $cmpLangs);

        return collect($lang2pref);
    }

    /**
     * It will find the first best match for lang_country according to the allowed list (from config file).
     *
     * @return \Illuminate\Config\Repository|int|mixed|string|static
     */
    protected function getLangCountry()
    {
        $prefered = $this->rewritePreferedToFourDigitValues();

        // Find exact match for 4 digits
        $prefered = $this->client_prefered->keys()->filter(function ($value) {
            return 5 == strlen($value);
        })->first(function ($value) {
            return $this->allowed->contains($value);
        });

        // Find first two digit (lang) match to four digit lang_country from the allowed-list
        if (null === $prefered) {
            $prefered = $this->client_prefered->keys()->filter(function ($value) {
                return 2 == strlen($value);
            })->map(function ($item) {
                return $this->allowed->filter(function ($value) use ($item) {
                    return $item == explode('-', $value)[0];
                })->first();
            })->reject(function ($value) {
                return $value === null;
            })->first();
        }

        // Get fallback if no results
        if (null === $prefered) {
            $prefered = $this->fallback;
        }

        return $prefered;
    }

    /**
     * @return string|null
     */
    private function rewritePreferedToFourDigitValues()
    {
        $prefered = $this->client_prefered->keys()->map(function ($value) {
            if (5 == strlen($value)) {
                return $value;
            } else {
                return $this->findFourDigitValueInOther($value);
            }
        })->reject(function ($value) {
            return $value === null;
        });

        return $prefered;
    }

    /**
     * @param $value
     * @return mixed
     */
    private function findFourDigitValueInOther($value)
    {
        return $this->allowed->filter(function ($item) use ($value) {
            return $value == explode('-', $value)[0];
        })->first();
    }

    /**
     * Check if 4 char language (ex. en-US.json) file exists in /resources/lang/ dir.
     * If not, just return the first two chars (represents the language).
     *
     * @return bool|\Illuminate\Config\Repository|int|PreferedLanguage|mixed|string
     */
    private function getLocale()
    {
        $path = resource_path('/lang/').$this->lang_country.'.json';

        if (file_exists($path)) {
            return $this->lang_country;
        } else {
            return substr($this->lang_country, 0, 2);
        }
    }

    /**
     * Check if Jenssegers\Date package supports this 4 char language code.
     * If not, just return the first two chars (represents the language).
     * Jenssegers\Date will default to its own fallback when this is also not available.
     */
    private function getLocaleForDate()
    {
        Date::setLocale($this->lang_country);

        if (str_replace('_', '-', Date::getLocale()) === $this->lang_country) {
            return $this->lang_country;
        } else {
            return substr($this->lang_country, 0, 2);
        }
    }
}
