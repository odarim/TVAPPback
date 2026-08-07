<?php

namespace App\Service;

/**
 * Resolves a channel's language from its country value, whatever format
 * that value happens to be in (2-letter ISO 3166-1 country code, or a
 * full country name). Always returns a 3-letter ISO 639-2/B language
 * code (e.g. "eng", "fra", "ara") to match the existing Channel::$language
 * values already in the database — and NEVER returns null, so every
 * channel ends up with something in that column.
 *
 * Usage:
 *   $channel->setLanguage(CountryLanguageMapper::getLanguageCode($channel->getCountry()));
 */
class CountryLanguageMapper
{
    /** Fallback ISO 639-2 code meaning "language could not be determined" */
    private const UNKNOWN = 'und';

    /**
     * ISO 3166-1 alpha-2 country code (lowercase) => ISO 639-2/B language code.
     * This is the primary lookup, since your DB mostly stores 2-letter codes.
     */
    private const CODE_MAP = [
        'af' => 'pus', 'al' => 'sqi', 'dz' => 'ara', 'ad' => 'cat', 'ao' => 'por',
        'ag' => 'eng', 'ar' => 'spa', 'am' => 'hye', 'au' => 'eng', 'at' => 'deu',
        'az' => 'aze', 'bs' => 'eng', 'bh' => 'ara', 'bd' => 'ben', 'bb' => 'eng',
        'by' => 'bel', 'be' => 'nld', 'bz' => 'eng', 'bj' => 'fra', 'bt' => 'dzo',
        'bo' => 'spa', 'ba' => 'bos', 'bw' => 'eng', 'br' => 'por', 'bn' => 'msa',
        'bg' => 'bul', 'bf' => 'fra', 'bi' => 'fra', 'kh' => 'khm', 'cm' => 'fra',
        'ca' => 'eng', 'cv' => 'por', 'cf' => 'fra', 'td' => 'fra', 'cl' => 'spa',
        'cn' => 'zho', 'co' => 'spa', 'km' => 'ara', 'cg' => 'fra', 'cd' => 'fra',
        'cr' => 'spa', 'hr' => 'hrv', 'cu' => 'spa', 'cy' => 'ell', 'cz' => 'ces',
        'dk' => 'dan', 'dj' => 'fra', 'dm' => 'eng', 'do' => 'spa', 'ec' => 'spa',
        'eg' => 'ara', 'sv' => 'spa', 'gq' => 'spa', 'er' => 'tir', 'ee' => 'est',
        'sz' => 'eng', 'et' => 'amh', 'fj' => 'eng', 'fi' => 'fin', 'fr' => 'fra',
        'ga' => 'fra', 'gm' => 'eng', 'ge' => 'kat', 'de' => 'deu', 'gh' => 'eng',
        'gr' => 'ell', 'gl' => 'dan', 'gd' => 'eng', 'gt' => 'spa', 'gn' => 'fra',
        'gw' => 'por', 'gy' => 'eng', 'ht' => 'fra', 'hn' => 'spa', 'hk' => 'zho',
        'hu' => 'hun', 'is' => 'isl', 'in' => 'hin', 'id' => 'ind', 'ir' => 'fas',
        'iq' => 'ara', 'ie' => 'eng', 'il' => 'heb', 'it' => 'ita', 'jm' => 'eng',
        'jp' => 'jpn', 'jo' => 'ara', 'kz' => 'kaz', 'ke' => 'swa', 'ki' => 'eng',
        'kp' => 'kor', 'kr' => 'kor', 'kw' => 'ara', 'kg' => 'kir', 'la' => 'lao',
        'lv' => 'lav', 'lb' => 'ara', 'ls' => 'eng', 'lr' => 'eng', 'ly' => 'ara',
        'li' => 'deu', 'lt' => 'lit', 'lu' => 'fra', 'mo' => 'zho', 'mg' => 'mlg',
        'mw' => 'eng', 'my' => 'msa', 'mv' => 'div', 'ml' => 'fra', 'mt' => 'mlt',
        'mh' => 'eng', 'mr' => 'ara', 'mu' => 'fra', 'mx' => 'spa', 'fm' => 'eng',
        'md' => 'ron', 'mc' => 'fra', 'mn' => 'mon', 'me' => 'srp', 'ma' => 'ara',
        'mz' => 'por', 'mm' => 'mya', 'na' => 'eng', 'nr' => 'eng', 'np' => 'nep',
        'nl' => 'nld', 'nz' => 'eng', 'ni' => 'spa', 'ne' => 'fra', 'ng' => 'eng',
        'mk' => 'mkd', 'no' => 'nor', 'om' => 'ara', 'pk' => 'urd', 'pw' => 'eng',
        'ps' => 'ara', 'pa' => 'spa', 'pg' => 'eng', 'py' => 'spa', 'pe' => 'spa',
        'ph' => 'fil', 'pl' => 'pol', 'pt' => 'por', 'pr' => 'spa', 'qa' => 'ara',
        'ro' => 'ron', 'ru' => 'rus', 'rw' => 'kin', 'kn' => 'eng', 'lc' => 'eng',
        'vc' => 'eng', 'ws' => 'eng', 'sm' => 'ita', 'st' => 'por', 'sa' => 'ara',
        'sn' => 'fra', 'rs' => 'srp', 'sc' => 'fra', 'sl' => 'eng', 'sg' => 'eng',
        'sk' => 'slk', 'si' => 'slv', 'sb' => 'eng', 'so' => 'som', 'za' => 'eng',
        'ss' => 'eng', 'es' => 'spa', 'lk' => 'sin', 'sd' => 'ara', 'sr' => 'nld',
        'se' => 'swe', 'ch' => 'deu', 'sy' => 'ara', 'tw' => 'zho', 'tj' => 'tgk',
        'tz' => 'swa', 'th' => 'tha', 'tl' => 'por', 'tg' => 'fra', 'to' => 'eng',
        'tt' => 'eng', 'tn' => 'ara', 'tr' => 'tur', 'tm' => 'tuk', 'tv' => 'eng',
        'ug' => 'eng', 'ua' => 'ukr', 'ae' => 'ara', 'gb' => 'eng', 'uk' => 'eng',
        'us' => 'eng', 'uy' => 'spa', 'uz' => 'uzb', 'vu' => 'fra', 'va' => 'ita',
        've' => 'spa', 'vn' => 'vie', 'eh' => 'ara', 'ye' => 'ara', 'zm' => 'eng',
        'zw' => 'eng', 'xk' => 'sqi',
    ];

