import { Controller } from '@hotwired/stimulus';

/**
 * Search autocomplete controller
 *
 * This controller handles the autocomplete functionality for the search field.
 */
export default class extends Controller {
    static targets = ['input', 'results'];
    static values = {
        url: String,
        minLength: { type: Number, default: 2 },
        debounce: { type: Number, default: 300 } // Debounce time in ms
    };

    // Store the timeout ID for debouncing
    debounceTimeout = null;

    connect() {
        // Create a results container if it doesn't exist
        if (!this.hasResultsTarget) {
            const resultsContainer = document.createElement('div');
            resultsContainer.classList.add('search-autocomplete-results');
            resultsContainer.setAttribute('data-search-autocomplete-target', 'results');
            this.inputTarget.parentNode.appendChild(resultsContainer);
            // Don't directly assign to this.resultsTarget as it's a getter-only property
            // The element will be automatically available as this.resultsTarget after setting the data attribute
        }

        // Hide results initially
        this.hideResults();

        // Add event listeners
        this.inputTarget.addEventListener('input', this.onInput.bind(this));
        this.inputTarget.addEventListener('focus', this.onFocus.bind(this));
        document.addEventListener('click', this.onDocumentClick.bind(this));
    }

    disconnect() {
        // Remove event listeners
        this.inputTarget.removeEventListener('input', this.onInput.bind(this));
        this.inputTarget.removeEventListener('focus', this.onFocus.bind(this));
        document.removeEventListener('click', this.onDocumentClick.bind(this));
    }

    onInput(event) {
        const query = this.inputTarget.value.trim();

        if (query.length < this.minLengthValue) {
            this.hideResults();
            return;
        }

        // Clear any existing timeout
        if (this.debounceTimeout) {
            clearTimeout(this.debounceTimeout);
        }

        // Set a new timeout
        this.debounceTimeout = setTimeout(() => {
            this.fetchSuggestions(query);
        }, this.debounceValue);
    }

    onFocus(event) {
        const query = this.inputTarget.value.trim();

        if (query.length >= this.minLengthValue) {
            this.fetchSuggestions(query);
        }
    }

    onDocumentClick(event) {
        // Hide results if clicked outside the controller element
        if (!this.element.contains(event.target)) {
            this.hideResults();
        }
    }

    fetchSuggestions(query) {
        // Construct the URL with the query parameter
        const url = `${this.urlValue}?query=${encodeURIComponent(query)}`;

        // Show loading state
        this.showLoading();

        // Fetch suggestions from the server
        fetch(url)
            .then(response => response.json())
            .then(data => {
                this.displayResults(data.results);
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                this.hideResults();
            });
    }

    displayResults(results) {
        // Clear previous results
        this.resultsTarget.innerHTML = '';

        if (results.length === 0) {
            this.hideResults();
            return;
        }

        // Create a list of suggestions
        const ul = document.createElement('ul');
        ul.classList.add('search-autocomplete-list');

        results.forEach(result => {
            const li = document.createElement('li');
            li.classList.add('search-autocomplete-item');

            // Add icon based on result type
            let icon = 'map-marker-alt';
            if (result.type === 'cadastral') {
                icon = 'map';
            } else if (result.type === 'city') {
                icon = 'city';
            }

            li.innerHTML = `
                <i class="fas fa-${icon} mr-2 text-primary-500"></i>
                <span>${result.text}</span>
            `;

            // Add click event to select the suggestion
            li.addEventListener('click', () => this.selectSuggestion(result));

            ul.appendChild(li);
        });

        this.resultsTarget.appendChild(ul);
        this.showResults();
    }

