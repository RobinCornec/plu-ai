# Test du changement de service cadastral

Ce document décrit comment tester manuellement le changement de service cadastral sur la carte.

## Contexte

Le service cadastral utilisé pour afficher la carte a été modifié de `https://wxs.ign.fr/parcellaire/geoportail/wms` (IGN Geoportail) à `https://www.cadastre.gouv.fr/scpc/wms` (Cadastre.gouv.fr).

## Comment tester

1. Démarrez le serveur de développement Symfony si ce n'est pas déjà fait :
   ```bash
   symfony server:start
   ```

2. Ouvrez un navigateur et accédez à l'application (généralement http://localhost:8000)

3. Effectuez une recherche avec une référence cadastrale (par exemple "AB123")

4. Vérifiez que la carte s'affiche correctement avec les éléments suivants :
   - La carte OpenStreetMap en fond
   - La couche cadastrale par-dessus (avec une opacité de 0.5)
   - Un marqueur à l'emplacement de la référence cadastrale
   - Un polygone bleu autour du marqueur

5. Vérifiez que la couche cadastrale affiche correctement les parcelles cadastrales

## Résultats attendus

- La carte doit afficher correctement les parcelles cadastrales
- Les limites des parcelles doivent être visibles
- La carte doit être réactive et permettre le zoom et le déplacement

## Problèmes connus

Si la carte ne s'affiche pas correctement, vérifiez les points suivants :

1. Ouvrez la console du navigateur (F12) et vérifiez s'il y a des erreurs JavaScript
2. Vérifiez que le service `https://www.cadastre.gouv.fr/scpc/wms` est accessible
3. Vérifiez que la couche `CADASTRALPARCELS` existe dans le service WMS

## Notes supplémentaires

Le service cadastral de cadastre.gouv.fr peut nécessiter des paramètres supplémentaires ou des ajustements selon la configuration exacte du service. Si nécessaire, ajustez les paramètres dans le fichier `assets/controllers/result_map_controller.js`.