    /**
     * Full country name (any casing/spelling handled by normalize()) => code.
     * Reused for the rarer rows where "country" was stored as a full name
     * (e.g. "Germany") instead of a 2-letter code.
     */
    private const NAME_MAP = [
        'afghanistan' => 'pus', 'albania' => 'sqi', 'algeria' => 'ara', 'andorra' => 'cat',
        'angola' => 'por', 'argentina' => 'spa', 'armenia' => 'hye', 'australia' => 'eng',
        'austria' => 'deu', 'azerbaijan' => 'aze', 'bahrain' => 'ara', 'bangladesh' => 'ben',
        'belarus' => 'bel', 'belgium' => 'nld', 'benin' => 'fra', 'bhutan' => 'dzo',
        'bolivia' => 'spa', 'bosnia' => 'bos', 'bosnia and herzegovina' => 'bos',
        'botswana' => 'eng', 'brazil' => 'por', 'brunei' => 'msa', 'bulgaria' => 'bul',
        'burkina faso' => 'fra', 'burundi' => 'fra', 'cambodia' => 'khm', 'cameroon' => 'fra',
        'canada' => 'eng', 'cape verde' => 'por', 'chad' => 'fra', 'chile' => 'spa',
        'china' => 'zho', 'colombia' => 'spa', 'congo' => 'fra', 'costa rica' => 'spa',
        'croatia' => 'hrv', 'cuba' => 'spa', 'cyprus' => 'ell', 'czech republic' => 'ces',
        'czechia' => 'ces', 'denmark' => 'dan', 'djibouti' => 'fra', 'dominican republic' => 'spa',
        'dr congo' => 'fra', 'congo kinshasa' => 'fra', 'democratic republic of the congo' => 'fra',
        'east timor' => 'por', 'timor leste' => 'por', 'ecuador' => 'spa', 'egypt' => 'ara',
        'el salvador' => 'spa', 'equatorial guinea' => 'spa', 'eritrea' => 'tir',
        'estonia' => 'est', 'eswatini' => 'eng', 'swaziland' => 'eng', 'ethiopia' => 'amh',
        'fiji' => 'eng', 'finland' => 'fin', 'france' => 'fra', 'gabon' => 'fra',
        'gambia' => 'eng', 'georgia' => 'kat', 'germany' => 'deu', 'ghana' => 'eng',
        'greece' => 'ell', 'greenland' => 'dan', 'guatemala' => 'spa', 'guinea' => 'fra',
        'guinea-bissau' => 'por', 'haiti' => 'fra', 'honduras' => 'spa', 'hong kong' => 'zho',
        'hungary' => 'hun', 'iceland' => 'isl', 'india' => 'hin', 'indonesia' => 'ind',
        'iran' => 'fas', 'iraq' => 'ara', 'ireland' => 'eng', 'israel' => 'heb',
        'italy' => 'ita', 'ivory coast' => 'fra', 'cote d ivoire' => 'fra', 'jamaica' => 'eng',
        'japan' => 'jpn', 'jordan' => 'ara', 'kazakhstan' => 'kaz', 'kenya' => 'swa',
        'kosovo' => 'sqi', 'kuwait' => 'ara', 'kyrgyzstan' => 'kir', 'laos' => 'lao',
        'latvia' => 'lav', 'lebanon' => 'ara', 'liberia' => 'eng', 'libya' => 'ara',
        'liechtenstein' => 'deu', 'lithuania' => 'lit', 'luxembourg' => 'fra', 'macau' => 'zho',
        'madagascar' => 'mlg', 'malawi' => 'eng', 'malaysia' => 'msa', 'maldives' => 'div',
        'mali' => 'fra', 'malta' => 'mlt', 'mauritania' => 'ara', 'mauritius' => 'fra',
        'mexico' => 'spa', 'moldova' => 'ron', 'monaco' => 'fra', 'mongolia' => 'mon',
        'montenegro' => 'srp', 'morocco' => 'ara', 'mozambique' => 'por', 'myanmar' => 'mya',
        'burma' => 'mya', 'namibia' => 'eng', 'nepal' => 'nep', 'netherlands' => 'nld',
        'new zealand' => 'eng', 'nicaragua' => 'spa', 'niger' => 'fra', 'nigeria' => 'eng',
        'north korea' => 'kor', 'north macedonia' => 'mkd', 'macedonia' => 'mkd',
        'norway' => 'nor', 'oman' => 'ara', 'pakistan' => 'urd', 'palestine' => 'ara',
        'panama' => 'spa', 'papua new guinea' => 'eng', 'paraguay' => 'spa', 'peru' => 'spa',
        'philippines' => 'fil', 'poland' => 'pol', 'portugal' => 'por', 'puerto rico' => 'spa',
        'qatar' => 'ara', 'romania' => 'ron', 'russia' => 'rus', 'russian federation' => 'rus',
        'rwanda' => 'kin', 'saudi arabia' => 'ara', 'arabia' => 'ara', 'saudi' => 'ara',
        'ksa' => 'ara', 'senegal' => 'fra', 'serbia' => 'srp', 'sierra leone' => 'eng',
        'singapore' => 'eng', 'slovakia' => 'slk', 'slovenia' => 'slv', 'somalia' => 'som',
        'south africa' => 'eng', 'south korea' => 'kor', 'south sudan' => 'eng',
        'spain' => 'spa', 'sri lanka' => 'sin', 'sudan' => 'ara', 'suriname' => 'nld',
        'sweden' => 'swe', 'switzerland' => 'deu', 'syria' => 'ara', 'taiwan' => 'zho',
        'tajikistan' => 'tgk', 'tanzania' => 'swa', 'thailand' => 'tha', 'togo' => 'fra',
        'tunisia' => 'ara', 'turkey' => 'tur', 'turkmenistan' => 'tuk', 'uganda' => 'eng',
        'ukraine' => 'ukr', 'united arab emirates' => 'ara', 'uae' => 'ara',
        'emirates' => 'ara', 'united kingdom' => 'eng', 'uk' => 'eng', 'great britain' => 'eng',
        'britain' => 'eng', 'england' => 'eng', 'scotland' => 'eng', 'wales' => 'eng',
        'northern ireland' => 'eng', 'united states' => 'eng', 'usa' => 'eng',
        'america' => 'eng', 'united states of america' => 'eng', 'uruguay' => 'spa',
        'uzbekistan' => 'uzb', 'vatican' => 'ita', 'vatican city' => 'ita', 'holy see' => 'ita',
        'venezuela' => 'spa', 'vietnam' => 'vie', 'western sahara' => 'ara', 'yemen' => 'ara',
        'zambia' => 'eng', 'zimbabwe' => 'eng',
    ];

