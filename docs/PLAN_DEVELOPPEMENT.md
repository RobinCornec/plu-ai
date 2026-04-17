### Plan de Développement : Assistant IA PLU pour Organismes HLM

#### 1. Vision du Projet
L'objectif est de fournir aux organismes HLM un outil capable d'extraire instantanément des informations critiques d'urbanisme pour une parcelle donnée, malgré la complexité et le volume des documents PLU (27+ PDFs, modifications successives).

#### 2. Architecture Technique Recommandée
Pour résoudre les problèmes de performance et de mémoire rencontrés précédemment, une architecture hybride est préconisée :
*   **Frontend/Orchestration (PHP/Symfony)** : Gestion de la recherche géographique, de la carte, de la file d'attente (Messenger) et de l'interface utilisateur.
*   **Moteur d'Analyse IA (Python/FastAPI)** : Un service dédié au traitement des PDFs et au RAG (Retrieval-Augmented Generation). 
    *   *Pourquoi ?* Python possède des bibliothèques plus matures (`PyMuPDF`, `LangChain`, `LlamaIndex`) pour gérer les gros volumes de PDFs sans fuite de mémoire et avec une meilleure précision d'extraction.
*   **Base de Données (PostgreSQL + pgvector)** : Stockage des métadonnées et des index vectoriels pour une recherche sémantique rapide.

#### 3. Jalons de Développement (Milestones)

##### Étape 1 : Synthèse Structurée (Données GpU)
*Objectif : Afficher un résumé des règles sans attendre l'analyse des PDFs.*
*   Intégrer les endpoints `/api/gpu/zone-urba` et `/api/gpu/document`.
*   Extraire automatiquement : Type de zone (U, AU, N, A), libellé de la zone, et liste des prescriptions surfaciques.
*   Générer un premier résumé "IA light" basé uniquement sur ces métadonnées (très rapide).

##### Étape 2 : Pipeline de Traitement Documentaire Intelligent
*Objectif : Digérer les 27+ PDFs de manière robuste.*
*   **Classification** : Identifier les documents prioritaires (Règlement écrit) vs secondaires (Annexes).
*   **Gestion des Modifications** : Utiliser les dates de publication des documents GpU pour donner plus de poids aux "Modifications" et "Révisions" lors de la recherche vectorielle.
*   **Chunking Hiérarchique** : Ne pas découper par simple article, mais capturer la hiérarchie (TITRE > ZONE > SECTEUR > ARTICLE). Cela permet de distinguer deux "Article 10" appartenant à des secteurs différents.

##### Étape 3 : Moteur RAG & Chatbot Expert
*Objectif : Répondre à des questions précises avec un contexte localisé.*
*   **Recherche de similarité sur `pgvector`**.
*   **Filtrage par Secteur** : Utiliser le libellé précis de la zone retourné par le GpU (ex: "UHb(cd)") pour filtrer ou pondérer les résultats de la recherche vectorielle. L'IA ne consultera que les articles correspondants au secteur de la parcelle.
*   **Système de "Source Attribution"** : Chaque réponse du chatbot doit citer le PDF, la page, et le chemin hiérarchique (ex: Zone UEi > Secteur Kerjaouen).
*   **Gestion des conflits** : Instructions spécifiques pour privilégier le document le plus récent en cas de contradiction entre une modification et le règlement de base.

##### Étape 4 : Interface Métier HLM
*   Ajout de raccourcis pour les questions fréquentes (Stationnement, Espaces verts, Emprise au sol).
*   Génération de fiches de synthèse exportables en PDF.

#### 4. Stratégie de Gestion du Volume (Problématique Quimper)
Pour éviter les timeouts et la saturation :
1.  **Traitement en arrière-plan (Asynchrone)** : L'utilisateur lance l'analyse et reçoit une notification/mise à jour quand c'est prêt.
2.  **Mise en cache (Embedding Cache)** : Si un PLU a déjà été analysé pour une autre parcelle de la même zone, réutiliser l'index existant.
3.  **Filtrage Intelligent** : Ne traiter que les PDFs tagués comme "Règlement" par l'API GpU pour le chatbot prioritaire.

#### 5. Prochaines Étapes Immédiates
1.  **Validation** du passage à un micro-service Python pour la partie "Brain" ou maintien en PHP optimisé.
2.  **Implémentation de la recherche vectorielle (Étape 3)** avec prise en compte des métadonnées de zone/secteur.
3.  **Refonte du système de téléchargement** pour gérer les priorités de fichiers.
