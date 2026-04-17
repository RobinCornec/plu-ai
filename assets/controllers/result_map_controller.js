import { Controller } from '@hotwired/stimulus';

/*
 * This is a Stimulus controller for the map functionality in the search results page
 *
 * Any element with a data-controller="result-map" attribute will cause
 * this controller to be executed.
 */
export default class extends Controller {
    static values = {
        latitude: Number,
        longitude: Number,
        zoom: { type: Number, default: 16 },
        geometry: Object
    };

    connect() {
        // Initialize the map when the controller connects
        this.initializeMap();
    }

    initializeMap() {
        // Check if Leaflet is available
        if (typeof L === 'undefined') {
            console.error('Leaflet is not loaded');
            return;
        }

        // Get the coordinates from the data attributes
        const lat = this.latitudeValue;
        const lng = this.longitudeValue;
        const zoom = this.zoomValue;

        // Initialize the Leaflet map
        this.map = L.map(this.element).setView([lat, lng], zoom);

        // Add the OpenStreetMap tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(this.map);

        // Add the cadastre layer (WMS from cadastre.gouv.fr)
        L.tileLayer.wms('https://www.cadastre.gouv.fr/scpc/wms', {
            layers: 'CADASTRALPARCELS',
            format: 'image/png',
            transparent: true,
            opacity: 0.5
        }).addTo(this.map);

        // Add a marker at the location
        this.marker = L.marker([lat, lng]).addTo(this.map);

        // Add the polygon for the parcel
        if (this.hasGeometryValue) {
            try {
                // Use the geometry data from the API
                const geometry = this.geometryValue;

                if (geometry.type === 'Polygon') {
                    // For Polygon type, the coordinates are in the format [[[lng, lat], [lng, lat], ...]]
                    // We need to convert them to [[lat, lng], [lat, lng], ...] for Leaflet
                    const coordinates = geometry.coordinates[0].map(coord => [coord[1], coord[0]]);

                    this.polygon = L.polygon(coordinates, {
                        color: 'blue',
                        fillColor: '#3388ff',
                        fillOpacity: 0.2,
                        weight: 2
                    }).addTo(this.map);

                    // Fit the map to the polygon bounds
                    this.map.fitBounds(this.polygon.getBounds());
                } else if (geometry.type === 'MultiPolygon') {
                    // For MultiPolygon type, the coordinates are in the format [[[[lng, lat], [lng, lat], ...]], ...]
                    // We need to convert them to [[[lat, lng], [lat, lng], ...], ...] for Leaflet
                    const polygons = geometry.coordinates.map(poly => {
                        return poly[0].map(coord => [coord[1], coord[0]]);
                    });

                    this.polygon = L.polygon(polygons, {
                        color: 'blue',
                        fillColor: '#3388ff',
                        fillOpacity: 0.2,
                        weight: 2
                    }).addTo(this.map);

                    // Fit the map to the polygon bounds
                    this.map.fitBounds(this.polygon.getBounds());
                }
            } catch (e) {
                console.error('Error displaying geometry:', e);
                this.createSimplePolygon(lat, lng);
            }
        } else {
            // If no geometry data is available, create a simple polygon
            this.createSimplePolygon(lat, lng);
        }
    }

    createSimplePolygon(lat, lng) {
        // Create a simple polygon around the marker
        const offset = 0.0005;
        const polygonCoords = [
            [lat - offset, lng - offset],
            [lat - offset, lng + offset],
            [lat + offset, lng + offset],
            [lat + offset, lng - offset]
        ];

        this.polygon = L.polygon(polygonCoords, {
            color: 'blue',
            fillColor: '#3388ff',
            fillOpacity: 0.2,
            weight: 2
        }).addTo(this.map);
    }

    disconnect() {
        // Clean up the map when the controller disconnects
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }
}
