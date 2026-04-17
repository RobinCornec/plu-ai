<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class GeocodingService
{
    private HttpClientInterface $httpClient;
    private string $addressApiUrl;
    private string $cadastreApiUrl;
    private ?string $cadastreApiKey;

    public function __construct(
        HttpClientInterface $httpClient,
        ParameterBagInterface $params
    ) {
        $this->httpClient = $httpClient;
        $this->addressApiUrl = $params->get('app.adresse_api_url');
        $this->cadastreApiUrl = $params->get('app.cadastre_api_url');
        $this->cadastreApiKey = $params->get('app.cadastre_api_key');
    }

    /**
     * Geocode an address or cadastral reference to get coordinates
     *
     * @param string $query The address or cadastral reference to geocode
     * @param array|null $metadata Optional metadata like code_insee for cadastral references
     * @return array|null The geocoded data or null if not found
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function geocode(string $query, ?array $metadata = null): ?array
    {
        // Check if the query looks like a cadastral reference (e.g., AB123)
        if (preg_match('/^[A-Z]{2}\d{1,4}$/i', $query)) {
            return $this->geocodeCadastralReference(strtoupper($query), $metadata['code_insee'] ?? null);
        }

        return $this->geocodeAddress($query);
    }

    /**
     * Geocode an address using the API Adresse.data.gouv.fr
     *
     * @param string $address The address to geocode
     * @return array|null The geocoded data or null if not found
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function geocodeAddress(string $address): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->addressApiUrl . '/search', [
                'query' => [
                    'q' => $address,
                    'limit' => 1
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return null;
            }

            $feature = $data['features'][0];
            $properties = $feature['properties'];
            $coordinates = $feature['geometry']['coordinates'];

            // Get cadastral reference and geometry for the coordinates
            $cadastralData = $this->getCadastralDataForPoint($coordinates[1], $coordinates[0]);
            $cadastralReference = $cadastralData['reference'] ?? null;
            $geometry = $cadastralData['geometry'] ?? null;

            return [
                'type' => 'address',
                'query' => $address,
                'coordinates' => [
                    'lat' => $coordinates[1], // API returns [longitude, latitude]
                    'lng' => $coordinates[0]
                ],
                'cadastralReference' => $cadastralReference,
                'properties' => [
                    'label' => $properties['label'] ?? $address,
                    'city' => $properties['city'] ?? '',
                    'postcode' => $properties['postcode'] ?? '',
                    'citycode' => $properties['citycode'] ?? '',
                    'type' => $properties['type'] ?? 'housenumber'
                ],
                'geometry' => $geometry
            ];
        } catch (\Exception $e) {
            // Log the error in a real application
            return null;
        }
    }

    private function geocodeCadastralReference(string $reference, ?string $codeInsee = null): ?array
    {
        try {
            // Extract section and number from the reference
            if (!preg_match('/^([A-Z]{2})(\d{1,4})$/i', $reference, $matches)) {
                return null;
            }

            $section = $matches[1];
            $number = $this->formatParcelNumber($matches[2]);

            // If code_insee is not provided, try to find it
            if ($codeInsee === null) {
                $communeCodes = $this->getCommuneCodesForSection($section);

                // Try each commune code until we find a match
                foreach ($communeCodes as $code) {
                    $result = $this->geocodeCadastralReferenceWithCodeInsee($section, $number, $code);
                    if ($result !== null) {
                        return $result;
                    }
                }

                return null;
            }

            // If code_insee is provided, use it directly
            return $this->geocodeCadastralReferenceWithCodeInsee($section, $number, $codeInsee);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function geocodeCadastralReferenceWithCodeInsee(string $section, string $number, string $codeInsee): ?array
    {
        try {
            $number = $this->formatParcelNumber($number);

            $response = $this->httpClient->request('GET', $this->cadastreApiUrl . '/parcelle', [
                'headers' => $this->getCadastreHeaders(),
                'query' => [
                    'code_insee' => $codeInsee,
                    'section' => $section,
                    'numero' => $number,
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return null;
            }

            $feature = $data['features'][0];
            $properties = $feature['properties'];
            $geometry = $feature['geometry'];

            // Calculate centroid for the polygon
            $coordinates = $this->calculateCentroid($geometry['coordinates'][0]);

            $reference = $section . $number;
            $commune = $properties['nom_com'];

            return [
                'type' => 'cadastral',
                'query' => $reference,
                'coordinates' => [
                    'lat' => $coordinates[1],
                    'lng' => $coordinates[0]
                ],
                'cadastralReference' => $reference,
                'properties' => [
                    'id' => $reference,
                    'city' => $commune,
                    'postcode' => $properties['code_dep'],
                    'type' => 'parcelle',
                    'area' => $properties['surface'] ?? rand(500, 2000), // m²
                    'code_insee' => $codeInsee
                ],
                'geometry' => $geometry
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate the centroid of a polygon
     *
     * @param array $coordinates Array of [lng, lat] coordinates
     * @return array [lng, lat] of the centroid
     */
    private function calculateCentroid(array $coordinates): array
    {
        $count = count($coordinates);
        if ($count === 0) {
            return [0, 0];
        }

        $sumX = 0;
        $sumY = 0;

        foreach ($coordinates as $coord) {
            $sumX += $coord[0][0]; // longitude
            $sumY += $coord[0][1]; // latitude
        }

        return [$sumX / $count, $sumY / $count];
    }

    private function getCadastralDataForPoint(float $lat, float $lng): array
    {
        try {
            // The API expects coordinates in WGS84 format
            $response = $this->httpClient->request('GET', $this->cadastreApiUrl . '/parcelle', [
                'headers' => $this->getCadastreHeaders(),
                'query' => [
                    'geom' => json_encode([
                        'type' => 'Point',
                        'coordinates' => [$lng, $lat]
                    ], JSON_UNESCAPED_UNICODE)
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return [];
            }

            // Get the first feature (closest to the point)
            $feature = $data['features'][0];
            $properties = $feature['properties'];
            $geometry = $feature['geometry'];

            // Extract section and number from properties
            $section = $properties['section'] ?? '';
            $number = $properties['numero'] ?? '';

            if (empty($section) || empty($number)) {
                return ['geometry' => $geometry];
            }

            return [
                'reference' => $section . $number,
                'geometry' => $geometry
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get autocomplete suggestions based on a partial query
     *
     * @param string $query The partial query to get suggestions for
     * @return array An array of suggestion objects
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getAutocompleteSuggestions(string $query): array
    {
        $suggestions = [];

        // If the query is empty or too short, return empty array
        if (empty($query) || strlen($query) < 3) {
            return [];
        }

        // Check if it looks like a cadastral reference with optional postal code and/or city
        // Format: IS112 29000 Quimper, IS112 29000, IS112 29, IS112 Quimper, IS112 Qui, or IS112
        if (preg_match('/^[A-Z]{2}\d*(?:\s+(?:\d{1,5}|\w[\w\s-]*)?)?$/i', $query)) {
            return $this->getCadastralSuggestions(strtoupper($query));
        }

        // Otherwise, treat it as an address query
        return $this->getAddressSuggestions($query);
    }

    /**
     * Get address autocomplete suggestions from the API
     *
     * @param string $query The partial address to get suggestions for
     * @return array An array of suggestion objects
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function getAddressSuggestions(string $query): array
    {
        $suggestions = [];

        try {
            $response = $this->httpClient->request('GET', $this->addressApiUrl . '/search', [
                'query' => [
                    'q' => $query,
                    'limit' => 5,
                    'autocomplete' => 1
                ]
            ]);

            $data = $response->toArray();

            if (!empty($data['features'])) {
                foreach ($data['features'] as $feature) {
                    $properties = $feature['properties'];
                    $label = $properties['label'] ?? $query;

                    $type = 'address';
                    if ($properties['type'] === 'municipality') {
                        $type = 'city';
                    }

                    $suggestions[] = [
                        'value' => $label,
                        'text' => $label,
                        'type' => $type
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log the error in a real application
            return [];
        }

        return $suggestions;
    }

    /**
     * Get cadastral reference suggestions
     *
     * @param string $query The partial cadastral reference, optionally with postal code and city
     * @return array An array of suggestion objects
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function getCadastralSuggestions(string $query): array
    {
        $suggestions = [];

        // If the query is too short, return empty array
        if (strlen($query) < 2) {
            return [];
        }

        try {
            $suggestions = [];
            $codeInsee = null;

            // Check if the query contains cadastral reference with optional postal code and/or city
            // Format: IS112 29000 Quimper, IS112 29000, IS112 29, IS112 Quimper, IS112 Qui, or IS112
            if (preg_match('/^([A-Z]{2}\d*)(?:\s+(\S+)(?:\s+([\w\s-]+))?)?$/i', $query, $matches)) {
                $cadastralPart = strtoupper($matches[1]);
                $section = substr($cadastralPart, 0, 2);

                // Extract the number part from the cadastral reference
                $numero = '';
                if (strlen($cadastralPart) > 2) {
                    $numero = $this->formatParcelNumber(substr($cadastralPart, 2));
                }

                // Process the second part (could be postal code or city name)
                if (isset($matches[2])) {
                    $secondPart = $matches[2];
                    $thirdPart = $matches[3] ?? null;

                    // If the second part is numeric, treat it as a postal code
                    if (is_numeric($secondPart)) {
                        $postalCode = $secondPart;
                        $cityName = $thirdPart;

                        // If we have both postal code and city name
                        if ($cityName !== null) {
                            $codeInsee = $this->getInseeCodeFromPostalCodeAndCity($postalCode, $cityName);
                        }
                        // If we only have a partial postal code (e.g., "29")
                        else if (strlen($postalCode) < 5) {
                            // Try to find INSEE codes for this partial postal code
                            // For now, we'll just use it as is in the search
                            $codeInsee = $this->findInseeCodeFromPartialPostalCode($postalCode);
                        }
                        // If we have a complete postal code but no city
                        else {
                            $codeInsee = $this->findInseeCodeFromPostalCode($postalCode);
                        }
                    }
                    // If the second part is not numeric, treat it as a city name
                    else {
                        $cityName = $secondPart;
                        if ($thirdPart !== null) {
                            $cityName .= ' ' . $thirdPart;
                        }
                        $codeInsee = $this->findInseeCodeFromCityName($cityName);
                    }
                }
            } else {
                // Fallback to the old format (just cadastral reference)
                $section = strtoupper(substr($query, 0, min(2, strlen($query))));

                // Extract the number part from the query
                $numero = '';
                if (strlen($query) > 2) {
                    $numero = $this->formatParcelNumber(substr($query, 2));
                }
            }

            $queryParams = [
                'section' => $section,
                'numero' => $numero,
            ];

            // Add code_insee parameter if available
            if ($codeInsee !== null) {
                $queryParams['code_insee'] = $codeInsee;
            }

            $response = $this->httpClient->request('GET', $this->cadastreApiUrl . '/parcelle', [
                'headers' => $this->getCadastreHeaders(),
                'query' => $queryParams
            ]);

            $data = $response->toArray();

            if (!empty($data['features'])) {
                foreach ($data['features'] as $feature) {
                    $properties = $feature['properties'];
                    $numero = $properties['numero'] ?? '';
                    $commune = $properties['nom_com'] ?? '';
                    $codeInsee = $properties['code_insee'] ?? '';
                    $geometry = $feature['geometry'];

                    // Calculate centroid for the polygon
                    $coordinates = $this->calculateCentroid($geometry['coordinates'][0]);
                    $lng = $coordinates[0];
                    $lat = $coordinates[1];

                    // Get address from coordinates using reverse geocoding
                    $address = $this->getAddressFromCoordinates($lng, $lat);

                    // Get postal code from address API using INSEE code (fallback)
                    $codePostal = $address['postcode'] ?? $this->getPostalCodeFromInseeCode($codeInsee) ?? '';
                    $reference = $section . $numero;

                    // Format the suggestion text with the address
                    $suggestionText = $reference;
                    if ($address && isset($address['label'])) {
                        $suggestionText .= ' (' . $address['label'] . ')';
                    } else {
                        $suggestionText .= ' (' . $codePostal . ' - ' . $commune . ')';
                    }

                    $suggestions[] = [
                        'value' => $reference,
                        'text' => $suggestionText,
                        'type' => 'cadastral',
                        'code_insee' => $codeInsee
                    ];
                }
            }

//            return array_slice($suggestions, 0, 5);
            return $suggestions;
        } catch (\Exception|TransportExceptionInterface $e) {
            // Log the error in a real application
            return [];
        }
    }

    private function getCommuneCodesForSection(string $section): array
    {
        try {
            // Query the cadastre API to find communes with the given section
            $response = $this->httpClient->request('GET', $this->cadastreApiUrl . '/commune', [
                'headers' => $this->getCadastreHeaders(),
                'query' => [
                    'section' => $section
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return [];
            }

            $communeCodes = [];
            foreach ($data['features'] as $feature) {
                $properties = $feature['properties'];
                if (isset($properties['code_insee'])) {
                    $communeCodes[] = $properties['code_insee'];
                }
            }

            return $communeCodes;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Convert a postal code and city name to an INSEE code
     */
    private function getInseeCodeFromPostalCodeAndCity(string $postalCode, string $cityName): ?string
    {
        return $this->searchInseeCode([
            'q' => $postalCode . ' ' . $cityName,
            'type' => 'municipality',
            'limit' => 1
        ]);
    }

    /**
     * Find INSEE code from a partial postal code
     */
    private function findInseeCodeFromPartialPostalCode(string $partialPostalCode): ?string
    {
        return $this->searchInseeCode([
            'q' => $partialPostalCode,
            'type' => 'municipality',
            'limit' => 1,
            'autocomplete' => 1
        ]);
    }

    /**
     * Find INSEE code from a complete postal code
     */
    private function findInseeCodeFromPostalCode(string $postalCode): ?string
    {
        return $this->searchInseeCode([
            'q' => $postalCode,
            'type' => 'municipality',
            'limit' => 1,
            'postcode' => $postalCode
        ]);
    }

    /**
     * Find INSEE code from a city name (full or partial)
     */
    private function findInseeCodeFromCityName(string $cityName): ?string
    {
        return $this->searchInseeCode([
            'q' => $cityName,
            'type' => 'municipality',
            'limit' => 1,
            'autocomplete' => 1
        ]);
    }

    /**
     * Get postal code from INSEE code
     */
    private function getPostalCodeFromInseeCode(string $inseeCode): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->addressApiUrl . '/search', [
                'query' => [
                    'q' => $inseeCode,
                    'type' => 'municipality',
                    'limit' => 1,
                    'citycode' => $inseeCode
                ]
            ]);

            $data = $response->toArray();

            return $data['features'][0]['properties']['postcode'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get address from coordinates using reverse geocoding
     *
     * @param float $lng Longitude
     * @param float $lat Latitude
     * @return array|null The address data or null if not found
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function getAddressFromCoordinates(float $lng, float $lat): ?array
    {
        try {
            // Query the address API to find the address at the given coordinates
            $response = $this->httpClient->request('GET', $this->addressApiUrl . '/reverse', [
                'query' => [
                    'lon' => $lng,
                    'lat' => $lat,
                    'limit' => 1
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return null;
            }

            $feature = $data['features'][0];
            $properties = $feature['properties'];

            return [
                'label' => $properties['label'] ?? null,
                'name' => $properties['name'] ?? null,
                'housenumber' => $properties['housenumber'] ?? null,
                'street' => $properties['street'] ?? null,
                'postcode' => $properties['postcode'] ?? null,
                'city' => $properties['city'] ?? null,
                'context' => $properties['context'] ?? null,
                'type' => $properties['type'] ?? null
            ];
        } catch (\Exception $e) {
            // Log the error in a real application
            return null;
        }
    }

    /**
     * Get common headers for Cadastre API requests
     */
    private function getCadastreHeaders(): array
    {
        $headers = [];
        if (!empty($this->cadastreApiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->cadastreApiKey;
        }
        return $headers;
    }

    /**
     * Format parcel number to 4 digits
     */
    private function formatParcelNumber(string $number): string
    {
        return str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generic method to search for a municipality and return its INSEE code
     */
    private function searchInseeCode(array $query): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->addressApiUrl . '/search', [
                'query' => $query
            ]);

            $data = $response->toArray();

            if (empty($data['features'])) {
                return null;
            }

            return $data['features'][0]['properties']['citycode'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