    selectSuggestion(result) {
        // Set the input value to the selected suggestion
        this.inputTarget.value = result.value;

        // Hide the results
        this.hideResults();

        // Show the search results section
        const searchResults = document.getElementById('search-results');
        if (searchResults) {
            searchResults.classList.remove('hidden');

            // Show loading state
            const searchResultsFrame = document.getElementById('search-results-frame');
            if (searchResultsFrame) {
                searchResultsFrame.innerHTML = `
                    <div class="app-container py-8">
                        <div class="flex justify-center">
                            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary-500"></div>
                        </div>
                    </div>
                `;
            }

            // Fetch search results from API
            const requestBody = {
                query: result.value
            };

            // Add code_insee if available
            if (result.code_insee) {
                requestBody.code_insee = result.code_insee;
            }

            fetch('/api/search', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestBody)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Render the results directly
                    this.renderSearchResults(data);
                } else {
                    // Show error message
                    if (searchResultsFrame) {
                        searchResultsFrame.innerHTML = `
                            <div class="app-container py-8">
                                <div class="text-center">
                                    <div class="bg-neutral-100 rounded-full p-4 inline-flex items-center justify-center mb-4">
                                        <i class="fas fa-exclamation-circle text-3xl text-neutral-400"></i>
                                    </div>
                                    <h4 class="heading-4 mb-2">Erreur</h4>
                                    <p class="text-neutral-500">${data.error || 'Une erreur est survenue lors de la recherche.'}</p>
                                </div>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                // Show error message
                if (searchResultsFrame) {
                    searchResultsFrame.innerHTML = `
                        <div class="app-container py-8">
                            <div class="text-center">
                                <div class="bg-neutral-100 rounded-full p-4 inline-flex items-center justify-center mb-4">
                                    <i class="fas fa-exclamation-circle text-3xl text-neutral-400"></i>
                                </div>
                                <h4 class="heading-4 mb-2">Erreur</h4>
                                <p class="text-neutral-500">Une erreur est survenue lors de la recherche.</p>
                            </div>
                        </div>
                    `;
                }
            });
        }
    }

    renderSearchResults(data) {
        const searchResultsFrame = document.getElementById('search-results-frame');
        if (!searchResultsFrame) return;

        const query = this.inputTarget.value;
        const geocode = data.geocode;
        const summary = data.summary;
        const urbanData = data.urbanData;
        const analysis = data.analysis || { ready: false, token: null };

        searchResultsFrame.innerHTML = `
            <div class="app-container py-6 md:py-8">
                <!-- Header with search info -->
                <div class="mb-8 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="fade-in text-center md:text-left">
                        <h2 class="heading-2 text-neutral-900 mb-1">
                            Résultats pour <span class="text-primary-600">${query}</span>
                        </h2>
                        <p class="text-neutral-500 flex items-center justify-center md:justify-start">
                            <i class="fas fa-map-marker-alt mr-2 text-primary-500"></i>
                            ${geocode.coordinates.lat.toFixed(6)}, ${geocode.coordinates.lng.toFixed(6)}
                            ${geocode.cadastralReference ? ` • Réf. cadastrale : <span class="font-medium ml-1">${geocode.cadastralReference}</span>` : ''}
                        </p>
                    </div>
                    <a href="/" class="btn btn-outline hover-scale shadow-sm">
                        <i class="fas fa-search mr-2"></i>
                        Nouvelle recherche
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left Column: Map and Details (2/3) -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Map Card -->
                        <div class="card slide-up shadow-lg">
                            <div class="card-header bg-white flex items-center justify-between">
                                <h3 class="heading-4 flex items-center">
                                    <i class="fas fa-map-marked-alt mr-3 text-primary-600"></i>
                                    Localisation et Parcelles
                                </h3>
                                <div class="badge badge-primary">IGN Cadastre</div>
                            </div>
                            <div class="card-body p-0">
                                <div id="result-map" class="map-container h-80 md:h-[450px]"
                                    data-controller="result-map"
                                    data-result-map-latitude-value="${geocode.coordinates.lat}"
                                    data-result-map-longitude-value="${geocode.coordinates.lng}"
                                    data-result-map-zoom-value="17"
                                    data-result-map-geometry-value='${JSON.stringify(geocode.geometry)}'>
                                </div>
                            </div>
                        </div>

                        <!-- Urban Data Details -->
                        ${urbanData && urbanData.zones && urbanData.zones.length > 0 ? `
                        <div class="card slide-up shadow-lg delay-100">
                            <div class="card-header bg-white">
                                <h3 class="heading-4 flex items-center">
                                    <i class="fas fa-file-contract mr-3 text-primary-600"></i>
                                    Zonage et Prescriptions (Données GpU)
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h4 class="font-bold text-neutral-800 mb-2">Zones</h4>
                                        <ul class="space-y-2">
                                            ${urbanData.zones.map(z => `
                                                <li class="flex items-start">
                                                    <span class="badge badge-secondary mr-2">${z.properties.typezone || 'Zone'}</span>
                                                    <span class="text-sm">${z.properties.libelle || z.properties.destdominante || 'N/A'}</span>
                                                </li>
                                            `).join('')}
                                        </ul>
                                    </div>
                                    ${urbanData.prescriptions && urbanData.prescriptions.length > 0 ? `
                                    <div>
                                        <h4 class="font-bold text-neutral-800 mb-2">Prescriptions</h4>
                                        <ul class="space-y-2">
                                            ${urbanData.prescriptions.map(p => `
                                                <li class="flex items-start">
                                                    <i class="fas fa-info-circle text-secondary-500 mt-1 mr-2 text-xs"></i>
                                                    <span class="text-sm">${p.properties.libelle || 'Prescription'}</span>
                                                </li>
                                            `).join('')}
                                        </ul>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>

                    <!-- Right Column: AI Summary & Chatbot (1/3) -->
                    <div class="space-y-8">
                        <!-- AI Quick Summary -->
                        <div class="card slide-up shadow-lg border-l-4 border-primary-500 delay-200">
                            <div class="card-header bg-white">
                                <h3 class="heading-4 flex items-center">
                                    <i class="fas fa-magic mr-3 text-primary-600"></i>
                                    Résumé Flash IA
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="prose prose-sm text-neutral-600 italic">
                                    ${summary || "Chargement du résumé..."}
                                </div>
                            </div>
                        </div>
                        <!-- Chatbot Action -->
                        ${analysis.ready ? `
                        <!-- Chatbot is ready -->
                        <div class="card slide-up shadow-lg border-2 border-primary-100 delay-300 overflow-hidden"
                             data-controller="analysis-progress"
                             data-analysis-progress-token-value="${analysis.token}">
                            <div class="card-body p-0">
                                <!-- The analysis-progress controller will replace its content with the chat interface -->
                                <div class="p-8 text-center" data-analysis-progress-target="statusText">
                                    Chargement du chatbot...
                                </div>
                                <div class="hidden" data-analysis-progress-target="progressBar"></div>
                            </div>
                        </div>
                        ` : `
                        <!-- Chatbot needs analysis -->
                        <div class="card card-gradient slide-up shadow-lg border-2 border-primary-100 delay-300">
                            <div class="card-body text-center py-8">
                                <div class="bg-primary-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-comments text-xl text-primary-600"></i>
                                </div>
                                <h3 class="heading-4 mb-2 text-primary-800">
                                    Analyse Approfondie
                                </h3>
                                <p class="text-sm text-neutral-600 mb-6">
                                    Analyser les documents complets du PLU pour répondre à vos questions précises.
                                </p>
                                <button class="btn btn-primary w-full py-3 shadow-md hover:shadow-lg transition-all"
                                        id="btn-chat-request">
                                    <i class="fas fa-robot mr-2"></i>
                                    Lancer l'analyse
                                </button>
                                <div id="chat-request-status" class="mt-4 hidden text-sm"></div>
                            </div>
                        </div>
                        `}
                    </div>
                </div>
            </div>
        `;

        // Add event listener to the button if it exists (only in the non-ready case)
        const chatBtn = document.getElementById('btn-chat-request');
        if (chatBtn) {
            chatBtn.addEventListener('click', () => this.sendChatRequest(geocode, urbanData));
        }

        // Fit map to window if on mobile
        if (window.innerWidth < 768) {
            window.scrollTo({
                top: searchResultsFrame.offsetTop - 20,
                behavior: 'smooth'
            });
        }
    }

    sendChatRequest(geocode, urbanData) {
        const btn = document.getElementById('btn-chat-request');
        const statusDiv = document.getElementById('chat-request-status');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Envoi en cours...';

        fetch('/api/chat-request', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ geocode: geocode, urbanData: urbanData })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.already_exists) {
                    statusDiv.innerHTML = `
                        <div class="mt-6 space-y-4"
                             data-controller="analysis-progress"
                             data-analysis-progress-token-value="${data.token}">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full text-green-600 bg-green-100">
                                    Analyse déjà disponible
                                </span>
                            </div>
                            <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-green-100">
                                <div style="width:100%"
                                     class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-green-500 transition-all duration-500">
                                </div>
                            </div>
                            <p class="text-xs text-neutral-500 italic">Chargement du chatbot...</p>
                        </div>
                    `;
                    btn.classList.add('hidden');
                    return;
                }

                statusDiv.innerHTML = `
                    <div class="mt-6 space-y-4"
                         data-controller="analysis-progress"
                         data-analysis-progress-token-value="${data.token}">
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full text-primary-600 bg-primary-100"
                                  data-analysis-progress-target="statusText">
                                Initialisation...
                            </span>
                        </div>
                        <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-primary-100">
                            <div style="width:0%"
                                 class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-primary-500 transition-all duration-500"
                                 data-analysis-progress-target="progressBar">
                            </div>
                        </div>
                    </div>
                `;
                btn.classList.add('hidden');
            } else {
                statusDiv.innerHTML = `<p class="text-red-600 font-medium"><i class="fas fa-exclamation-triangle mr-1"></i> ${data.error}</p>`;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot mr-2"></i> Réessayer';
            }
            statusDiv.classList.remove('hidden');
        })
        .catch(error => {
            console.error('Error sending chat request:', error);
            statusDiv.innerHTML = '<p class="text-red-600 font-medium">Une erreur est survenue.</p>';
            statusDiv.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-robot mr-2"></i> Réessayer';
        });
    }
    showResults() {
        this.resultsTarget.classList.remove('hidden');
    }

    hideResults() {
        this.resultsTarget.classList.add('hidden');
    }

    showLoading() {
        // Add loading indicator to results container
        this.resultsTarget.innerHTML = `
            <div class="search-autocomplete-loading">
                <i class="fas fa-spinner fa-spin text-primary-500"></i>
                <span class="ml-2">Chargement...</span>
            </div>
        `;
        this.showResults();
    }
}
