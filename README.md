# PLU-AI

Une application web pour consulter les règles d'urbanisme applicables à une parcelle et poser des questions en langage naturel sur ces règles grâce à l'IA.

## Fonctionnalités

- Recherche d'adresses ou de références cadastrales
- Affichage de la parcelle sur une carte interactive
- Consultation des règles du PLU (Plan Local d'Urbanisme)
- Chatbot IA pour poser des questions sur les règles d'urbanisme

## Architecture technique

### Frontend

- **Framework CSS** : Tailwind CSS
- **JavaScript** : 
  - Stimulus pour les comportements interactifs
  - Leaflet.js pour la carte interactive
  - Fetch API pour les appels AJAX

### Backend

- **Framework** : Symfony 7.3
- **API externes** :
  - API Adresse.data.gouv.fr pour le géocodage (simulée pour le MVP)
  - API Géoportail de l'Urbanisme pour les données PLU (simulée pour le MVP)
  - API OpenAI (ChatGPT) pour le chatbot IA (simulée pour le MVP)

## Installation

### Prérequis

- PHP 8.2 ou supérieur
- Composer
- Node.js et npm
- **poppler-utils** (recommandé pour l'extraction de texte PDF performante via `pdftotext`)
  - Sur Ubuntu/Debian : `sudo apt-get install poppler-utils`
  - Sur macOS : `brew install poppler`
- **PostgreSQL** avec l'extension **pgvector** (pour la recherche vectorielle)

### Installation des dépendances

```bash
# Installation des dépendances PHP
composer install

# Installation des dépendances JavaScript
npm install
```

### Configuration

Aucune configuration spéciale n'est nécessaire pour le MVP. Les API externes sont simulées avec des données de test.

### Lancement du serveur de développement

```bash
# Lancement du serveur Symfony
symfony server:start

# Compilation des assets (dans un autre terminal)
npm run watch
```

## Utilisation

1. Accédez à l'application dans votre navigateur (généralement http://localhost:8000)
2. Saisissez une adresse ou une référence cadastrale dans le champ de recherche
3. La carte se centre automatiquement sur la parcelle
4. Le règlement du PLU s'affiche à côté de la carte
5. Le chatbot affiche un résumé des règles principales
6. Vous pouvez poser des questions spécifiques au chatbot

## Structure du projet

### Contrôleurs

- **HomeController** : Affichage de la page d'accueil
- **SearchController** : Gestion des recherches d'adresses et parcelles via API
- **SecurityController** : Gestion de l'authentification (pour une future version)

### Services

- **GeocodingService** : Interface avec l'API de géocodage
- **PluDataService** : Récupération des données du PLU
- **ChatGptService** : Interface avec l'API ChatGPT

### Contrôleurs JavaScript (Stimulus)

- **map_controller.js** : Gestion de la carte et des recherches
- **chatbot_controller.js** : Gestion des interactions avec le chatbot
- **search_autocomplete_controller.js** : Gestion de l'autocomplétion dans le champ de recherche

## Exemples de recherche

Pour tester l'application, vous pouvez utiliser les exemples suivants :

- Adresses : "Paris", "Lyon", "Marseille"
- Références cadastrales : "AB123", "XY789"

## Exemples de questions pour le chatbot

- "Quelle est la hauteur maximale autorisée ?"
- "Quelle est l'emprise au sol maximale ?"
- "Combien de places de stationnement dois-je prévoir ?"
- "Quel est le recul minimum par rapport à l'alignement ?"
- "Puis-je construire un garage en limite de propriété ?"

## Prochaines étapes

- Intégration avec les API réelles (Adresse.data.gouv.fr, Géoportail de l'Urbanisme)
- Intégration avec l'API OpenAI pour des réponses plus précises
- Authentification des utilisateurs pour sauvegarder leurs recherches
- Comparaison entre différentes parcelles
- Génération de rapports PDF personnalisés

## Troubleshooting

### Problème d'autocomplétion

Si l'autocomplétion dans le champ de recherche ne fonctionne pas, vous pouvez essayer les solutions suivantes :

1. Exécutez le script de correction :
   ```bash
   chmod +x bin/fix_autocomplete.sh
   ./bin/fix_autocomplete.sh
   ```

2. Ou exécutez manuellement les commandes suivantes :
   ```bash
   php bin/console cache:clear
   php bin/console assets:install public
   php bin/console importmap:install
   ```

3. Rafraîchissez la page ou videz le cache de votre navigateur

### Problème de recherche et de mise à jour de la page

Si la recherche ne fonctionne pas correctement (l'API est appelée mais la page ne se met pas à jour), vous pouvez essayer les solutions suivantes :

1. Exécutez le script de correction :
   ```bash
   chmod +x bin/fix_js.sh
   ./bin/fix_js.sh
   ```

2. Ou exécutez manuellement les commandes suivantes :
   ```bash
   php bin/console cache:clear
   php bin/console assets:install public
   php bin/console importmap:install
   ```

3. Rafraîchissez la page ou videz le cache de votre navigateur

### Erreur "setting getter-only property resultsTarget"

Si vous rencontrez cette erreur dans la console, c'est parce que les propriétés "target" de Stimulus sont en lecture seule et ne peuvent pas être assignées directement. La solution est de s'assurer que le code utilise correctement les attributs data pour définir les cibles, et de laisser Stimulus gérer les références aux éléments DOM.

## Remarques

Cette application est un MVP (Minimum Viable Product) destiné à démontrer les fonctionnalités de base. Les données affichées sont simulées et ne reflètent pas nécessairement les règles d'urbanisme réelles applicables aux adresses recherchées.