    /**
     * Always returns a language code — never null — so no channel is
     * left without a language after a bulk backfill.
     */
    public static function getLanguageCode(?string $country): string
    {
        $normalized = self::normalize($country);
        if ($normalized === '') {
            return self::UNKNOWN;
        }

        // 2-letter ISO country code — the format most of your DB uses
        if (isset(self::CODE_MAP[$normalized])) {
            return self::CODE_MAP[$normalized];
        }

        // Full country name (exact)
        if (isset(self::NAME_MAP[$normalized])) {
            return self::NAME_MAP[$normalized];
        }

        // Word-overlap fuzzy match against full names (handles "Republic of X" etc.)
        $normWords = explode(' ', $normalized);
        foreach (self::NAME_MAP as $key => $code) {
            $keyWords = explode(' ', $key);
            if ($keyWords !== [''] && count(array_intersect($keyWords, $normWords)) === count($keyWords)) {
                return $code;
            }
        }

        return self::UNKNOWN;
    }

    /**
     * ISO 3166-1 alpha-2 code (lowercase) => proper full country name.
     * Used by getFullCountryName() so Channel::$country always displays
     * as a real name instead of a raw 2-letter code.
     */
    private const CODE_TO_NAME = [
        'af' => 'Afghanistan', 'al' => 'Albania', 'dz' => 'Algeria', 'ad' => 'Andorra',
        'ao' => 'Angola', 'ag' => 'Antigua and Barbuda', 'ar' => 'Argentina', 'am' => 'Armenia',
        'au' => 'Australia', 'at' => 'Austria', 'az' => 'Azerbaijan', 'bs' => 'Bahamas',
        'bh' => 'Bahrain', 'bd' => 'Bangladesh', 'bb' => 'Barbados', 'by' => 'Belarus',
        'be' => 'Belgium', 'bz' => 'Belize', 'bj' => 'Benin', 'bt' => 'Bhutan',
        'bo' => 'Bolivia', 'ba' => 'Bosnia and Herzegovina', 'bw' => 'Botswana', 'br' => 'Brazil',
        'bn' => 'Brunei', 'bg' => 'Bulgaria', 'bf' => 'Burkina Faso', 'bi' => 'Burundi',
        'kh' => 'Cambodia', 'cm' => 'Cameroon', 'ca' => 'Canada', 'cv' => 'Cape Verde',
        'cf' => 'Central African Republic', 'td' => 'Chad', 'cl' => 'Chile', 'cn' => 'China',
        'co' => 'Colombia', 'km' => 'Comoros', 'cg' => 'Congo', 'cd' => 'DR Congo',
        'cr' => 'Costa Rica', 'hr' => 'Croatia', 'cu' => 'Cuba', 'cy' => 'Cyprus',
        'cz' => 'Czech Republic', 'dk' => 'Denmark', 'dj' => 'Djibouti', 'dm' => 'Dominica',
        'do' => 'Dominican Republic', 'ec' => 'Ecuador', 'eg' => 'Egypt', 'sv' => 'El Salvador',
        'gq' => 'Equatorial Guinea', 'er' => 'Eritrea', 'ee' => 'Estonia', 'sz' => 'Eswatini',
        'et' => 'Ethiopia', 'fj' => 'Fiji', 'fi' => 'Finland', 'fr' => 'France',
        'ga' => 'Gabon', 'gm' => 'Gambia', 'ge' => 'Georgia', 'de' => 'Germany',
        'gh' => 'Ghana', 'gr' => 'Greece', 'gl' => 'Greenland', 'gd' => 'Grenada',
        'gt' => 'Guatemala', 'gn' => 'Guinea', 'gw' => 'Guinea-Bissau', 'gy' => 'Guyana',
        'ht' => 'Haiti', 'hn' => 'Honduras', 'hk' => 'Hong Kong', 'hu' => 'Hungary',
        'is' => 'Iceland', 'in' => 'India', 'id' => 'Indonesia', 'ir' => 'Iran',
        'iq' => 'Iraq', 'ie' => 'Ireland', 'il' => 'Israel', 'it' => 'Italy',
        'jm' => 'Jamaica', 'jp' => 'Japan', 'jo' => 'Jordan', 'kz' => 'Kazakhstan',
        'ke' => 'Kenya', 'ki' => 'Kiribati', 'kp' => 'North Korea', 'kr' => 'South Korea',
        'kw' => 'Kuwait', 'kg' => 'Kyrgyzstan', 'la' => 'Laos', 'lv' => 'Latvia',
        'lb' => 'Lebanon', 'ls' => 'Lesotho', 'lr' => 'Liberia', 'ly' => 'Libya',
        'li' => 'Liechtenstein', 'lt' => 'Lithuania', 'lu' => 'Luxembourg', 'mo' => 'Macau',
        'mg' => 'Madagascar', 'mw' => 'Malawi', 'my' => 'Malaysia', 'mv' => 'Maldives',
        'ml' => 'Mali', 'mt' => 'Malta', 'mh' => 'Marshall Islands', 'mr' => 'Mauritania',
        'mu' => 'Mauritius', 'mx' => 'Mexico', 'fm' => 'Micronesia', 'md' => 'Moldova',
        'mc' => 'Monaco', 'mn' => 'Mongolia', 'me' => 'Montenegro', 'ma' => 'Morocco',
        'mz' => 'Mozambique', 'mm' => 'Myanmar', 'na' => 'Namibia', 'nr' => 'Nauru',
        'np' => 'Nepal', 'nl' => 'Netherlands', 'nz' => 'New Zealand', 'ni' => 'Nicaragua',
        'ne' => 'Niger', 'ng' => 'Nigeria', 'mk' => 'North Macedonia', 'no' => 'Norway',
        'om' => 'Oman', 'pk' => 'Pakistan', 'pw' => 'Palau', 'ps' => 'Palestine',
        'pa' => 'Panama', 'pg' => 'Papua New Guinea', 'py' => 'Paraguay', 'pe' => 'Peru',
        'ph' => 'Philippines', 'pl' => 'Poland', 'pt' => 'Portugal', 'pr' => 'Puerto Rico',
        'qa' => 'Qatar', 'ro' => 'Romania', 'ru' => 'Russia', 'rw' => 'Rwanda',
        'kn' => 'Saint Kitts and Nevis', 'lc' => 'Saint Lucia', 'vc' => 'Saint Vincent and the Grenadines',
        'ws' => 'Samoa', 'sm' => 'San Marino', 'st' => 'Sao Tome and Principe', 'sa' => 'Saudi Arabia',
        'sn' => 'Senegal', 'rs' => 'Serbia', 'sc' => 'Seychelles', 'sl' => 'Sierra Leone',
        'sg' => 'Singapore', 'sk' => 'Slovakia', 'si' => 'Slovenia', 'sb' => 'Solomon Islands',
        'so' => 'Somalia', 'za' => 'South Africa', 'ss' => 'South Sudan', 'es' => 'Spain',
        'lk' => 'Sri Lanka', 'sd' => 'Sudan', 'sr' => 'Suriname', 'se' => 'Sweden',
        'ch' => 'Switzerland', 'sy' => 'Syria', 'tw' => 'Taiwan', 'tj' => 'Tajikistan',
        'tz' => 'Tanzania', 'th' => 'Thailand', 'tl' => 'East Timor', 'tg' => 'Togo',
        'to' => 'Tonga', 'tt' => 'Trinidad and Tobago', 'tn' => 'Tunisia', 'tr' => 'Turkey',
        'tm' => 'Turkmenistan', 'tv' => 'Tuvalu', 'ug' => 'Uganda', 'ua' => 'Ukraine',
        'ae' => 'United Arab Emirates', 'gb' => 'United Kingdom', 'uk' => 'United Kingdom',
        'us' => 'United States', 'uy' => 'Uruguay', 'uz' => 'Uzbekistan', 'vu' => 'Vanuatu',
        'va' => 'Vatican City', 've' => 'Venezuela', 'vn' => 'Vietnam', 'eh' => 'Western Sahara',
        'ye' => 'Yemen', 'zm' => 'Zambia', 'zw' => 'Zimbabwe', 'xk' => 'Kosovo',
    ];

