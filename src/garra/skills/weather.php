<?php
/**
 * Skill: weather
 * ==============
 * Fetches current weather data from the Open-Meteo API (free, no API key needed).
 *
 * This file serves as the canonical example of how to write a GarraPHP skill.
 *
 * Every skill must define exactly two functions, prefixed with the filename:
 *
 *   {filename}_definition() → array
 *     Returns the JSON Schema that tells the LLM what this tool does and what
 *     arguments it accepts. Match the schema to OpenAI's function-calling format;
 *     Garra's driver layer translates it for Anthropic automatically.
 *
 *   {filename}_execute(array $args) → string|array
 *     Receives the decoded arguments the LLM chose to pass, runs the logic,
 *     and returns a result. Strings and arrays are both fine — Garra will
 *     JSON-encode arrays before passing them back to the LLM.
 *
 * No class, no constructor, no global state. Keep skills self-contained.
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition — tells the LLM what this skill does
// ---------------------------------------------------------------------------

function weather_definition(): array
{
    return [
        'name'        => 'weather',
        'description' => 'Get the current weather conditions (temperature, wind speed, weather code) for any city or location. Use this whenever the user asks about weather, temperature, or conditions in a place.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'location' => [
                    'type'        => 'string',
                    'description' => 'The city and country to look up, e.g. "London, UK" or "Tokyo, Japan".',
                ],
            ],
            'required' => ['location'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution — the actual skill logic
// ---------------------------------------------------------------------------

function weather_execute(array $args): array
{
    $location = trim($args['location'] ?? '');

    if ($location === '') {
        return ['error' => 'No location provided.'];
    }

    // Step 1: Geocode the location name → lat/lon using Open-Meteo's geocoding API
    $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?'
            . http_build_query(['name' => $location, 'count' => 1, 'format' => 'json']);

    $geoRaw = weather_fetch($geoUrl);
    if ($geoRaw === null) {
        return ['error' => "Could not reach the geocoding service for '{$location}'."];
    }

    $results = $geoRaw['results'] ?? [];
    if (empty($results)) {
        return ['error' => "Location '{$location}' not found. Try a more specific name."];
    }

    $place = $results[0];
    $lat   = $place['latitude'];
    $lon   = $place['longitude'];
    $name  = $place['name'] . ', ' . ($place['country'] ?? '');

    // Step 2: Fetch current weather from Open-Meteo
    $weatherUrl = 'https://api.open-meteo.com/v1/forecast?'
                . http_build_query([
                    'latitude'       => $lat,
                    'longitude'      => $lon,
                    'current'        => 'temperature_2m,weathercode,windspeed_10m',
                    'temperature_unit' => 'celsius',
                    'windspeed_unit' => 'kmh',
                    'forecast_days'  => 1,
                ]);

    $weatherRaw = weather_fetch($weatherUrl);
    if ($weatherRaw === null) {
        return ['error' => "Could not reach the weather service."];
    }

    $current = $weatherRaw['current'] ?? [];

    return [
        'location'    => $name,
        'temperature' => ($current['temperature_2m'] ?? 'N/A') . ' °C',
        'wind_speed'  => ($current['windspeed_10m'] ?? 'N/A') . ' km/h',
        'condition'   => weather_code_label($current['weathercode'] ?? -1),
    ];
}

// ---------------------------------------------------------------------------
// Internal helpers (prefixed to avoid collision with other skills)
// ---------------------------------------------------------------------------

/**
 * Minimal cURL GET helper — returns decoded JSON or null on failure.
 */
function weather_fetch(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
}

/**
 * Map WMO weather code to a human-readable label.
 * Full list: https://open-meteo.com/en/docs#weathervariables
 */
function weather_code_label(int $code): string
{
    $map = [
        0  => 'Clear sky',
        1  => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Foggy', 48 => 'Icy fog',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle',
        61 => 'Slight rain', 63 => 'Rain', 65 => 'Heavy rain',
        71 => 'Slight snow', 73 => 'Snow', 75 => 'Heavy snow',
        80 => 'Slight showers', 81 => 'Showers', 82 => 'Heavy showers',
        95 => 'Thunderstorm', 99 => 'Thunderstorm with hail',
    ];

    return $map[$code] ?? "Unknown (code {$code})";
}