    /**
     * Messy/alternate full-name spellings (as seen from LiveWatch, e.g.
     * "GERMANY", "Arabia", "France Sport") => the proper canonical name.
     * Deliberately reuses the same alias vocabulary as NAME_MAP/ALIASES
     * above, but maps to a display name rather than a language code.
     */
    private const NAME_ALIASES = [
        'usa' => 'United States', 'america' => 'United States', 'united states of america' => 'United States',
        'uk' => 'United Kingdom', 'great britain' => 'United Kingdom', 'britain' => 'United Kingdom',
        'england' => 'United Kingdom', 'scotland' => 'United Kingdom', 'wales' => 'United Kingdom',
        'northern ireland' => 'United Kingdom', 'arabia' => 'Saudi Arabia', 'saudi' => 'Saudi Arabia',
        'ksa' => 'Saudi Arabia', 'uae' => 'United Arab Emirates', 'emirates' => 'United Arab Emirates',
        'korea south' => 'South Korea', 'republic of korea' => 'South Korea', 'korea north' => 'North Korea',
        'dprk' => 'North Korea', 'congo kinshasa' => 'DR Congo', 'democratic republic of the congo' => 'DR Congo',
        'drc' => 'DR Congo', 'congo brazzaville' => 'Congo', 'republic of the congo' => 'Congo',
        'macedonia' => 'North Macedonia', 'czechia' => 'Czech Republic', 'russian federation' => 'Russia',
        'vatican' => 'Vatican City', 'holy see' => 'Vatican City', 'cote d ivoire' => 'Ivory Coast',
        'timor leste' => 'East Timor', 'swaziland' => 'Eswatini', 'burma' => 'Myanmar',
        'bosnia' => 'Bosnia and Herzegovina', 'brunei darussalam' => 'Brunei',
    ];

    /**
     * Full-name-only entries not derivable from CODE_TO_NAME above
     * (mostly ones with no single obvious 2-letter code mapping used elsewhere,
     * or that only ever appear as full names in your feeds).
     */
    private const EXTRA_NAMES = [
        'ivory coast' => 'Ivory Coast',
    ];

    /**
     * Resolves any country value (2-letter code, full name, messy casing,
     * or a name with extra noise like "France Sport") into a clean, proper
     * full country name. Never returns an empty string for non-empty input —
     * worst case it title-cases whatever was given so no data is silently lost.
     */
    public static function getFullCountryName(?string $country): string
    {
        $original = trim((string) $country);
        if ($original === '') {
            return 'Unknown';
        }

        $normalized = self::normalize($country);
        if ($normalized === '') {
            return 'Unknown';
        }

        // 2-letter ISO country code
        if (isset(self::CODE_TO_NAME[$normalized])) {
            return self::CODE_TO_NAME[$normalized];
        }

        // Build a normalized-name => proper-name index once (from CODE_TO_NAME's
        // values, since those are already the canonical proper names)
        static $properByNormalized = null;
        if ($properByNormalized === null) {
            $properByNormalized = [];
            foreach (self::CODE_TO_NAME as $properName) {
                $properByNormalized[self::normalize($properName)] = $properName;
            }
            foreach (self::EXTRA_NAMES as $properName) {
                $properByNormalized[self::normalize($properName)] = $properName;
            }
        }

        // Exact full-name match
        if (isset($properByNormalized[$normalized])) {
            return $properByNormalized[$normalized];
        }

        // Alias match (e.g. "arabia" -> "Saudi Arabia")
        if (isset(self::NAME_ALIASES[$normalized])) {
            return self::NAME_ALIASES[$normalized];
        }

        // Word-overlap fuzzy match (handles "France Sport", "Republic of X", etc.)
        $normWords = explode(' ', $normalized);
        foreach ($properByNormalized as $key => $properName) {
            $keyWords = explode(' ', $key);
            if ($keyWords !== [''] && count(array_intersect($keyWords, $normWords)) === count($keyWords)) {
                return $properName;
            }
        }
        foreach (self::NAME_ALIASES as $aliasKey => $properName) {
            $aliasWords = explode(' ', $aliasKey);
            if ($aliasWords !== [''] && count(array_intersect($aliasWords, $normWords)) === count($aliasWords)) {
                return $properName;
            }
        }

        // Nothing recognized — return a cleaned-up, title-cased version of
        // whatever was originally given (e.g. "Balkans" stays "Balkans")
        // rather than discarding the value.
        return mb_convert_case(trim($original), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * ISO 639-2/B language code => display flag + English name.
     * Used to label the individual sources of a merged channel so users
     * can tell them apart (e.g. "🇬🇧 English", "🇫🇷 French").
     */
    private const LANG_DISPLAY = [
        'eng' => 'English', 'fra' => 'French', 'spa' => 'Spanish', 'deu' => 'German',
        'ita' => 'Italian', 'tur' => 'Turkish', 'rus' => 'Russian', 'ara' => 'Arabic',
        'por' => 'Portuguese', 'zho' => 'Chinese', 'jpn' => 'Japanese', 'kor' => 'Korean',
        'hin' => 'Hindi', 'ben' => 'Bengali', 'urd' => 'Urdu', 'fas' => 'Persian',
        'heb' => 'Hebrew', 'nld' => 'Dutch', 'swe' => 'Swedish', 'nor' => 'Norwegian',
        'dan' => 'Danish', 'fin' => 'Finnish', 'pol' => 'Polish', 'ces' => 'Czech',
        'slk' => 'Slovak', 'hun' => 'Hungarian', 'ell' => 'Greek', 'ron' => 'Romanian',
        'bul' => 'Bulgarian', 'srp' => 'Serbian', 'hrv' => 'Croatian', 'ukr' => 'Ukrainian',
        'kat' => 'Georgian', 'hye' => 'Armenian', 'aze' => 'Azerbaijani', 'sqi' => 'Albanian',
        'bos' => 'Bosnian', 'mak' => 'Macedonian', 'lit' => 'Lithuanian', 'lav' => 'Latvian',
        'est' => 'Estonian', 'mlt' => 'Maltese', 'isl' => 'Icelandic', 'vie' => 'Vietnamese',
        'tha' => 'Thai', 'msa' => 'Malay', 'ind' => 'Indonesian', 'fil' => 'Filipino',
        'khm' => 'Khmer', 'lao' => 'Lao', 'mya' => 'Burmese', 'nep' => 'Nepali',
        'sin' => 'Sinhala', 'tam' => 'Tamil', 'tel' => 'Telugu', 'mar' => 'Marathi',
        'amh' => 'Amharic', 'swa' => 'Swahili', 'kin' => 'Kinyarwanda', 'mlg' => 'Malagasy',
        'som' => 'Somali', 'div' => 'Dhivehi', 'tgk' => 'Tajik', 'uzb' => 'Uzbek',
        'tuk' => 'Turkmen', 'kir' => 'Kyrgyz', 'mon' => 'Mongolian', 'kat' => 'Georgian',
        'bis' => 'Bisaya', 'ban' => 'Balinese', 'ces' => 'Czech', 'pus' => 'Pashto',
        'slv' => 'Slovenian', 'cat' => 'Catalan', 'glg' => 'Galician', 'eus' => 'Basque',
        'cym' => 'Welsh', 'gla' => 'Gaelic', 'dzo' => 'Dzongkha', 'tir' => 'Tigrinya',
        'sqi' => 'Albanian', 'kaz' => 'Kazakh',
    ];

    /**
     * Builds a short, human-readable label for a merged channel stream so
     * users can tell the sources apart in the player. Prefers a language
     * name and falls back to the country, then null.
     */
    public static function getStreamLabel(?string $language, ?string $country): ?string
    {
        $lang = $language !== null ? trim($language) : '';
        if (isset(self::LANG_DISPLAY[$lang])) {
            $name = self::LANG_DISPLAY[$lang];
            $countryName = self::getFullCountryName($country);
            if ($countryName !== 'Unknown') {
                return $name . ' (' . $countryName . ')';
            }

            return $name;
        }

        $countryName = self::getFullCountryName($country);
        if ($countryName !== 'Unknown') {
            return $countryName;
        }

        return null;
    }

    private static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = preg_replace('/\(.*?\)/', ' ', $value);
        $value = preg_replace(
            '/\b(the|republic of|kingdom of|state of|federal|federation of|islamic)\b/',
            ' ',
            $value
        );
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}